<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolModule;
use App\Models\SchoolSubscription;
use App\Models\User;
use App\Services\LegacyEntitlementProvisioner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EntitlementAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
    }

    protected function createSchool(string $name = 'Audit School'): School
    {
        return School::create([
            'name'   => $name,
            'slug'   => \Illuminate\Support\Str::slug($name . '-' . uniqid()),
            'email'  => strtolower(str_replace(' ', '', $name)) . uniqid() . '@example.com',
            'status' => 'active',
        ]);
    }

    public function test_audit_command_without_target_fails_safe(): void
    {
        $exitCode = Artisan::call('entitlement:audit');
        $output   = Artisan::output();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('No target specified. Use --school=<id> to audit a single school or --all to audit all schools.', $output);
    }

    public function test_audit_command_all_performs_zero_writes(): void
    {
        $school1 = $this->createSchool('Audit Target 1');
        $school2 = $this->createSchool('Audit Target 2');

        $initialSubs = SchoolSubscription::count();
        $initialPkgs = Package::count();
        $initialMods = SchoolModule::count();

        $exitCode = Artisan::call('entitlement:audit', ['--all' => true]);
        $output   = Artisan::output();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('=== READ-ONLY TENANT ENTITLEMENT AUDIT', $output);
        $this->assertStringContainsString('Audit completed with zero database writes.', $output);

        $this->assertEquals($initialSubs, SchoolSubscription::count());
        $this->assertEquals($initialPkgs, Package::count());
        $this->assertEquals($initialMods, SchoolModule::count());
    }

    public function test_audit_command_reports_no_subscription_school_as_blocked(): void
    {
        $school = $this->createSchool('School No Sub');

        $exitCode = Artisan::call('entitlement:audit', ['--school' => (string) $school->id]);
        $output   = Artisan::output();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('NO_ACTIVE_SUBSCRIPTION', $output);
        $this->assertStringContainsString('BLOCKED (403)', $output);
        $this->assertStringContainsString('0/14', $output);
    }

    public function test_audit_command_reports_all_access_school_as_allowed(): void
    {
        $school = $this->createSchool('School All Access');
        app(LegacyEntitlementProvisioner::class)->provisionSchool($school);

        $exitCode = Artisan::call('entitlement:audit', ['--school' => (string) $school->id]);
        $output   = Artisan::output();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('ALLOWED (All Access)', $output);
        $this->assertStringContainsString('14/14', $output);
    }

    public function test_audit_command_reports_partial_package_school(): void
    {
        $school = $this->createSchool('School Partial Tier');

        $pkg = Package::create([
            'name'          => 'Trio Plan',
            'slug'          => 'trio-plan',
            'price_monthly' => 15,
            'price_yearly'  => 150,
            'max_students'  => 50,
            'max_staff'     => 10,
            'storage_gb'    => 5,
            'is_active'     => true,
        ]);
        PackageModule::create(['package_id' => $pkg->id, 'module_slug' => 'students']);
        PackageModule::create(['package_id' => $pkg->id, 'module_slug' => 'fees']);

        SchoolSubscription::create([
            'school_id'   => $school->id,
            'package_id'  => $pkg->id,
            'start_date'  => Carbon::yesterday(),
            'end_date'    => Carbon::tomorrow(),
            'status'      => 'active',
            'amount_paid' => 15,
        ]);

        $exitCode = Artisan::call('entitlement:audit', ['--school' => (string) $school->id]);
        $output   = Artisan::output();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('PARTIAL (2/14 Modules)', $output);
        $this->assertStringContainsString('2/14', $output);
    }

    public function test_inertia_entitlement_props_contain_only_approved_non_sensitive_fields(): void
    {
        $school = $this->createSchool('Inertia Test School');
        $pkg = \App\Models\Package::firstOrCreate(
            ['slug' => 'test-all-access-pkg'],
            [
                'name'          => 'Test All Access',
                'currency'      => 'PKR',
                'price_monthly' => 100,
                'is_active'     => true,
                'is_internal'   => false,
            ]
        );
        \App\Models\SchoolSubscription::create([
            'school_id'   => $school->id,
            'package_id'  => $pkg->id,
            'start_date'  => now()->subDay(),
            'end_date'    => now()->addYear(),
            'status'      => 'active',
            'amount_paid' => 100,
        ]);
        $user = User::factory()->create(['school_id' => $school->id]);
        $user->assignRole('school-admin');

        $response = $this->actingAs($user)->get('/school/reports/dashboard');
        $response->assertStatus(200);

        $pageProps = $response->viewData('page')['props'] ?? [];
        $this->assertArrayHasKey('entitlement', $pageProps);

        $entitlement = $pageProps['entitlement'];
        $this->assertNotNull($entitlement);
        $this->assertArrayHasKey('mode', $entitlement);
        $this->assertArrayHasKey('subscription_active', $entitlement);
        $this->assertArrayHasKey('effective_modules', $entitlement);

        // Ensure sensitive payment/token internals are NOT leaked in Inertia props
        $this->assertArrayNotHasKey('amount_paid', $entitlement);
        $this->assertArrayNotHasKey('payment_method', $entitlement);
        $this->assertArrayNotHasKey('stripe_id', $entitlement);
    }
}
