<?php

namespace Tests\Feature;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingService = app(CommercialPricingService::class);
        $this->provisioner    = app(CommercialPackageProvisioner::class);

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
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

    public function test_provisioner_creates_three_commercial_packages_with_accurate_modules_and_pricing(): void
    {
        $packages = $this->provisioner->provisionAll();

        $this->assertCount(3, $packages);

        // Starter
        $starter = $packages['starter'];
        $this->assertEquals('Starter', $starter->name);
        $this->assertEquals('starter', $starter->slug);
        $this->assertEquals('PKR', $starter->currency);
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
        $this->assertEquals(2000, $pro->max_students);
        $this->assertEquals(200, $pro->max_staff);
        $this->assertEquals(50, $pro->storage_gb);
        $this->assertTrue($pro->is_active);
        $this->assertCount(14, $pro->modules);
        $this->assertTrue($pro->modules->pluck('module_slug')->contains('hostel'));
    }

    public function test_legacy_all_access_remains_intact_and_inactive_for_public_sale(): void
    {
        $legacyProvisioner = app(LegacyEntitlementProvisioner::class);
        $legacyPkg = $legacyProvisioner->getOrCreateLegacyPackage();

        $this->assertEquals('Legacy All Access', $legacyPkg->name);
        $this->assertEquals('legacy-all-access', $legacyPkg->slug);
        $this->assertFalse($legacyPkg->is_active);
        $this->assertCount(14, $legacyPkg->modules);

        // Provision commercial packages
        $this->provisioner->provisionAll();

        // Check legacy package is still intact and inactive
        $freshLegacy = Package::where('slug', 'legacy-all-access')->first();
        $this->assertNotNull($freshLegacy);
        $this->assertFalse($freshLegacy->is_active);
        $this->assertCount(14, $freshLegacy->modules);
    }

    public function test_subscription_commercial_snapshot_persists_and_is_immutable_to_future_package_price_changes(): void
    {
        $this->provisioner->provisionAll();
        $standard = Package::where('slug', 'standard')->first();
        $school = School::create(['name' => 'City Grammar School', 'code' => 'CGS001', 'is_active' => true]);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        // Assign 6-month subscription to school
        $response = $this->actingAs($superAdmin)->post(route('super-admin.subscriptions.store'), [
            'school_id'           => $school->id,
            'package_id'          => $standard->id,
            'billing_term_months' => 6,
            'start_date'          => Carbon::today()->toDateString(),
            'end_date'            => Carbon::today()->addMonths(6)->toDateString(),
            'status'              => 'active',
            'amount_paid'         => 28500.00,
        ]);

        $response->assertSessionHasNoErrors();

        $sub = SchoolSubscription::where('school_id', $school->id)->first();
        $this->assertNotNull($sub);
        $this->assertEquals(6, $sub->billing_term_months);
        $this->assertEquals(5000.00, (float) $sub->base_monthly_price);
        $this->assertEquals(5.00, (float) $sub->discount_percent);
        $this->assertEquals(28500.00, (float) $sub->billed_amount);
        $this->assertEquals(28500.00, (float) $sub->amount_paid);
        $this->assertEquals('PKR', $sub->currency);

        // Price change on Package in the future: Standard increases to PKR 6,000/mo
        $this->pricingService->syncPackagePrices($standard, 6000.00, 'PKR');

        // Verify that existing subscription snapshot remains completely unchanged!
        $freshSub = $sub->fresh();
        $this->assertEquals(6, $freshSub->billing_term_months);
        $this->assertEquals(5000.00, (float) $freshSub->base_monthly_price);
        $this->assertEquals(5.00, (float) $freshSub->discount_percent);
        $this->assertEquals(28500.00, (float) $freshSub->billed_amount);
        $this->assertEquals(28500.00, (float) $freshSub->amount_paid);
    }

    public function test_super_admin_subscription_validation_rejects_invalid_billing_terms(): void
    {
        $this->provisioner->provisionAll();
        $starter = Package::where('slug', 'starter')->first();
        $school = School::create(['name' => 'Beacon Academy', 'code' => 'BA001', 'is_active' => true]);
        
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        // 1 month is not an allowed commitment
        $response = $this->actingAs($superAdmin)->post(route('super-admin.subscriptions.store'), [
            'school_id'           => $school->id,
            'package_id'          => $starter->id,
            'billing_term_months' => 1,
            'start_date'          => Carbon::today()->toDateString(),
            'end_date'            => Carbon::today()->addMonth()->toDateString(),
            'status'              => 'active',
            'amount_paid'         => 3000.00,
        ]);

        $response->assertSessionHasErrors('billing_term_months');
    }
}