<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Package;
use App\Models\PackagePrice;
use App\Models\School;
use App\Models\SchoolSubscription;
use App\Models\User;
use App\Services\CommercialPackageProvisioner;
use App\Services\CommercialPricingService;
use App\Services\LegacyEntitlementProvisioner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommercialPackageBillingTest extends TestCase
{
    use RefreshDatabase;

    protected CommercialPricingService $pricingService;
    protected CommercialPackageProvisioner $provisioner;
    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingService = app(CommercialPricingService::class);
        $this->provisioner    = app(CommercialPackageProvisioner::class);

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super-admin');
    }

    public function test_commercial_pricing_service_calculates_exact_canonical_terms_for_starter(): void
    {
        $baseMonthly = 3000.00;

        // 3 Months (0% discount)
        $term3 = $this->pricingService->calculateTermPrice($baseMonthly, 3);
        $this->assertEquals(3, $term3['term_months']);
        $this->assertEquals(0.00, $term3['discount_percent']);
        $this->assertEquals(9000.00, $term3['subtotal']);
        $this->assertEquals(0.00, $term3['discount_amount']);
        $this->assertEquals(9000.00, $term3['total_price']);
        $this->assertEquals(0.00, $term3['savings_amount']);
        $this->assertEquals(3000.00, $term3['effective_monthly_price']);

        // 6 Months (5% discount, save 900)
        $term6 = $this->pricingService->calculateTermPrice($baseMonthly, 6);
        $this->assertEquals(6, $term6['term_months']);
        $this->assertEquals(5.00, $term6['discount_percent']);
        $this->assertEquals(18000.00, $term6['subtotal']);
        $this->assertEquals(900.00, $term6['discount_amount']);
        $this->assertEquals(17100.00, $term6['total_price']);
        $this->assertEquals(900.00, $term6['savings_amount']);
        $this->assertEquals(2850.00, $term6['effective_monthly_price']);

        // 12 Months (10% discount, save 3600)
        $term12 = $this->pricingService->calculateTermPrice($baseMonthly, 12);
        $this->assertEquals(12, $term12['term_months']);
        $this->assertEquals(10.00, $term12['discount_percent']);
        $this->assertEquals(36000.00, $term12['subtotal']);
        $this->assertEquals(3600.00, $term12['discount_amount']);
        $this->assertEquals(32400.00, $term12['total_price']);
        $this->assertEquals(3600.00, $term12['savings_amount']);
        $this->assertEquals(2700.00, $term12['effective_monthly_price']);
    }

    public function test_commercial_pricing_service_calculates_exact_canonical_terms_for_standard(): void
    {
        $baseMonthly = 5000.00;

        // 3 Months
        $term3 = $this->pricingService->calculateTermPrice($baseMonthly, 3);
        $this->assertEquals(15000.00, $term3['total_price']);
        $this->assertEquals(0.00, $term3['savings_amount']);
        $this->assertEquals(5000.00, $term3['effective_monthly_price']);

        // 6 Months (Save 1500)
        $term6 = $this->pricingService->calculateTermPrice($baseMonthly, 6);
        $this->assertEquals(28500.00, $term6['total_price']);
        $this->assertEquals(1500.00, $term6['savings_amount']);
        $this->assertEquals(4750.00, $term6['effective_monthly_price']);

        // 12 Months (Save 6000)
        $term12 = $this->pricingService->calculateTermPrice($baseMonthly, 12);
        $this->assertEquals(54000.00, $term12['total_price']);
        $this->assertEquals(6000.00, $term12['savings_amount']);
        $this->assertEquals(4500.00, $term12['effective_monthly_price']);
    }

    public function test_commercial_pricing_service_calculates_exact_canonical_terms_for_pro(): void
    {
        $baseMonthly = 8000.00;

        // 3 Months
        $term3 = $this->pricingService->calculateTermPrice($baseMonthly, 3);
        $this->assertEquals(24000.00, $term3['total_price']);
        $this->assertEquals(0.00, $term3['savings_amount']);
        $this->assertEquals(8000.00, $term3['effective_monthly_price']);

        // 6 Months (Save 2400)
        $term6 = $this->pricingService->calculateTermPrice($baseMonthly, 6);
        $this->assertEquals(45600.00, $term6['total_price']);
        $this->assertEquals(2400.00, $term6['savings_amount']);
        $this->assertEquals(7600.00, $term6['effective_monthly_price']);

        // 12 Months (Save 9600)
        $term12 = $this->pricingService->calculateTermPrice($baseMonthly, 12);
        $this->assertEquals(86400.00, $term12['total_price']);
        $this->assertEquals(9600.00, $term12['savings_amount']);
        $this->assertEquals(7200.00, $term12['effective_monthly_price']);
    }

    public function test_rejection_of_unsupported_term_months(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pricingService->calculateTermPrice(5000.00, 1); // 1 month is rejected
    }

    public function test_legacy_subscription_migration_leaves_commercial_snapshot_null(): void
    {
        $legacyProvisioner = app(LegacyEntitlementProvisioner::class);
        $legacyPkg = $legacyProvisioner->getOrCreateLegacyPackage();
        $school = School::create(['name' => 'Lahore Cambridge School', 'code' => 'LCS001', 'is_active' => true]);

        // Create legacy subscription without any snapshot columns
        $sub = SchoolSubscription::create([
            'school_id'     => $school->id,
            'package_id'    => $legacyPkg->id,
            'start_date'    => '2026-01-01',
            'end_date'      => '2027-01-01',
            'status'        => 'active',
            'amount_paid'   => 0.00,
        ]);

        $fresh = $sub->fresh();
        $this->assertNull($fresh->billing_term_months);
        $this->assertNull($fresh->base_monthly_price);
        $this->assertNull($fresh->discount_percent);
        $this->assertNull($fresh->billed_amount);
        $this->assertNull($fresh->currency);
        $this->assertNull($fresh->package_price_id);
    }

    public function test_provisioner_creates_three_commercial_packages_with_accurate_modules_and_pricing(): void
    {
        $packages = $this->provisioner->provisionAll();

        $this->assertCount(3, $packages);

        // Starter
        $starter = $packages['starter'];
        $this->assertEquals('Starter', $starter->name);
        $this->assertEquals('starter', $starter->slug);
        $this->assertEquals('PKR', $starter->currency);
        $this->assertFalse($starter->is_internal);
        $this->assertEquals(300, $starter->max_students);
        $this->assertEquals(25, $starter->max_staff);
        $this->assertEquals(5, $starter->storage_gb);
        $this->assertTrue($starter->is_active);
        $this->assertCount(9, $starter->modules);

        $expectedStarterModules = ['students', 'staff', 'attendance', 'timetable', 'exams', 'fees', 'homework', 'communication', 'reports'];
        sort($expectedStarterModules);
        $actualStarterModules = $starter->modules->pluck('module_slug')->sort()->values()->toArray();
        $this->assertEquals($expectedStarterModules, $actualStarterModules);

        // Standard
        $standard = $packages['standard'];
        $this->assertEquals('Standard', $standard->name);
        $this->assertEquals('standard', $standard->slug);
        $this->assertEquals('Most Popular', $standard->badge);
        $this->assertFalse($standard->is_internal);
        $this->assertEquals(800, $standard->max_students);
        $this->assertEquals(75, $standard->max_staff);
        $this->assertEquals(20, $standard->storage_gb);
        $this->assertTrue($standard->is_active);
        $this->assertCount(13, $standard->modules);
        $this->assertFalse($standard->modules->pluck('module_slug')->contains('hostel'));

        // Pro
        $pro = $packages['pro'];
        $this->assertEquals('Pro', $pro->name);
        $this->assertEquals('pro', $pro->slug);
        $this->assertFalse($pro->is_internal);
        $this->assertEquals(2000, $pro->max_students);
        $this->assertEquals(200, $pro->max_staff);
        $this->assertEquals(50, $pro->storage_gb);
        $this->assertTrue($pro->is_active);
        $this->assertCount(14, $pro->modules);
        $this->assertTrue($pro->modules->pluck('module_slug')->contains('hostel'));
    }

    public function test_legacy_all_access_remains_internal_and_protected(): void
    {
        $legacyProvisioner = app(LegacyEntitlementProvisioner::class);
        $legacyPkg = $legacyProvisioner->getOrCreateLegacyPackage();

        $this->assertEquals('Legacy All Access', $legacyPkg->name);
        $this->assertEquals('legacy-all-access', $legacyPkg->slug);
        $this->assertTrue($legacyPkg->is_internal);
        $this->assertFalse($legacyPkg->is_active);
        $this->assertCount(14, $legacyPkg->modules);

        // Attempt to update through PackageController update endpoint must be blocked
        $response = $this->actingAs($this->superAdmin)->put(route('super-admin.packages.update', $legacyPkg), [
            'name'         => 'Hacked Legacy',
            'max_students' => 100,
            'max_staff'    => 10,
            'storage_gb'   => 5,
        ]);
        $response->assertSessionHas('error');

        // Attempt to delete through PackageController destroy endpoint must be blocked
        $delResponse = $this->actingAs($this->superAdmin)->delete(route('super-admin.packages.destroy', $legacyPkg));
        $delResponse->assertSessionHas('error');
    }

    public function test_server_authoritative_end_date_for_multi_terms(): void
    {
        $this->provisioner->provisionAll();
        $starter = Package::where('slug', 'starter')->first();
        $school = School::create(['name' => 'Army Public School', 'code' => 'APS001', 'is_active' => true]);

        // Test 3 months
        $this->actingAs($this->superAdmin)->post(route('super-admin.subscriptions.store'), [
            'school_id'           => $school->id,
            'package_id'          => $starter->id,
            'billing_term_months' => 3,
            'start_date'          => '2026-10-01',
            'end_date'            => '2029-01-01', // Client tamper attempt
            'status'              => 'active',
            'amount_paid'         => 0,
        ]);
        $sub3 = SchoolSubscription::where('school_id', $school->id)->first();
        $this->assertEquals('2027-01-01', Carbon::parse($sub3->end_date)->toDateString());

        // Test 6 months
        $school6 = School::create(['name' => 'DPS Model Town', 'code' => 'DPS002', 'is_active' => true]);
        $this->actingAs($this->superAdmin)->post(route('super-admin.subscriptions.store'), [
            'school_id'           => $school6->id,
            'package_id'          => $starter->id,
            'billing_term_months' => 6,
            'start_date'          => '2026-10-01',
            'status'              => 'active',
        ]);
        $sub6 = SchoolSubscription::where('school_id', $school6->id)->first();
        $this->assertEquals('2027-04-01', Carbon::parse($sub6->end_date)->toDateString());

        // Test 12 months
        $school12 = School::create(['name' => 'Convent of Jesus and Mary', 'code' => 'CJM003', 'is_active' => true]);
        $this->actingAs($this->superAdmin)->post(route('super-admin.subscriptions.store'), [
            'school_id'           => $school12->id,
            'package_id'          => $starter->id,
            'billing_term_months' => 12,
            'start_date'          => '2026-10-01',
            'status'              => 'active',
        ]);
        $sub12 = SchoolSubscription::where('school_id', $school12->id)->first();
        $this->assertEquals('2027-10-01', Carbon::parse($sub12->end_date)->toDateString());
    }

    public function test_billed_amount_vs_amount_paid_separation(): void
    {
        $this->provisioner->provisionAll();
        $standard = Package::where('slug', 'standard')->first();
        $school = School::create(['name' => 'Roots Millennium', 'code' => 'RMS001', 'is_active' => true]);

        // Unpaid subscription: amount_paid = 0
        $this->actingAs($this->superAdmin)->post(route('super-admin.subscriptions.store'), [
            'school_id'           => $school->id,
            'package_id'          => $standard->id,
            'billing_term_months' => 6,
            'start_date'          => '2026-10-01',
            'status'              => 'active',
            'amount_paid'         => 0,
        ]);
        $sub = SchoolSubscription::where('school_id', $school->id)->first();
        $this->assertEquals(28500.00, (float) $sub->billed_amount);
        $this->assertEquals(0.00, (float) $sub->amount_paid);

        // Partial payment update: customer paid PKR 15,000
        $this->actingAs($this->superAdmin)->put(route('super-admin.subscriptions.update', $sub), [
            'package_id'          => $standard->id,
            'billing_term_months' => 6,
            'start_date'          => '2026-10-01',
            'status'              => 'active',
            'amount_paid'         => 15000.00,
        ]);
        $freshSub = $sub->fresh();
        $this->assertEquals(28500.00, (float) $freshSub->billed_amount);
        $this->assertEquals(15000.00, (float) $freshSub->amount_paid);

        // Full payment update: customer paid PKR 28,500
        $this->actingAs($this->superAdmin)->put(route('super-admin.subscriptions.update', $sub), [
            'package_id'          => $standard->id,
            'billing_term_months' => 6,
            'start_date'          => '2026-10-01',
            'status'              => 'active',
            'amount_paid'         => 28500.00,
        ]);
        $paidSub = $sub->fresh();
        $this->assertEquals(28500.00, (float) $paidSub->billed_amount);
        $this->assertEquals(28500.00, (float) $paidSub->amount_paid);
    }

    public function test_term_discount_remains_separate_from_promotional_coupon(): void
    {
        $this->provisioner->provisionAll();
        $standard = Package::where('slug', 'standard')->first();
        $school = School::create(['name' => 'KGS Karachi', 'code' => 'KGS001', 'is_active' => true]);

        // Create a 10% promotional coupon
        $coupon = Coupon::create([
            'code'      => 'PROMO10',
            'type'      => 'percent',
            'value'     => 10.00,
            'is_active' => true,
        ]);

        // Standard 6mo: base = 5000, term discount = 5%, term total = 28,500.
        // With 10% coupon: payable billed_amount = 28,500 - 2,850 = 25,650.
        $this->actingAs($this->superAdmin)->post(route('super-admin.subscriptions.store'), [
            'school_id'           => $school->id,
            'package_id'          => $standard->id,
            'billing_term_months' => 6,
            'coupon_id'           => $coupon->id,
            'start_date'          => '2026-10-01',
            'status'              => 'active',
            'amount_paid'         => 25650.00,
        ]);

        $sub = SchoolSubscription::where('school_id', $school->id)->first();
        $this->assertEquals(6, $sub->billing_term_months);
        $this->assertEquals(5000.00, (float) $sub->base_monthly_price);
        $this->assertEquals(5.00, (float) $sub->discount_percent); // Term discount preserved!
        $this->assertEquals(25650.00, (float) $sub->billed_amount); // Coupon applied to billed amount
        $this->assertEquals($coupon->id, $sub->coupon_id);
    }

    public function test_rejection_of_invalid_or_tampered_package_pricing(): void
    {
        $this->provisioner->provisionAll();
        $starter = Package::where('slug', 'starter')->first();
        $school = School::create(['name' => 'The City School', 'code' => 'TCS001', 'is_active' => true]);

        // 1 month invalid term
        $response = $this->actingAs($this->superAdmin)->post(route('super-admin.subscriptions.store'), [
            'school_id'           => $school->id,
            'package_id'          => $starter->id,
            'billing_term_months' => 1,
            'start_date'          => '2026-10-01',
            'status'              => 'active',
        ]);
        $response->assertSessionHasErrors('billing_term_months');

        // Deactivated price term attempt
        $p3 = PackagePrice::where('package_id', $starter->id)->where('term_months', 3)->first();
        $p3->update(['is_active' => false]);

        $responseDeactivated = $this->actingAs($this->superAdmin)->post(route('super-admin.subscriptions.store'), [
            'school_id'           => $school->id,
            'package_id'          => $starter->id,
            'billing_term_months' => 3,
            'start_date'          => '2026-10-01',
            'status'              => 'active',
        ]);
        $responseDeactivated->assertSessionHasErrors('billing_term_months');
    }

    public function test_provision_and_deprovision_cli_commands(): void
    {
        // 1. Dry run provision command (zero writes)
        $this->artisan('packages:provision-commercial --dry-run')
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertEquals(0, Package::whereIn('slug', ['starter', 'standard', 'pro'])->count());

        // 2. Execute provision command
        $this->artisan('packages:provision-commercial --execute')
            ->expectsOutputToContain('Commercial packages provisioned successfully.')
            ->assertSuccessful();

        $this->assertEquals(3, Package::whereIn('slug', ['starter', 'standard', 'pro'])->count());

        // 3. Dry run deprovision command (zero deletions)
        $this->artisan('packages:deprovision-commercial --dry-run')
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        $this->assertEquals(3, Package::whereIn('slug', ['starter', 'standard', 'pro'])->count());

        // 4. Deprovision refusal when subscription exists
        $pro = Package::where('slug', 'pro')->first();
        $school = School::create(['name' => 'LGS Paragon', 'code' => 'LGS001', 'is_active' => true]);
        SchoolSubscription::create([
            'school_id'           => $school->id,
            'package_id'          => $pro->id,
            'billing_term_months' => 3,
            'start_date'          => '2026-10-01',
            'end_date'            => '2027-01-01',
            'status'              => 'active',
            'amount_paid'         => 24000.00,
        ]);

        $this->artisan('packages:deprovision-commercial --execute')
            ->assertFailed();

        // 5. Deprovision execution succeeds after subscription removed
        SchoolSubscription::where('school_id', $school->id)->forceDelete();
        $this->artisan('packages:deprovision-commercial --execute')
            ->expectsOutputToContain('Commercial packages deprovisioned successfully.')
            ->assertSuccessful();

        $this->assertEquals(0, Package::whereIn('slug', ['starter', 'standard', 'pro'])->count());
    }

    public function test_migration_backfills_pre_existing_legacy_all_access_package(): void
    {
        // Simulate pre-existing legacy package before migration backfill
        $legacy = Package::create([
            'name'          => 'Legacy All Access',
            'slug'          => 'legacy-all-access',
            'description'   => 'Existing tier',
            'price_monthly' => 0.00,
            'price_yearly'  => 0.00,
            'max_students'  => 0,
            'max_staff'     => 0,
            'storage_gb'    => 100,
            'is_active'     => true,
            'is_internal'   => false,
        ]);

        $originalId = $legacy->id;

        // Run the backfill update as executed in migration 2026_09_02_224001
        \Illuminate\Support\Facades\DB::table('packages')
            ->where('slug', 'legacy-all-access')
            ->update([
                'is_internal' => true,
                'is_active'   => false,
            ]);

        $fresh = $legacy->fresh();
        $this->assertEquals($originalId, $fresh->id);
        $this->assertTrue($fresh->is_internal);
        $this->assertFalse($fresh->is_active);
    }

    public function test_provision_command_strictly_blocks_overwriting_subscribed_commercial_package_with_no_bypass(): void
    {
        // 1. Initial provision succeeds
        $this->artisan('packages:provision-commercial --execute')->assertSuccessful();

        $starter = Package::where('slug', 'starter')->first();
        $school = School::create(['name' => 'KGS Clifton', 'code' => 'KGS002', 'is_active' => true]);

        // 2. Attach a subscription to Starter
        SchoolSubscription::create([
            'school_id'           => $school->id,
            'package_id'          => $starter->id,
            'billing_term_months' => 3,
            'start_date'          => '2026-10-01',
            'end_date'            => '2027-01-01',
            'status'              => 'active',
            'amount_paid'         => 9000.00,
        ]);

        // 3. Attempting to rerun provision must strictly fail and refuse overwrite
        $this->artisan('packages:provision-commercial --execute')
            ->expectsOutputToContain("Package 'Starter' (slug: starter) has active or historic subscriptions")
            ->assertFailed();

        // 4. Verify Starter modules and prices were not destroyed or altered
        $freshStarter = $starter->fresh(['modules', 'prices']);
        $this->assertCount(9, $freshStarter->modules);
        $this->assertCount(3, $freshStarter->prices);
    }
}