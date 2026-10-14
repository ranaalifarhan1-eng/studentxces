<?php

namespace Tests\Feature\SchoolAdmin;

use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeChallan;
use App\Models\FeePayment;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SchoolAdminDashboardFinancialMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $schoolB;
    protected User $adminUser;
    protected User $accountantUser;
    protected AcademicYear $academicYear;
    protected SchoolClass $class;
    protected Section $section;
    protected Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $pView = Permission::firstOrCreate(['name' => 'fees.view', 'guard_name' => 'web']);
        $pCollect = Permission::firstOrCreate(['name' => 'fees.collect', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([$pView, $pCollect]);

        $accRole = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $accRole->syncPermissions([$pView, $pCollect]);

        $this->school = School::create([
            'name'   => 'Test Alpha Academy',
            'slug'   => 'alpha-acad-' . uniqid(),
            'email'  => 'alpha-' . uniqid() . '@alpha.test',
            'status' => 'active',
        ]);

        $this->schoolB = School::create([
            'name'   => 'Test Beta Academy',
            'slug'   => 'beta-acad-' . uniqid(),
            'email'  => 'beta-' . uniqid() . '@beta.test',
            'status' => 'active',
        ]);

        $package = Package::create([
            'name'          => 'Test Plan',
            'slug'          => 'test-plan-' . uniqid(),
            'price_monthly' => 30,
            'price_yearly'  => 300,
            'max_students'  => 500,
            'max_staff'     => 50,
            'storage_gb'    => 20,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'fees']);

        SchoolSubscription::create([
            'school_id'   => $this->school->id,
            'package_id'  => $package->id,
            'status'      => 'active',
            'start_date'  => Carbon::now()->subDays(10),
            'end_date'    => Carbon::now()->addDays(30),
        ]);

        SchoolSubscription::create([
            'school_id'   => $this->schoolB->id,
            'package_id'  => $package->id,
            'status'      => 'active',
            'start_date'  => Carbon::now()->subDays(10),
            'end_date'    => Carbon::now()->addDays(30),
        ]);

        $this->adminUser = User::create([
            'name'              => 'Admin User',
            'email'             => 'admin-' . uniqid() . '@alpha.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->accountantUser = User::create([
            'name'              => 'Accountant User',
            'email'             => 'acc-' . uniqid() . '@alpha.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->accountantUser->assignRole('accountant');

        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => '2026-2027',
            'start_date' => Carbon::now()->startOfYear()->toDateString(),
            'end_date'   => Carbon::now()->endOfYear()->toDateString(),
            'is_current' => true,
        ]);

        $this->class = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Grade 1',
            'numeric_name' => 1,
        ]);

        $this->section = Section::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->class->id,
            'name'      => 'A',
        ]);

        $this->student = Student::create([
            'school_id'     => $this->school->id,
            'class_id'      => $this->class->id,
            'section_id'    => $this->section->id,
            'admission_no'  => 'ADM-1001',
            'first_name'    => 'Student',
            'last_name'     => 'One',
            'gender'        => 'male',
            'date_of_birth' => '2015-01-01',
            'status'        => 'active',
        ]);
    }

    public function test_admin_dashboard_returns_canonical_current_month_collections_and_outstanding(): void
    {
        // 1. Modern partial challan in current month: 12000 total payable, 2000 paid
        $challan1 = FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Student One',
            'admission_no'       => 'ADM-1001',
            'class_name'         => 'Grade 1',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-001',
            'issue_date'         => now()->startOfMonth()->toDateString(),
            'due_date'           => now()->addDays(10)->toDateString(),
            'gross_amount'       => '12000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '12000.00',
            'paid_amount'        => '2000.00',
            'status'             => 'partial',
            'billing_period_key' => now()->format('Y-m'),
        ]);

        // FeePayment record for the 2000 payment
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $challan1->id,
            'receipt_no'       => 'RCP-001',
            'amount_due'       => '12000.00',
            'amount_paid'      => '2000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '10000.00',
            'payment_date'     => now()->toDateString(),
            'month_year'       => now()->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // 2. Modern unpaid challan: 5000 total payable, 0 paid
        FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Student One',
            'admission_no'       => 'ADM-1001',
            'class_name'         => 'Grade 1',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-002',
            'issue_date'         => now()->startOfMonth()->toDateString(),
            'due_date'           => now()->addDays(10)->toDateString(),
            'gross_amount'       => '5000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '5000.00',
            'paid_amount'        => '0.00',
            'status'             => 'unpaid',
            'billing_period_key' => now()->format('Y-m'),
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('school.reports.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Dashboard')
            ->where('monthFees', fn ($val) => (float) $val === 2000.0)
            ->where('pendingFees', fn ($val) => (float) $val === 15000.0) // 10000 remaining on CH-001 + 5000 on CH-002
        );
    }

    public function test_admin_dashboard_fee_chart_returns_last_6_months_series(): void
    {
        $now = Carbon::now();
        $lastMonth = $now->copy()->subMonth();

        // Payment in current month: 1500
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'receipt_no'       => 'RCP-M0',
            'amount_due'       => '1500.00',
            'amount_paid'      => '1500.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => $now->toDateString(),
            'month_year'       => $now->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // Payment in previous month: 3500
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'receipt_no'       => 'RCP-M1',
            'amount_due'       => '3500.00',
            'amount_paid'      => '3500.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => $lastMonth->toDateString(),
            'month_year'       => $lastMonth->format('Y-m'),
            'method'           => 'bank_transfer',
            'status'           => 'paid',
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('school.reports.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Dashboard')
            ->has('feeChart', 6)
            ->where('feeChart.5.amount', fn ($val) => (float) $val === 1500.0)
            ->where('feeChart.4.amount', fn ($val) => (float) $val === 3500.0)
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_accountant_dashboard_returns_today_collection_and_month_fees(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
        $today = Carbon::now('UTC');
        $earlierThisMonth = Carbon::parse('2026-10-01', 'UTC');

        // Today's payment: 1200
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'receipt_no'       => 'RCP-T1',
            'amount_due'       => '1200.00',
            'amount_paid'      => '1200.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => $today->toDateString(),
            'month_year'       => $today->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // Earlier this month payment: 2800
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'receipt_no'       => 'RCP-T2',
            'amount_due'       => '2800.00',
            'amount_paid'      => '2800.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => $earlierThisMonth->toDateString(),
            'month_year'       => $today->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        $response = $this->actingAs($this->accountantUser)->get(route('school.reports.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Dashboard')
            ->where('todayCollection', fn ($val) => (float) $val === 1200.0)
            ->where('monthFees', fn ($val) => (float) $val === 4000.0)
        );

        Carbon::setTestNow(null);
    }

    public function test_school_timezone_boundary_today_and_current_month_resolution(): void
    {
        // School Tokyo (UTC+9)
        $schoolTokyo = School::create([
            'name'     => 'Tokyo Academy',
            'slug'     => 'tokyo-acad-' . uniqid(),
            'email'    => 'tokyo-' . uniqid() . '@tokyo.test',
            'timezone' => 'Asia/Tokyo',
            'status'   => 'active',
        ]);

        // School New York (America/New_York, EDT is UTC-4 in October)
        $schoolNewYork = School::create([
            'name'     => 'New York Academy',
            'slug'     => 'ny-acad-' . uniqid(),
            'email'    => 'ny-' . uniqid() . '@ny.test',
            'timezone' => 'America/New_York',
            'status'   => 'active',
        ]);

        // Student for Tokyo
        $classT = SchoolClass::create(['school_id' => $schoolTokyo->id, 'name' => 'T1', 'numeric_name' => 1]);
        $studentT = Student::create([
            'school_id'     => $schoolTokyo->id,
            'class_id'      => $classT->id,
            'admission_no'  => 'ADM-TK-1',
            'first_name'    => 'Tokyo',
            'last_name'     => 'Student',
            'gender'        => 'male',
            'date_of_birth' => '2015-01-01',
            'status'        => 'active',
        ]);

        // Student for New York
        $classNY = SchoolClass::create(['school_id' => $schoolNewYork->id, 'name' => 'NY1', 'numeric_name' => 1]);
        $studentNY = Student::create([
            'school_id'     => $schoolNewYork->id,
            'class_id'      => $classNY->id,
            'admission_no'  => 'ADM-NY-1',
            'first_name'    => 'NY',
            'last_name'     => 'Student',
            'gender'        => 'female',
            'date_of_birth' => '2015-01-01',
            'status'        => 'active',
        ]);

        // Fix instant in UTC: 2026-10-31 20:30:00 UTC
        // In Asia/Tokyo: 2026-11-01 05:30:00 (November 1, 2026)
        // In America/New_York: 2026-10-31 16:30:00 (October 31, 2026)
        Carbon::setTestNow(Carbon::parse('2026-10-31 20:30:00', 'UTC'));

        // Tokyo payment on 2026-11-01 (Tokyo's today & this month): 5000
        FeePayment::create([
            'school_id'        => $schoolTokyo->id,
            'student_id'       => $studentT->id,
            'receipt_no'       => 'RCP-TK-01',
            'amount_due'       => '5000.00',
            'amount_paid'      => '5000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => '2026-11-01',
            'month_year'       => '2026-11',
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // Tokyo payment on 2026-10-31 (Tokyo's yesterday & last month): 2000
        FeePayment::create([
            'school_id'        => $schoolTokyo->id,
            'student_id'       => $studentT->id,
            'receipt_no'       => 'RCP-TK-02',
            'amount_due'       => '2000.00',
            'amount_paid'      => '2000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => '2026-10-31',
            'month_year'       => '2026-10',
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // New York payment on 2026-10-31 (NY's today & this month): 7000
        FeePayment::create([
            'school_id'        => $schoolNewYork->id,
            'student_id'       => $studentNY->id,
            'receipt_no'       => 'RCP-NY-01',
            'amount_due'       => '7000.00',
            'amount_paid'      => '7000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => '2026-10-31',
            'month_year'       => '2026-10',
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // New York payment on 2026-11-01 (NY's tomorrow & next month): 3000
        FeePayment::create([
            'school_id'        => $schoolNewYork->id,
            'student_id'       => $studentNY->id,
            'receipt_no'       => 'RCP-NY-02',
            'amount_due'       => '3000.00',
            'amount_paid'      => '3000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => '2026-11-01',
            'month_year'       => '2026-11',
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        $service = app(\App\Services\FeeFinancialSummaryService::class);

        // Verify Tokyo: Today = 2026-11-01, Current Month = 2026-11
        $this->assertSame('Asia/Tokyo', $service->resolveSchoolTimezone($schoolTokyo->id));
        $this->assertSame('2026-11-01', $service->getSchoolNow($schoolTokyo->id)->toDateString());
        $this->assertSame(500000, $service->todayCollectedCents($schoolTokyo->id));
        $this->assertSame(500000, $service->currentMonthCollectedCents($schoolTokyo->id));

        // Verify New York: Today = 2026-10-31, Current Month = 2026-10
        $this->assertSame('America/New_York', $service->resolveSchoolTimezone($schoolNewYork->id));
        $this->assertSame('2026-10-31', $service->getSchoolNow($schoolNewYork->id)->toDateString());
        $this->assertSame(700000, $service->todayCollectedCents($schoolNewYork->id));
        $this->assertSame(700000, $service->currentMonthCollectedCents($schoolNewYork->id));

        Carbon::setTestNow(null);
    }

    public function test_invalid_and_fallback_timezone_graceful_handling(): void
    {
        $service = app(\App\Services\FeeFinancialSummaryService::class);

        // Empty string timezone falls back to app.timezone or UTC
        $schoolEmpty = School::create([
            'name'     => 'Empty TZ Academy',
            'slug'     => 'empty-tz-' . uniqid(),
            'email'    => 'empty-' . uniqid() . '@empty.test',
            'timezone' => '',
            'status'   => 'active',
        ]);
        $this->assertSame(config('app.timezone') ?: 'UTC', $service->resolveSchoolTimezone($schoolEmpty->id));

        // Invalid timezone string falls back safely without erroring
        $schoolInvalid = School::create([
            'name'     => 'Invalid TZ Academy',
            'slug'     => 'inv-tz-' . uniqid(),
            'email'    => 'inv-' . uniqid() . '@inv.test',
            'timezone' => 'Fictional/Atlantis_Standard_Time',
            'status'   => 'active',
        ]);
        $this->assertSame(config('app.timezone') ?: 'UTC', $service->resolveSchoolTimezone($schoolInvalid->id));

        // Non-existent school falls back to config/UTC
        $this->assertSame(config('app.timezone') ?: 'UTC', $service->resolveSchoolTimezone(999999999));
    }

    public function test_tenant_isolation_in_dashboard_financial_metrics(): void
    {
        $classB = SchoolClass::create([
            'school_id'    => $this->schoolB->id,
            'name'         => 'Grade B1',
            'numeric_name' => 1,
        ]);

        $ayB = AcademicYear::create([
            'school_id'  => $this->schoolB->id,
            'name'       => '2026-2027',
            'start_date' => Carbon::now()->startOfYear()->toDateString(),
            'end_date'   => Carbon::now()->endOfYear()->toDateString(),
            'is_current' => true,
        ]);

        // School B has high numbers
        $studentB = Student::create([
            'school_id'     => $this->schoolB->id,
            'class_id'      => $classB->id,
            'admission_no'  => 'ADM-B1',
            'first_name'    => 'Student',
            'last_name'     => 'Beta',
            'gender'        => 'female',
            'date_of_birth' => '2016-01-01',
            'status'        => 'active',
        ]);

        FeePayment::create([
            'school_id'        => $this->schoolB->id,
            'student_id'       => $studentB->id,
            'receipt_no'       => 'RCP-B1',
            'amount_due'       => '99000.00',
            'amount_paid'      => '99000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => now()->toDateString(),
            'month_year'       => now()->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        FeeChallan::create([
            'school_id'          => $this->schoolB->id,
            'academic_year_id'   => $ayB->id,
            'student_id'         => $studentB->id,
            'class_id'           => $classB->id,
            'student_name'       => 'Student Beta',
            'admission_no'       => 'ADM-B1',
            'class_name'         => 'Grade B1',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-B1',
            'issue_date'         => now()->toDateString(),
            'due_date'           => now()->addDays(5)->toDateString(),
            'gross_amount'       => '50000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '50000.00',
            'paid_amount'        => '0.00',
            'status'             => 'unpaid',
            'billing_period_key' => now()->format('Y-m'),
        ]);

        // School A has modest numbers
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'receipt_no'       => 'RCP-A1',
            'amount_due'       => '1500.00',
            'amount_paid'      => '1500.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => now()->toDateString(),
            'month_year'       => now()->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('school.reports.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Dashboard')
            ->where('monthFees', fn ($val) => (float) $val === 1500.0)
            ->where('pendingFees', fn ($val) => (float) $val === 0.0)
        );
    }
}
