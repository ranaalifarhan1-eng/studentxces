<?php

namespace Tests\Feature\SchoolAdmin;

use App\Models\AcademicYear;
use App\Models\FeeChallan;
use App\Models\FeeChallanAdjustment;
use App\Models\FeePayment;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Services\FeeFinancialSummaryService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeeManagementStatsReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $schoolB;
    protected User $adminUser;
    protected Student $student;
    protected SchoolClass $class;
    protected Section $section;
    protected AcademicYear $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        $pView = Permission::firstOrCreate(['name' => 'fees.view', 'guard_name' => 'web']);
        $pCollect = Permission::firstOrCreate(['name' => 'fees.collect', 'guard_name' => 'web']);
        $pAdjust = Permission::firstOrCreate(['name' => 'fees.adjustment', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([$pView, $pCollect, $pAdjust]);

        $this->school = School::create([
            'name'   => 'LCS Primary',
            'slug'   => 'lcs-primary-' . uniqid(),
            'email'  => 'primary-' . uniqid() . '@lcs.test',
            'status' => 'active',
        ]);

        $this->schoolB = School::create([
            'name'   => 'LCS High',
            'slug'   => 'lcs-high-' . uniqid(),
            'email'  => 'high-' . uniqid() . '@lcs.test',
            'status' => 'active',
        ]);

        $package = Package::create([
            'name'          => 'Test Plan',
            'slug'          => 'plan-' . uniqid(),
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

        $this->adminUser = User::create([
            'name'              => 'Primary Admin',
            'email'             => 'admin-' . uniqid() . '@primary.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => '2026-2027',
            'start_date' => Carbon::now()->startOfYear()->toDateString(),
            'end_date'   => Carbon::now()->endOfYear()->toDateString(),
            'is_current' => true,
        ]);

        $this->class = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Grade 3',
            'numeric_name' => 3,
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
            'admission_no'  => 'ADM-FM-01',
            'first_name'    => 'Finance',
            'last_name'     => 'Reconcile',
            'gender'        => 'male',
            'date_of_birth' => '2015-08-08',
            'status'        => 'active',
        ]);
    }

    public function test_fee_management_stats_lifecycle_aggregation(): void
    {
        // 1. Fully paid challan: 10000 payable, 10000 paid
        $c1 = FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Finance Reconcile',
            'admission_no'       => 'ADM-FM-01',
            'class_name'         => 'Grade 3',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-L1',
            'issue_date'         => now()->toDateString(),
            'due_date'           => now()->addDays(10)->toDateString(),
            'gross_amount'       => '10000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '10000.00',
            'paid_amount'        => '10000.00',
            'status'             => 'paid',
            'billing_period_key' => now()->format('Y-m'),
        ]);

        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $c1->id,
            'receipt_no'       => 'RCP-L1',
            'amount_due'       => '10000.00',
            'amount_paid'      => '10000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => now()->toDateString(),
            'month_year'       => now()->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // 2. Partial challan: 12000 payable, 4000 paid -> 8000 balance
        $c2 = FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Finance Reconcile',
            'admission_no'       => 'ADM-FM-01',
            'class_name'         => 'Grade 3',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-L2',
            'issue_date'         => now()->toDateString(),
            'due_date'           => now()->addDays(10)->toDateString(),
            'gross_amount'       => '12000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '12000.00',
            'paid_amount'        => '4000.00',
            'status'             => 'partial',
            'billing_period_key' => now()->format('Y-m'),
        ]);

        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $c2->id,
            'receipt_no'       => 'RCP-L2',
            'amount_due'       => '12000.00',
            'amount_paid'      => '4000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '8000.00',
            'payment_date'     => now()->toDateString(),
            'month_year'       => now()->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // 3. Unpaid challan: 5000 payable, 0 paid -> 5000 balance
        FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Finance Reconcile',
            'admission_no'       => 'ADM-FM-01',
            'class_name'         => 'Grade 3',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-L3',
            'issue_date'         => now()->toDateString(),
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

        $stats = app(FeeFinancialSummaryService::class)->getFeeManagementStats($this->school->id);

        $this->assertSame('14000.00', $stats['total_collected']);
        $this->assertSame('13000.00', $stats['total_outstanding']); // 8000 + 5000
        $this->assertSame(2, $stats['paid_count']);
        $this->assertSame(2, $stats['pending_count']); // c2 + c3
    }

    public function test_voided_challans_are_strictly_excluded_from_outstanding(): void
    {
        FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Finance Reconcile',
            'admission_no'       => 'ADM-FM-01',
            'class_name'         => 'Grade 3',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-VOID-1',
            'issue_date'         => now()->toDateString(),
            'due_date'           => now()->addDays(10)->toDateString(),
            'gross_amount'       => '60000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '60000.00',
            'paid_amount'        => '0.00',
            'status'             => 'void',
            'billing_period_key' => now()->format('Y-m'),
        ]);

        $service = app(FeeFinancialSummaryService::class);

        $this->assertSame(0, $service->currentOutstandingCents($this->school->id));
        $this->assertSame('0.00', $service->getFeeManagementStats($this->school->id)['total_outstanding']);
    }

    public function test_non_cash_adjustments_reduce_outstanding_without_counting_as_collected(): void
    {
        // Challan: 12000 initial payable
        $challan = FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Finance Reconcile',
            'admission_no'       => 'ADM-FM-01',
            'class_name'         => 'Grade 3',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-ADJ-1',
            'issue_date'         => now()->toDateString(),
            'due_date'           => now()->addDays(10)->toDateString(),
            'gross_amount'       => '12000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '12000.00',
            'paid_amount'        => '0.00',
            'status'             => 'unpaid',
            'billing_period_key' => now()->format('Y-m'),
        ]);

        // Apply 2000 adjustment (reduces total_payable to 10000, tracks adjustment_amount = 2000)
        $challan->total_payable = '10000.00';
        $challan->adjustment_amount = '2000.00';
        $challan->save();

        $stats = app(FeeFinancialSummaryService::class)->getFeeManagementStats($this->school->id);

        $this->assertSame('10000.00', $stats['total_outstanding']);
        $this->assertSame('0.00', $stats['total_collected']);
        $this->assertSame('2000.00', $stats['total_adjustments']);
    }

    public function test_legacy_payments_outstanding_calculation(): void
    {
        // Legacy record: fee_challan_id is NULL, status is pending
        // amount_due 3000 + fine 200 - discount 100 - amount_paid 0 = 3100 balance
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => null,
            'receipt_no'       => 'RCP-LEG-1',
            'amount_due'       => '3000.00',
            'fine'             => '200.00',
            'discount'         => '100.00',
            'amount_paid'      => '0.00',
            'balance_snapshot' => null,
            'payment_date'     => now()->toDateString(),
            'month_year'       => now()->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'pending',
        ]);

        $service = app(FeeFinancialSummaryService::class);

        $this->assertSame(310000, $service->legacyOutstandingCents($this->school->id));
        $this->assertSame('3100.00', $service->getFeeManagementStats($this->school->id)['total_outstanding']);
    }

    public function test_installment_payment_balance_snapshot_integrity(): void
    {
        $challan = FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Finance Reconcile',
            'admission_no'       => 'ADM-FM-01',
            'class_name'         => 'Grade 3',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-INST-1',
            'issue_date'         => now()->toDateString(),
            'due_date'           => now()->addDays(10)->toDateString(),
            'gross_amount'       => '10000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '10000.00',
            'paid_amount'        => '7000.00',
            'status'             => 'partial',
            'billing_period_key' => now()->format('Y-m'),
        ]);

        // First installment: paid 4000, balance snapshot 6000
        $p1 = FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $challan->id,
            'receipt_no'       => 'RCP-INST-1',
            'amount_due'       => '10000.00',
            'amount_paid'      => '4000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '6000.00',
            'payment_date'     => now()->subDays(2)->toDateString(),
            'month_year'       => now()->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // Second installment: paid 3000, balance snapshot 3000
        $p2 = FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $challan->id,
            'receipt_no'       => 'RCP-INST-2',
            'amount_due'       => '10000.00',
            'amount_paid'      => '3000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '3000.00',
            'payment_date'     => now()->toDateString(),
            'month_year'       => now()->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('school.fees.payments.index', ['tab' => 'payments']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Payments')
            ->where('stats.total_collected', '7000.00')
            ->where('stats.total_outstanding', '3000.00')
            ->has('payments.data', 2)
            ->where('payments.data.0.balance_snapshot', '3000.00')
            ->where('payments.data.1.balance_snapshot', '6000.00')
        );
    }

    public function test_exact_10k_obligation_2k_adjustment_3k_payment_deterministic_audit(): void
    {
        // 1. Original obligation: 10,000
        $challan = FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Audit Student',
            'admission_no'       => 'ADM-AUD-01',
            'class_name'         => 'Grade 3',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-AUD-10K',
            'issue_date'         => now()->toDateString(),
            'due_date'           => now()->addDays(10)->toDateString(),
            'gross_amount'       => '10000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '10000.00',
            'paid_amount'        => '0.00',
            'status'             => 'unpaid',
            'billing_period_key' => now()->format('Y-m'),
        ]);

        // 2. Apply 2,000 adjustment via FeeAdjustmentService
        \App\Services\FeeAdjustmentService::applyAdjustment(
            $challan,
            [
                'adjustment_type' => 'fixed',
                'value'           => '2000.00',
                'reason'          => 'special_relief',
                'notes'           => 'Audit test adjustment',
            ],
            $this->adminUser
        );

        $challan->refresh();
        // Proves total_payable becomes 8,000 and adjustment_amount becomes 2,000
        $this->assertSame('8000.00', $challan->total_payable);
        $this->assertSame('2000.00', $challan->adjustment_amount);

        // 3. Cash payment: 3,000
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $challan->id,
            'receipt_no'       => 'RCP-AUD-3K',
            'amount_due'       => '8000.00',
            'amount_paid'      => '3000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '5000.00',
            'payment_date'     => now()->toDateString(),
            'month_year'       => now()->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'paid',
        ]);
        $challan->paid_amount = '3000.00';
        $challan->status = 'partial';
        $challan->save();

        // Surface A: FeeFinancialSummaryService
        $service = app(FeeFinancialSummaryService::class);
        $this->assertSame(500000, $service->currentOutstandingCents($this->school->id)); // Expected 5,000
        $this->assertSame(300000, $service->lifetimeCollectedCents($this->school->id));  // Expected 3,000
        $this->assertSame(200000, $service->totalAdjustmentsCents($this->school->id));   // Expected 2,000

        // Surface B: Fee Management stats
        $stats = $service->getFeeManagementStats($this->school->id);
        $this->assertSame('5000.00', $stats['total_outstanding']);
        $this->assertSame('3000.00', $stats['total_collected']);
        $this->assertSame('2000.00', $stats['total_adjustments']);

        // Surface C: Dashboard current outstanding & month collections
        $dashResponse = $this->actingAs($this->adminUser)->get(route('school.reports.dashboard'));
        $dashResponse->assertOk();
        $dashResponse->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Dashboard')
            ->where('pendingFees', fn ($val) => (float) $val === 5000.0)
            ->where('monthFees', fn ($val) => (float) $val === 3000.0)
        );

        // Surface D: Finance Report current outstanding & collected
        $finResponse = $this->actingAs($this->adminUser)->get(route('school.reports.finance', [
            'from_date' => now()->startOfMonth()->toDateString(),
            'to_date'   => now()->endOfMonth()->toDateString(),
        ]));
        $finResponse->assertOk();
        $finResponse->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Finance')
            ->where('outstanding', fn ($val) => (float) $val === 5000.0)
            ->where('collected', fn ($val) => (float) $val === 3000.0)
        );
    }

    public function test_adjustment_only_challan(): void
    {
        $challan = FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Adj Only Student',
            'admission_no'       => 'ADM-ADJ-01',
            'class_name'         => 'Grade 3',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-ADJ-ONLY',
            'issue_date'         => now()->toDateString(),
            'due_date'           => now()->addDays(10)->toDateString(),
            'gross_amount'       => '10000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '10000.00',
            'paid_amount'        => '0.00',
            'status'             => 'unpaid',
            'billing_period_key' => now()->format('Y-m'),
        ]);

        \App\Services\FeeAdjustmentService::applyAdjustment(
            $challan,
            [
                'adjustment_type' => 'fixed',
                'value'           => '4000.00',
                'reason'          => 'scholarship',
                'notes'           => 'Partial scholarship',
            ],
            $this->adminUser
        );

        $service = app(FeeFinancialSummaryService::class);
        $this->assertSame(600000, $service->currentOutstandingCents($this->school->id));
        $this->assertSame(0, $service->lifetimeCollectedCents($this->school->id));
        $this->assertSame(400000, $service->totalAdjustmentsCents($this->school->id));
    }

    public function test_fully_adjusted_challan_settles_and_removes_from_outstanding(): void
    {
        $challan = FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Full Adj Student',
            'admission_no'       => 'ADM-FADJ-01',
            'class_name'         => 'Grade 3',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-FULL-ADJ',
            'issue_date'         => now()->toDateString(),
            'due_date'           => now()->addDays(10)->toDateString(),
            'gross_amount'       => '10000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '10000.00',
            'paid_amount'        => '0.00',
            'status'             => 'unpaid',
            'billing_period_key' => now()->format('Y-m'),
        ]);

        \App\Services\FeeAdjustmentService::applyAdjustment(
            $challan,
            [
                'adjustment_type' => 'fixed',
                'value'           => '10000.00',
                'reason'          => 'full_waiver',
                'notes'           => '100% full waiver',
            ],
            $this->adminUser
        );

        $challan->refresh();
        $this->assertSame('paid', $challan->status);
        $this->assertSame('0.00', $challan->total_payable);

        $service = app(FeeFinancialSummaryService::class);
        $this->assertSame(0, $service->currentOutstandingCents($this->school->id));
        $this->assertSame(0, $service->lifetimeCollectedCents($this->school->id));
        $this->assertSame(1000000, $service->totalAdjustmentsCents($this->school->id));
    }

    public function test_voided_adjusted_challan_is_zero_outstanding(): void
    {
        $challan = FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Void Adj Student',
            'admission_no'       => 'ADM-VOID-01',
            'class_name'         => 'Grade 3',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-VOID-ADJ',
            'issue_date'         => now()->toDateString(),
            'due_date'           => now()->addDays(10)->toDateString(),
            'gross_amount'       => '10000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '10000.00',
            'paid_amount'        => '0.00',
            'status'             => 'unpaid',
            'billing_period_key' => now()->format('Y-m'),
        ]);

        \App\Services\FeeAdjustmentService::applyAdjustment(
            $challan,
            [
                'adjustment_type' => 'fixed',
                'value'           => '2000.00',
                'reason'          => 'management_discount',
                'notes'           => 'Initial adjustment',
            ],
            $this->adminUser
        );

        // Later voided by administrator
        $challan->status = 'void';
        $challan->save();

        $service = app(FeeFinancialSummaryService::class);
        $this->assertSame(0, $service->currentOutstandingCents($this->school->id));
    }

    public function test_receipt_balance_snapshot_modern_vs_legacy_contract(): void
    {
        $challan = FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Contract Student',
            'admission_no'       => 'ADM-CON-01',
            'class_name'         => 'Grade 3',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-CON-01',
            'issue_date'         => now()->toDateString(),
            'due_date'           => now()->addDays(10)->toDateString(),
            'gross_amount'       => '10000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '10000.00',
            'paid_amount'        => '6000.00',
            'status'             => 'partial',
            'billing_period_key' => now()->format('Y-m'),
        ]);

        // 1. Modern payment with snapshot
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $challan->id,
            'receipt_no'       => 'RCP-MOD-SNAP',
            'amount_due'       => '10000.00',
            'amount_paid'      => '4000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '6000.00',
            'payment_date'     => now()->subDays(1)->toDateString(),
            'month_year'       => now()->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // 2. Modern payment with missing snapshot (legacy migrated modern row)
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => $challan->id,
            'receipt_no'       => 'RCP-MOD-NOSNAP',
            'amount_due'       => '10000.00',
            'amount_paid'      => '2000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => null,
            'payment_date'     => now()->toDateString(),
            'month_year'       => now()->format('Y-m'),
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // 3. Legacy standalone payment without fee_challan_id
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'fee_challan_id'   => null,
            'receipt_no'       => 'RCP-LEG-STANDALONE',
            'amount_due'       => '5000.00',
            'amount_paid'      => '2000.00',
            'discount'         => '500.00',
            'fine'             => '100.00',
            'balance_snapshot' => null,
            'payment_date'     => now()->toDateString(),
            'month_year'       => now()->format('Y-m'),
            'method'           => 'bank_transfer',
            'status'           => 'partial',
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('school.fees.payments.index', ['tab' => 'payments']));
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Payments')
            ->has('payments.data', 3)
            // Verify payment 0 (RCP-LEG-STANDALONE): fee_challan_id is null
            ->where('payments.data.0.receipt_no', 'RCP-LEG-STANDALONE')
            ->where('payments.data.0.fee_challan_id', null)
            ->where('payments.data.0.balance_snapshot', null)
            // Verify payment 1 (RCP-MOD-NOSNAP): modern linked, balance_snapshot is null
            ->where('payments.data.1.receipt_no', 'RCP-MOD-NOSNAP')
            ->where('payments.data.1.fee_challan_id', $challan->id)
            ->where('payments.data.1.balance_snapshot', null)
            // Verify payment 2 (RCP-MOD-SNAP): modern linked, balance_snapshot is 6000.00
            ->where('payments.data.2.receipt_no', 'RCP-MOD-SNAP')
            ->where('payments.data.2.fee_challan_id', $challan->id)
            ->where('payments.data.2.balance_snapshot', '6000.00')
        );
    }
}
