<?php

namespace Tests\Feature;

use App\DTOs\EntitlementResult;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolModule;
use App\Models\SchoolSubscription;
use App\Models\User;
use App\Services\SchoolEntitlementResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SchoolEntitlementResolverTest extends TestCase
{
    use RefreshDatabase;

    protected SchoolEntitlementResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(SchoolEntitlementResolver::class);
    }

    protected function createSchool(string $name = 'School One', string $status = 'active'): School
    {
        return School::create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name . '-' . uniqid()),
            'email' => strtolower(str_replace(' ', '', $name)) . uniqid() . '@example.com',
            'status' => $status,
        ]);
    }

    public function test_active_subscription_with_package_module_is_entitled(): void
    {
        $school = $this->createSchool('School Active');
        $package = Package::create([
            'name' => 'Standard Tier',
            'slug' => 'standard-tier',
            'price_monthly' => 30,
            'price_yearly' => 300,
            'max_students' => 100,
            'max_staff' => 20,
            'storage_gb' => 10,
            'is_active' => true,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'fees']);

        SchoolSubscription::create([
            'school_id' => $school->id,
            'package_id' => $package->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
            'status' => 'active',
            'amount_paid' => 30,
        ]);

        $result = $this->resolver->checkModule($school, 'fees');

        $this->assertTrue($result->isEntitled);
        $this->assertEquals(EntitlementResult::ALLOWED, $result->reason);
        $this->assertEquals($school->id, $result->schoolId);
        $this->assertEquals($package->id, $result->packageId);
    }

    public function test_active_subscription_with_missing_package_module_is_denied(): void
    {
        $school = $this->createSchool('School Missing Mod');
        $package = Package::create([
            'name' => 'Basic Tier',
            'slug' => 'basic-tier',
            'price_monthly' => 10,
            'price_yearly' => 100,
            'max_students' => 50,
            'max_staff' => 10,
            'storage_gb' => 5,
            'is_active' => true,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'students']);

        SchoolSubscription::create([
            'school_id' => $school->id,
            'package_id' => $package->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
            'status' => 'active',
            'amount_paid' => 10,
        ]);

        $result = $this->resolver->checkModule($school, 'library');

        $this->assertFalse($result->isEntitled);
        $this->assertEquals(EntitlementResult::MODULE_NOT_IN_PACKAGE, $result->reason);
    }

    public function test_active_subscription_with_explicit_enable_override_is_entitled(): void
    {
        $school = $this->createSchool('School Override Enable');
        $package = Package::create([
            'name' => 'Basic Tier',
            'slug' => 'basic-tier-2',
            'price_monthly' => 10,
            'price_yearly' => 100,
            'max_students' => 50,
            'max_staff' => 10,
            'storage_gb' => 5,
            'is_active' => true,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'students']);

        SchoolSubscription::create([
            'school_id' => $school->id,
            'package_id' => $package->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
            'status' => 'active',
            'amount_paid' => 10,
        ]);

        // Explicit enable override for library
        SchoolModule::create([
            'school_id' => $school->id,
            'module_slug' => 'library',
            'is_enabled' => true,
        ]);

        $result = $this->resolver->checkModule($school, 'library');

        $this->assertTrue($result->isEntitled);
        $this->assertEquals(EntitlementResult::MODULE_ENABLED_BY_OVERRIDE, $result->reason);
    }

    public function test_active_subscription_with_explicit_disable_override_is_denied(): void
    {
        $school = $this->createSchool('School Override Disable');
        $package = Package::create([
            'name' => 'Full Tier',
            'slug' => 'full-tier',
            'price_monthly' => 50,
            'price_yearly' => 500,
            'max_students' => 200,
            'max_staff' => 50,
            'storage_gb' => 20,
            'is_active' => true,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'hostel']);

        SchoolSubscription::create([
            'school_id' => $school->id,
            'package_id' => $package->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
            'status' => 'active',
            'amount_paid' => 50,
        ]);

        // Explicit disable override for hostel
        SchoolModule::create([
            'school_id' => $school->id,
            'module_slug' => 'hostel',
            'is_enabled' => false,
        ]);

        $result = $this->resolver->checkModule($school, 'hostel');

        $this->assertFalse($result->isEntitled);
        $this->assertEquals(EntitlementResult::MODULE_DISABLED_BY_OVERRIDE, $result->reason);
    }

    public function test_school_with_no_subscription_is_denied_even_with_enable_override(): void
    {
        $school = $this->createSchool('School No Sub Override');

        // Explicit enable override with NO valid subscription
        SchoolModule::create([
            'school_id' => $school->id,
            'module_slug' => 'fees',
            'is_enabled' => true,
        ]);

        $result = $this->resolver->checkModule($school, 'fees');

        // CRITICAL: Must be denied because subscription is absent
        $this->assertFalse($result->isEntitled);
        $this->assertEquals(EntitlementResult::NO_ACTIVE_SUBSCRIPTION, $result->reason);
    }

    public function test_expired_active_status_subscription_is_dynamically_denied(): void
    {
        $school = $this->createSchool('School Expired Sub');
        $package = Package::create([
            'name' => 'Tier A',
            'slug' => 'tier-a',
            'price_monthly' => 20,
            'price_yearly' => 200,
            'max_students' => 100,
            'max_staff' => 20,
            'storage_gb' => 10,
            'is_active' => true,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'transport']);

        // DB row says 'active' but end_date is in the past!
        SchoolSubscription::create([
            'school_id' => $school->id,
            'package_id' => $package->id,
            'start_date' => Carbon::now()->subDays(60),
            'end_date' => Carbon::now()->subDays(5),
            'status' => 'active',
            'amount_paid' => 20,
        ]);

        $result = $this->resolver->checkModule($school, 'transport');

        $this->assertFalse($result->isEntitled);
        $this->assertEquals(EntitlementResult::SUBSCRIPTION_EXPIRED, $result->reason);
    }

    public function test_suspended_subscription_is_denied(): void
    {
        $school = $this->createSchool('School Suspended Sub');
        $package = Package::create([
            'name' => 'Tier B',
            'slug' => 'tier-b',
            'price_monthly' => 20,
            'price_yearly' => 200,
            'max_students' => 100,
            'max_staff' => 20,
            'storage_gb' => 10,
            'is_active' => true,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'inventory']);

        SchoolSubscription::create([
            'school_id' => $school->id,
            'package_id' => $package->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
            'status' => 'suspended',
            'amount_paid' => 20,
        ]);

        $result = $this->resolver->checkModule($school, 'inventory');

        $this->assertFalse($result->isEntitled);
        $this->assertEquals(EntitlementResult::SUBSCRIPTION_SUSPENDED, $result->reason);
    }

    public function test_valid_trial_subscription_is_entitled(): void
    {
        $school = $this->createSchool('School Valid Trial');
        $package = Package::create([
            'name' => 'Trial Tier',
            'slug' => 'trial-tier',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'max_students' => 100,
            'max_staff' => 20,
            'storage_gb' => 10,
            'is_active' => true,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'exams']);

        SchoolSubscription::create([
            'school_id' => $school->id,
            'package_id' => $package->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::now()->addDays(14),
            'status' => 'trial',
            'is_trial' => true,
            'trial_ends_at' => Carbon::now()->addDays(14),
            'amount_paid' => 0,
        ]);

        $result = $this->resolver->checkModule($school, 'exams');

        $this->assertTrue($result->isEntitled);
        $this->assertEquals(EntitlementResult::ALLOWED, $result->reason);
    }

    public function test_expired_trial_subscription_is_denied(): void
    {
        $school = $this->createSchool('School Expired Trial');
        $package = Package::create([
            'name' => 'Trial Tier 2',
            'slug' => 'trial-tier-2',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'max_students' => 100,
            'max_staff' => 20,
            'storage_gb' => 10,
            'is_active' => true,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'exams']);

        SchoolSubscription::create([
            'school_id' => $school->id,
            'package_id' => $package->id,
            'start_date' => Carbon::now()->subDays(20),
            'end_date' => Carbon::now()->subDays(2),
            'status' => 'trial',
            'is_trial' => true,
            'trial_ends_at' => Carbon::now()->subDays(2),
            'amount_paid' => 0,
        ]);

        $result = $this->resolver->checkModule($school, 'exams');

        $this->assertFalse($result->isEntitled);
        $this->assertEquals(EntitlementResult::TRIAL_EXPIRED, $result->reason);
    }

    public function test_multiple_simultaneously_valid_subscriptions_produces_ambiguous_result(): void
    {
        $school = $this->createSchool('School Ambiguous Subs');
        $packageA = Package::create([
            'name' => 'Package A',
            'slug' => 'package-a',
            'price_monthly' => 20,
            'price_yearly' => 200,
            'max_students' => 50,
            'max_staff' => 10,
            'storage_gb' => 5,
            'is_active' => true,
        ]);
        $packageB = Package::create([
            'name' => 'Package B',
            'slug' => 'package-b',
            'price_monthly' => 40,
            'price_yearly' => 400,
            'max_students' => 100,
            'max_staff' => 20,
            'storage_gb' => 10,
            'is_active' => true,
        ]);

        PackageModule::create(['package_id' => $packageA->id, 'module_slug' => 'fees']);
        PackageModule::create(['package_id' => $packageB->id, 'module_slug' => 'fees']);

        // Two active overlapping subscriptions
        SchoolSubscription::create([
            'school_id' => $school->id,
            'package_id' => $packageA->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
            'status' => 'active',
            'amount_paid' => 20,
        ]);
        SchoolSubscription::create([
            'school_id' => $school->id,
            'package_id' => $packageB->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
            'status' => 'active',
            'amount_paid' => 40,
        ]);

        $result = $this->resolver->checkModule($school, 'fees');

        // Must NOT arbitrarily choose or merge packages; must flag ambiguous subscriptions
        $this->assertFalse($result->isEntitled);
        $this->assertEquals(EntitlementResult::AMBIGUOUS_ACTIVE_SUBSCRIPTIONS, $result->reason);
        $this->assertNull($this->resolver->resolveSubscription($school));
    }

    public function test_super_admin_bypasses_entitlement_globally(): void
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $school = $this->createSchool('School SuperAdmin Bypass');
        // No subscription or package for school

        $result = $this->resolver->canAccessModule($superAdmin, $school, 'library');

        $this->assertTrue($result->isEntitled);
        $this->assertEquals(EntitlementResult::SUPER_ADMIN_BYPASS, $result->reason);
    }

    public function test_school_a_entitlement_never_affects_school_b(): void
    {
        $schoolA = $this->createSchool('School Tenant A');
        $schoolB = $this->createSchool('School Tenant B');

        $package = Package::create([
            'name' => 'Package Single',
            'slug' => 'package-single',
            'price_monthly' => 20,
            'price_yearly' => 200,
            'max_students' => 50,
            'max_staff' => 10,
            'storage_gb' => 5,
            'is_active' => true,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'hostel']);

        // School A has subscription
        SchoolSubscription::create([
            'school_id' => $schoolA->id,
            'package_id' => $package->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
            'status' => 'active',
            'amount_paid' => 20,
        ]);

        // School B has NO subscription
        $this->assertTrue($this->resolver->isModuleEnabled($schoolA, 'hostel'));
        $this->assertFalse($this->resolver->isModuleEnabled($schoolB, 'hostel'));
    }

    public function test_core_features_are_unconditionally_accessible(): void
    {
        $school = $this->createSchool('School Core Features');
        // No subscriptions

        $result = $this->resolver->checkModule($school, 'dashboard');
        $this->assertTrue($result->isEntitled);
        $this->assertEquals(EntitlementResult::CORE_FEATURE, $result->reason);

        $resultSettings = $this->resolver->checkModule($school, 'settings');
        $this->assertTrue($resultSettings->isEntitled);
        $this->assertEquals(EntitlementResult::CORE_FEATURE, $resultSettings->reason);
    }

    public function test_get_effective_modules_returns_correct_canonical_map(): void
    {
        $school = $this->createSchool('School Effective Map');
        $package = Package::create([
            'name' => 'Selective Tier',
            'slug' => 'selective-tier',
            'price_monthly' => 20,
            'price_yearly' => 200,
            'max_students' => 50,
            'max_staff' => 10,
            'storage_gb' => 5,
            'is_active' => true,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'students']);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'staff']);

        SchoolSubscription::create([
            'school_id' => $school->id,
            'package_id' => $package->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
            'status' => 'active',
            'amount_paid' => 20,
        ]);

        // Override: enable library, disable staff
        SchoolModule::create(['school_id' => $school->id, 'module_slug' => 'library', 'is_enabled' => true]);
        SchoolModule::create(['school_id' => $school->id, 'module_slug' => 'staff', 'is_enabled' => false]);

        $effective = $this->resolver->getEffectiveModules($school);

        $this->assertCount(14, $effective);
        $this->assertTrue($effective['students']);  // from package
        $this->assertFalse($effective['staff']);     // disabled by override
        $this->assertTrue($effective['library']);   // enabled by override
        $this->assertFalse($effective['hostel']);    // not in package
    }
}
