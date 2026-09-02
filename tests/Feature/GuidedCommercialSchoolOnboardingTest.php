<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Coupon;
use App\Models\Package;
use App\Models\PackagePrice;
use App\Models\School;
use App\Models\SchoolDomain;
use App\Models\SchoolSubscription;
use App\Models\User;
use App\Services\CommercialPricingService;
use App\Services\SchoolOnboardingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GuidedCommercialSchoolOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected Package $starterPkg;
    protected Package $standardPkg;
    protected Package $proPkg;
    protected Package $legacyPkg;
    protected Coupon $percentCoupon;
    protected Coupon $fixedCoupon;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create([
            'name'     => 'Super Admin',
            'email'    => 'superadmin@edusystem.store',
            'status'   => 'active',
            'password' => bcrypt('Password123!'),
        ]);
        $this->superAdmin->assignRole('super-admin');

        // Provision Starter (PKR 3,000/mo)
        $this->starterPkg = Package::create([
            'name'          => 'Starter',
            'slug'          => 'starter',
            'currency'      => 'PKR',
            'price_monthly' => 3000.00,
            'max_students'  => 300,
            'max_staff'     => 30,
            'storage_gb'    => 10,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackagePrice::create(['package_id' => $this->starterPkg->id, 'term_months' => 3, 'base_monthly_price' => 3000.00, 'discount_percent' => 0.00, 'total_price' => 9000.00, 'currency' => 'PKR', 'is_active' => true]);
        PackagePrice::create(['package_id' => $this->starterPkg->id, 'term_months' => 6, 'base_monthly_price' => 3000.00, 'discount_percent' => 5.00, 'total_price' => 17100.00, 'currency' => 'PKR', 'is_active' => true]);
        PackagePrice::create(['package_id' => $this->starterPkg->id, 'term_months' => 12, 'base_monthly_price' => 3000.00, 'discount_percent' => 10.00, 'total_price' => 32400.00, 'currency' => 'PKR', 'is_active' => true]);

        // Provision Standard (PKR 5,000/mo)
        $this->standardPkg = Package::create([
            'name'          => 'Standard',
            'slug'          => 'standard',
            'currency'      => 'PKR',
            'price_monthly' => 5000.00,
            'max_students'  => 800,
            'max_staff'     => 80,
            'storage_gb'    => 30,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackagePrice::create(['package_id' => $this->standardPkg->id, 'term_months' => 3, 'base_monthly_price' => 5000.00, 'discount_percent' => 0.00, 'total_price' => 15000.00, 'currency' => 'PKR', 'is_active' => true]);
        PackagePrice::create(['package_id' => $this->standardPkg->id, 'term_months' => 6, 'base_monthly_price' => 5000.00, 'discount_percent' => 5.00, 'total_price' => 28500.00, 'currency' => 'PKR', 'is_active' => true]);
        PackagePrice::create(['package_id' => $this->standardPkg->id, 'term_months' => 12, 'base_monthly_price' => 5000.00, 'discount_percent' => 10.00, 'total_price' => 54000.00, 'currency' => 'PKR', 'is_active' => true]);

        // Provision Pro (PKR 8,000/mo)
        $this->proPkg = Package::create([
            'name'          => 'Pro',
            'slug'          => 'pro',
            'currency'      => 'PKR',
            'price_monthly' => 8000.00,
            'max_students'  => 2000,
            'max_staff'     => 200,
            'storage_gb'    => 100,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackagePrice::create(['package_id' => $this->proPkg->id, 'term_months' => 3, 'base_monthly_price' => 8000.00, 'discount_percent' => 0.00, 'total_price' => 24000.00, 'currency' => 'PKR', 'is_active' => true]);
        PackagePrice::create(['package_id' => $this->proPkg->id, 'term_months' => 6, 'base_monthly_price' => 8000.00, 'discount_percent' => 5.00, 'total_price' => 45600.00, 'currency' => 'PKR', 'is_active' => true]);
        PackagePrice::create(['package_id' => $this->proPkg->id, 'term_months' => 12, 'base_monthly_price' => 8000.00, 'discount_percent' => 10.00, 'total_price' => 86400.00, 'currency' => 'PKR', 'is_active' => true]);

        // Provision Legacy Internal Package
        $this->legacyPkg = Package::create([
            'name'          => 'Legacy All Access',
            'slug'          => 'legacy-all-access',
            'currency'      => 'PKR',
            'price_monthly' => 0.00,
            'max_students'  => 0,
            'max_staff'     => 0,
            'storage_gb'    => 100,
            'is_active'     => false,
            'is_internal'   => true,
        ]);

        // Coupons
        $this->percentCoupon = Coupon::create([
            'code'        => 'SAVE10',
            'type'        => 'percent',
            'value'       => 10.00,
            'is_active'   => true,
            'description' => '10% Launch Discount',
        ]);

        $this->fixedCoupon = Coupon::create([
            'code'        => 'FLAT2000',
            'type'        => 'fixed',
            'value'       => 2000.00,
            'is_active'   => true,
            'description' => 'PKR 2000 Flat Off',
        ]);
    }

    public function test_starter_3_month_onboarding_succeeds(): void
    {
        $payload = [
            'name'                => 'Al-Hadi Grammar School',
            'code'                => 'AHGS01',
            'slug'                => 'al-hadi-grammar-school',
            'email'               => 'info@alhadi.edu.pk',
            'phone'               => '+923001234567',
            'city'                => 'Lahore',
            'state'               => 'Punjab',
            'country'             => 'PK',
            'timezone'            => 'Asia/Karachi',
            'currency'            => 'PKR',
            'language'            => 'en',
            'status'              => 'active',
            'admin_name'          => 'Muhammad Usman',
            'admin_email'         => 'admin@alhadi.edu.pk',
            'admin_phone'         => '+923001234567',
            'admin_password'      => 'SecureAdminPass123!',
            'package_id'          => $this->starterPkg->id,
            'billing_term_months' => 3,
            'start_date'          => '2026-09-01',
            'amount_paid'         => 9000.00,
            'payment_method'      => 'bank_transfer',
            'academic_year_name'  => 'Academic Year 2026-27',
            'academic_start'      => '2026-08-01',
            'academic_end'        => '2027-06-30',
            'set_academic_current'=> true,
        ];

        $response = $this->actingAs($this->superAdmin)
            ->post(route('super-admin.schools.onboard.store'), $payload);

        $response->assertRedirect(route('super-admin.schools.onboard'));
        $response->assertSessionHas('success');

        $school = School::where('slug', 'al-hadi-grammar-school')->first();
        $this->assertNotNull($school);
        $this->assertEquals('Al-Hadi Grammar School', $school->name);
        $this->assertEquals('AHGS01', $school->code);

        $admin = User::where('email', 'admin@alhadi.edu.pk')->first();
        $this->assertNotNull($admin);
        $this->assertEquals($school->id, $admin->school_id);
        $this->assertTrue($admin->hasRole('school-admin'));

        $sub = SchoolSubscription::where('school_id', $school->id)->first();
        $this->assertNotNull($sub);
        $this->assertEquals(3, $sub->billing_term_months);
        $this->assertEquals(3000.00, (float) $sub->base_monthly_price);
        $this->assertEquals(0.00, (float) $sub->discount_percent);
        $this->assertEquals(9000.00, (float) $sub->billed_amount);
        $this->assertEquals(9000.00, (float) $sub->amount_paid);
        $this->assertEquals('2026-09-01', $sub->start_date->format('Y-m-d'));
        $this->assertEquals('2026-12-01', $sub->end_date->format('Y-m-d'));

        $ay = AcademicYear::where('school_id', $school->id)->first();
        $this->assertNotNull($ay);
        $this->assertTrue($ay->is_current);
    }

    public function test_standard_6_month_onboarding_with_auto_discount(): void
    {
        $payload = [
            'name'                => 'Beacon Light School',
            'code'                => 'BLS01',
            'slug'                => 'beacon-light-school',
            'country'             => 'PK',
            'timezone'            => 'Asia/Karachi',
            'currency'            => 'PKR',
            'language'            => 'en',
            'admin_name'          => 'Fatima Tariq',
            'admin_email'         => 'fatima@beaconlight.edu.pk',
            'admin_password'      => 'Password@12345',
            'package_id'          => $this->standardPkg->id,
            'billing_term_months' => 6,
            'start_date'          => '2026-09-15',
            'amount_paid'         => 15000.00, // Partial payment
        ];

        $response = $this->actingAs($this->superAdmin)
            ->post(route('super-admin.schools.onboard.store'), $payload);

        $response->assertRedirect(route('super-admin.schools.onboard'));

        $school = School::where('slug', 'beacon-light-school')->first();
        $sub = SchoolSubscription::where('school_id', $school->id)->first();

        // 6 months @ 5,000 with 5% off = PKR 28,500
        $this->assertEquals(6, $sub->billing_term_months);
        $this->assertEquals(5.00, (float) $sub->discount_percent);
        $this->assertEquals(28500.00, (float) $sub->billed_amount);
        $this->assertEquals(15000.00, (float) $sub->amount_paid);
        $this->assertEquals('2027-03-15', $sub->end_date->format('Y-m-d'));
    }

    public function test_pro_12_month_onboarding_with_coupon_application(): void
    {
        $payload = [
            'name'                => 'City Cambridge College',
            'code'                => 'CCC01',
            'slug'                => 'city-cambridge-college',
            'country'             => 'PK',
            'timezone'            => 'Asia/Karachi',
            'currency'            => 'PKR',
            'language'            => 'en',
            'admin_name'          => 'Kamran Akmal',
            'admin_email'         => 'kamran@citycambridge.edu.pk',
            'admin_password'      => 'SecureCollegePass99!',
            'package_id'          => $this->proPkg->id,
            'billing_term_months' => 12,
            'coupon_id'           => $this->percentCoupon->id, // 10% coupon
            'start_date'          => '2026-10-01',
            'amount_paid'         => 0.00, // Unpaid
        ];

        $response = $this->actingAs($this->superAdmin)
            ->post(route('super-admin.schools.onboard.store'), $payload);

        $response->assertRedirect(route('super-admin.schools.onboard'));

        $school = School::where('slug', 'city-cambridge-college')->first();
        $sub = SchoolSubscription::where('school_id', $school->id)->first();

        // Pro 12mo base total = 86,400. 10% coupon off = 77,760
        $this->assertEquals(12, $sub->billing_term_months);
        $this->assertEquals(10.00, (float) $sub->discount_percent);
        $this->assertEquals(77760.00, (float) $sub->billed_amount);
        $this->assertEquals(0.00, (float) $sub->amount_paid);
        $this->assertEquals($this->percentCoupon->id, $sub->coupon_id);
        $this->assertEquals('2027-10-01', $sub->end_date->format('Y-m-d'));
    }

    public function test_payment_greater_than_billed_amount_is_rejected(): void
    {
        $payload = [
            'name'                => 'Overpayment Test School',
            'code'                => 'OPTS01',
            'slug'                => 'overpayment-test-school',
            'country'             => 'PK',
            'timezone'            => 'Asia/Karachi',
            'currency'            => 'PKR',
            'language'            => 'en',
            'admin_name'          => 'Tariq Mehmood',
            'admin_email'         => 'tariq@overpay.test',
            'admin_password'      => 'Password@123',
            'package_id'          => $this->starterPkg->id,
            'billing_term_months' => 3, // Billed = 9,000
            'start_date'          => '2026-09-01',
            'amount_paid'         => 12000.00, // Greater than 9,000
        ];

        $response = $this->actingAs($this->superAdmin)
            ->post(route('super-admin.schools.onboard.store'), $payload);

        $response->assertSessionHasErrors(['amount_paid']);
        $this->assertDatabaseMissing('schools', ['slug' => 'overpayment-test-school']);
    }

    public function test_unsupported_term_months_is_rejected(): void
    {
        $payload = [
            'name'                => 'One Month Test School',
            'code'                => 'OMTS01',
            'slug'                => 'one-month-test-school',
            'country'             => 'PK',
            'timezone'            => 'Asia/Karachi',
            'currency'            => 'PKR',
            'language'            => 'en',
            'admin_name'          => 'One Month Admin',
            'admin_email'         => 'admin@onemonth.test',
            'admin_password'      => 'Password@123',
            'package_id'          => $this->starterPkg->id,
            'billing_term_months' => 1, // 1 month is forbidden
            'start_date'          => '2026-09-01',
        ];

        $response = $this->actingAs($this->superAdmin)
            ->post(route('super-admin.schools.onboard.store'), $payload);

        $response->assertSessionHasErrors(['billing_term_months']);
        $this->assertDatabaseMissing('schools', ['slug' => 'one-month-test-school']);
    }

    public function test_internal_legacy_package_is_rejected(): void
    {
        $payload = [
            'name'                => 'Legacy Attempt School',
            'code'                => 'LAS01',
            'slug'                => 'legacy-attempt-school',
            'country'             => 'PK',
            'timezone'            => 'Asia/Karachi',
            'currency'            => 'PKR',
            'language'            => 'en',
            'admin_name'          => 'Legacy Admin',
            'admin_email'         => 'legacy@attempt.test',
            'admin_password'      => 'Password@123',
            'package_id'          => $this->legacyPkg->id,
            'billing_term_months' => 3,
            'start_date'          => '2026-09-01',
        ];

        $response = $this->actingAs($this->superAdmin)
            ->post(route('super-admin.schools.onboard.store'), $payload);

        $response->assertSessionHasErrors(['package_id']);
        $this->assertDatabaseMissing('schools', ['slug' => 'legacy-attempt-school']);
    }

    public function test_inactive_package_is_rejected(): void
    {
        $inactivePkg = Package::create([
            'name'          => 'Archived Tier',
            'slug'          => 'archived-tier',
            'currency'      => 'PKR',
            'price_monthly' => 2000.00,
            'max_students'  => 100,
            'max_staff'     => 10,
            'storage_gb'    => 5,
            'is_active'     => false,
            'is_internal'   => false,
        ]);

        $payload = [
            'name'                => 'Inactive Tier School',
            'code'                => 'ITS01',
            'slug'                => 'inactive-tier-school',
            'country'             => 'PK',
            'timezone'            => 'Asia/Karachi',
            'currency'            => 'PKR',
            'language'            => 'en',
            'admin_name'          => 'Inactive Admin',
            'admin_email'         => 'inactive@tier.test',
            'admin_password'      => 'Password@123',
            'package_id'          => $inactivePkg->id,
            'billing_term_months' => 3,
            'start_date'          => '2026-09-01',
        ];

        $response = $this->actingAs($this->superAdmin)
            ->post(route('super-admin.schools.onboard.store'), $payload);

        $response->assertSessionHasErrors(['package_id']);
        $this->assertDatabaseMissing('schools', ['slug' => 'inactive-tier-school']);
    }

    public function test_custom_domain_is_created_in_pending_state_with_verification_token(): void
    {
        $payload = [
            'name'                => 'Future Leaders Academy',
            'code'                => 'FLA01',
            'slug'                => 'future-leaders-academy',
            'country'             => 'PK',
            'timezone'            => 'Asia/Karachi',
            'currency'            => 'PKR',
            'language'            => 'en',
            'admin_name'          => 'Zubair Shah',
            'admin_email'         => 'zubair@futureleaders.edu.pk',
            'admin_password'      => 'FuturePass@123',
            'package_id'          => $this->standardPkg->id,
            'billing_term_months' => 6,
            'start_date'          => '2026-09-01',
            'custom_domain'       => 'app.futureleaders.edu.pk',
        ];

        $response = $this->actingAs($this->superAdmin)
            ->post(route('super-admin.schools.onboard.store'), $payload);

        $response->assertRedirect(route('super-admin.schools.onboard'));

        $school = School::where('slug', 'future-leaders-academy')->first();
        $domain = SchoolDomain::where('school_id', $school->id)->where('hostname', 'app.futureleaders.edu.pk')->first();

        $this->assertNotNull($domain);
        $this->assertEquals(SchoolDomain::STATUS_PENDING, $domain->status);
        $this->assertEquals(SchoolDomain::SSL_PENDING, $domain->ssl_status);
        $this->assertFalse($domain->is_primary);
        $this->assertNotNull($domain->verification_token);
        $this->assertStringStartsWith('stx_', $domain->verification_token);
    }

    public function test_duplicate_school_identity_or_admin_email_is_rejected(): void
    {
        // First creation
        $school = School::create([
            'name'     => 'Existing Elite School',
            'code'     => 'EES01',
            'slug'     => 'existing-elite-school',
            'country'  => 'PK',
            'timezone' => 'Asia/Karachi',
            'currency' => 'PKR',
            'language' => 'en',
            'status'   => 'active',
        ]);
        User::create([
            'school_id' => $school->id,
            'name'      => 'Existing Admin',
            'email'     => 'existingadmin@eliteschool.edu.pk',
            'password'  => bcrypt('Pass123!'),
            'status'    => 'active',
        ]);

        // Try duplicate name
        $payload1 = [
            'name'                => 'Existing Elite School',
            'code'                => 'NEWCODE01',
            'slug'                => 'unique-slug-1',
            'country'             => 'PK',
            'timezone'            => 'Asia/Karachi',
            'currency'            => 'PKR',
            'language'            => 'en',
            'admin_name'          => 'New Admin',
            'admin_email'         => 'newadmin1@test.com',
            'admin_password'      => 'Password@123',
            'package_id'          => $this->starterPkg->id,
            'billing_term_months' => 3,
            'start_date'          => '2026-09-01',
        ];
        $res1 = $this->actingAs($this->superAdmin)->post(route('super-admin.schools.onboard.store'), $payload1);
        $res1->assertSessionHasErrors(['name']);

        // Try duplicate slug
        $payload2 = [
            'name'                => 'Brand New School',
            'code'                => 'BNS01',
            'slug'                => 'existing-elite-school', // Duplicate slug
            'country'             => 'PK',
            'timezone'            => 'Asia/Karachi',
            'currency'            => 'PKR',
            'language'            => 'en',
            'admin_name'          => 'New Admin',
            'admin_email'         => 'newadmin2@test.com',
            'admin_password'      => 'Password@123',
            'package_id'          => $this->starterPkg->id,
            'billing_term_months' => 3,
            'start_date'          => '2026-09-01',
        ];
        $res2 = $this->actingAs($this->superAdmin)->post(route('super-admin.schools.onboard.store'), $payload2);
        $res2->assertSessionHasErrors(['slug']);

        // Try duplicate admin email
        $payload3 = [
            'name'                => 'Unique School Three',
            'code'                => 'UST01',
            'slug'                => 'unique-school-three',
            'country'             => 'PK',
            'timezone'            => 'Asia/Karachi',
            'currency'            => 'PKR',
            'language'            => 'en',
            'admin_name'          => 'New Admin',
            'admin_email'         => 'existingadmin@eliteschool.edu.pk', // Duplicate email
            'admin_password'      => 'Password@123',
            'package_id'          => $this->starterPkg->id,
            'billing_term_months' => 3,
            'start_date'          => '2026-09-01',
        ];
        $res3 = $this->actingAs($this->superAdmin)->post(route('super-admin.schools.onboard.store'), $payload3);
        $res3->assertSessionHasErrors(['admin_email']);
    }

    public function test_transaction_rolls_back_atomically_on_unexpected_failure(): void
    {
        $payload = [
            'name'                => 'Rollback Test School',
            'code'                => 'RTS01',
            'slug'                => 'rollback-test-school',
            'country'             => 'PK',
            'timezone'            => 'Asia/Karachi',
            'currency'            => 'PKR',
            'language'            => 'en',
            'admin_name'          => 'Rollback Admin',
            'admin_email'         => 'admin@rollback.test',
            'admin_password'      => 'Password@123',
            'package_id'          => $this->starterPkg->id,
            'billing_term_months' => 3,
            'start_date'          => '2026-09-01',
            'custom_domain'       => 'admin.edusystem.store', // Reserved hostname triggers domain exception
        ];

        $response = $this->actingAs($this->superAdmin)
            ->post(route('super-admin.schools.onboard.store'), $payload);

        // Entire transaction must be clean; zero orphan schools or users
        $this->assertDatabaseMissing('schools', ['slug' => 'rollback-test-school']);
        $this->assertDatabaseMissing('users', ['email' => 'admin@rollback.test']);
        $this->assertDatabaseMissing('school_subscriptions', ['notes' => 'Commercial guided onboarding']);
    }

    public function test_tenant_audit_privacy_and_lahore_cambridge_integrity(): void
    {
        // Setup mock Lahore Cambridge School
        $lcs = School::create([
            'name'     => 'Lahore Cambridge School',
            'code'     => 'LCS01',
            'slug'     => 'lahore-cambridge-school',
            'country'  => 'PK',
            'timezone' => 'Asia/Karachi',
            'currency' => 'PKR',
            'language' => 'en',
            'status'   => 'active',
        ]);
        $lcsAdmin = User::create([
            'school_id' => $lcs->id,
            'name'      => 'LCS Admin',
            'email'     => 'admin@lahorecambridge.com',
            'password'  => bcrypt('Secret123!'),
            'status'    => 'active',
        ]);
        $lcsSub = SchoolSubscription::create([
            'school_id'           => $lcs->id,
            'package_id'          => $this->legacyPkg->id,
            'billing_term_months' => 12,
            'base_monthly_price'  => 0.00,
            'discount_percent'    => 0.00,
            'billed_amount'       => 0.00,
            'amount_paid'         => 0.00,
            'start_date'          => '2026-09-01',
            'end_date'            => '2027-09-01',
            'status'              => 'active',
        ]);

        // Onboard a new school
        $payload = [
            'name'                => 'New Model High School',
            'code'                => 'NMHS01',
            'slug'                => 'new-model-high-school',
            'country'             => 'PK',
            'timezone'            => 'Asia/Karachi',
            'currency'            => 'PKR',
            'language'            => 'en',
            'admin_name'          => 'Shahid Afridi',
            'admin_email'         => 'shahid@newmodel.edu.pk',
            'admin_password'      => 'Password@123',
            'package_id'          => $this->standardPkg->id,
            'billing_term_months' => 6,
            'start_date'          => '2026-09-01',
        ];

        $this->actingAs($this->superAdmin)->post(route('super-admin.schools.onboard.store'), $payload);

        // Verify Lahore Cambridge School is 100% untouched
        $lcsFresh = $lcs->fresh();
        $this->assertEquals('Lahore Cambridge School', $lcsFresh->name);
        $this->assertEquals($this->legacyPkg->id, $lcsSub->fresh()->package_id);
    }
}
