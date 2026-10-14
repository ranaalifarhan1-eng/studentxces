<?php

namespace Tests\Feature\SchoolAdmin;

use App\Models\AcademicYear;
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

class FinanceReportMetricsTest extends TestCase
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

        $pReports = Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        $pFees = Permission::firstOrCreate(['name' => 'fees.view', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([$pReports, $pFees]);

        $this->school = School::create([
            'name'   => 'School Alpha',
            'slug'   => 'school-alpha-' . uniqid(),
            'email'  => 'alpha-' . uniqid() . '@alpha.test',
            'status' => 'active',
        ]);

        $this->schoolB = School::create([
            'name'   => 'School Beta',
            'slug'   => 'school-beta-' . uniqid(),
            'email'  => 'beta-' . uniqid() . '@beta.test',
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
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'reports']);
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
            'name'              => 'Alpha Admin',
            'email'             => 'admin-' . uniqid() . '@alpha.test',
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
            'name'         => 'Grade 2',
            'numeric_name' => 2,
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
            'admission_no'  => 'ADM-FR-01',
            'first_name'    => 'Finance',
            'last_name'     => 'Student',
            'gender'        => 'male',
            'date_of_birth' => '2015-05-05',
            'status'        => 'active',
        ]);
    }

    public function test_finance_report_respects_date_range_for_collections(): void
    {
        // Payment in August: 3000
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'receipt_no'       => 'RCP-AUG',
            'amount_due'       => '3000.00',
            'amount_paid'      => '3000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => '2026-08-15',
            'month_year'       => '2026-08',
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // Payment in September: 4500
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'receipt_no'       => 'RCP-SEP',
            'amount_due'       => '4500.00',
            'amount_paid'      => '4500.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => '2026-09-15',
            'month_year'       => '2026-09',
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // Payment in October: 6000
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'receipt_no'       => 'RCP-OCT',
            'amount_due'       => '6000.00',
            'amount_paid'      => '6000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => '2026-10-05',
            'month_year'       => '2026-10',
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // Filter exclusively for September 2026
        $response = $this->actingAs($this->adminUser)->get(route('school.reports.finance', [
            'from_date' => '2026-09-01',
            'to_date'   => '2026-09-30',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Finance')
            ->where('collected', fn ($val) => (float) $val === 4500.0)
            ->where('filters.from_date', '2026-09-01')
            ->where('filters.to_date', '2026-09-30')
        );
    }

    public function test_finance_report_outstanding_is_point_in_time_snapshot_unaffected_by_date_filter(): void
    {
        // Unpaid obligation: 14000
        FeeChallan::create([
            'school_id'          => $this->school->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'section_id'         => $this->section->id,
            'student_name'       => 'Finance Student',
            'admission_no'       => 'ADM-FR-01',
            'class_name'         => 'Grade 2',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-OUT-1',
            'issue_date'         => '2026-10-01',
            'due_date'           => '2026-10-15',
            'gross_amount'       => '14000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '14000.00',
            'paid_amount'        => '0.00',
            'status'             => 'unpaid',
            'billing_period_key' => '2026-10',
        ]);

        // Filter with a narrow past date range (January 2026)
        $response = $this->actingAs($this->adminUser)->get(route('school.reports.finance', [
            'from_date' => '2026-01-01',
            'to_date'   => '2026-01-15',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Finance')
            ->where('collected', fn ($val) => (float) $val === 0.0)
            ->where('outstanding', fn ($val) => (float) $val === 14000.0) // Current snapshot is preserved regardless of date filter
        );
    }

    public function test_finance_report_daily_chart_structure(): void
    {
        // Day 1 payment: 1000
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'receipt_no'       => 'RCP-D1',
            'amount_due'       => '1000.00',
            'amount_paid'      => '1000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => '2026-09-10',
            'month_year'       => '2026-09',
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        // Day 2 payment: 2500
        FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student->id,
            'receipt_no'       => 'RCP-D2',
            'amount_due'       => '2500.00',
            'amount_paid'      => '2500.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => '2026-09-12',
            'month_year'       => '2026-09',
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('school.reports.finance', [
            'from_date' => '2026-09-01',
            'to_date'   => '2026-09-30',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Finance')
            ->where('collected', fn ($val) => (float) $val === 3500.0)
            ->has('dailyChart', 2)
            ->where('dailyChart.0.day', '2026-09-10')
            ->where('dailyChart.0.amount', fn ($val) => (float) $val === 1000.0)
            ->where('dailyChart.1.day', '2026-09-12')
            ->where('dailyChart.1.amount', fn ($val) => (float) $val === 2500.0)
        );
    }

    public function test_finance_report_tenant_isolation(): void
    {
        $classB = SchoolClass::create([
            'school_id'    => $this->schoolB->id,
            'name'         => 'Grade B2',
            'numeric_name' => 2,
        ]);

        $ayB = AcademicYear::create([
            'school_id'  => $this->schoolB->id,
            'name'       => '2026-2027',
            'start_date' => Carbon::now()->startOfYear()->toDateString(),
            'end_date'   => Carbon::now()->endOfYear()->toDateString(),
            'is_current' => true,
        ]);

        $studentB = Student::create([
            'school_id'     => $this->schoolB->id,
            'class_id'      => $classB->id,
            'admission_no'  => 'ADM-FR-B1',
            'first_name'    => 'Beta',
            'last_name'     => 'Student',
            'gender'        => 'female',
            'date_of_birth' => '2015-06-06',
            'status'        => 'active',
        ]);

        FeePayment::create([
            'school_id'        => $this->schoolB->id,
            'student_id'       => $studentB->id,
            'receipt_no'       => 'RCP-FB1',
            'amount_due'       => '75000.00',
            'amount_paid'      => '75000.00',
            'discount'         => '0.00',
            'fine'             => '0.00',
            'balance_snapshot' => '0.00',
            'payment_date'     => '2026-09-15',
            'month_year'       => '2026-09',
            'method'           => 'cash',
            'status'           => 'paid',
        ]);

        FeeChallan::create([
            'school_id'          => $this->schoolB->id,
            'academic_year_id'   => $ayB->id,
            'student_id'         => $studentB->id,
            'class_id'           => $classB->id,
            'student_name'       => 'Beta Student',
            'admission_no'       => 'ADM-FR-B1',
            'class_name'         => 'Grade B2',
            'academic_year_name' => '2026-2027',
            'challan_no'         => 'CH-FB1',
            'issue_date'         => '2026-09-01',
            'due_date'           => '2026-09-15',
            'gross_amount'       => '30000.00',
            'discount_amount'    => '0.00',
            'adjustment_amount'  => '0.00',
            'fine_amount'        => '0.00',
            'total_payable'      => '30000.00',
            'paid_amount'        => '0.00',
            'status'             => 'unpaid',
            'billing_period_key' => '2026-09',
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('school.reports.finance', [
            'from_date' => '2026-09-01',
            'to_date'   => '2026-09-30',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Reports/Finance')
            ->where('collected', fn ($val) => (float) $val === 0.0)
            ->where('outstanding', fn ($val) => (float) $val === 0.0)
        );
    }
}
