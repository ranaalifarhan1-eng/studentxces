<?php

namespace Tests\Feature;

use App\Jobs\ProcessSchoolRecurringBillingJob;
use App\Models\AcademicYear;
use App\Models\FeeBillingRun;
use App\Models\FeeCategory;
use App\Models\FeeChallan;
use App\Models\FeeChallanAdjustment;
use App\Models\FeeChallanItem;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\SchoolSubscription;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeDiscount;
use App\Models\User;
use App\Services\FeeAdjustmentService;
use App\Services\FeeBillingService;
use App\Services\FeeBulkAssignService;
use App\Services\RecurringFeeBillingService;
use App\Services\StudentFeeAssignmentService;
use App\Services\StudentFinancialLedgerService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecurringFeeBillingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $schoolB;
    protected User $adminUser;
    protected AcademicYear $academicYear;
    protected AcademicYear $academicYearB;
    protected SchoolClass $class;
    protected Student $student1;
    protected Student $student2;
    protected FeeCategory $tuitionCat;
    protected FeeCategory $transportCat;
    protected FeeCategory $admissionCat;
    protected FeeStructure $monthlyTuition;
    protected FeeStructure $optionalTransport;
    protected FeeStructure $oneTimeAdmission;
    protected FeeStructure $annualResourceFee;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = [
            'students.view', 'students.create', 'fees.view', 'fees.collect',
            'fees.structure', 'fees.assign', 'fees.bulk_bill', 'fees.discount',
        ];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $superAdminRole = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $schoolAdminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $schoolAdminRole->syncPermissions($permissions);

        // Package & Module Entitlements
        $package = Package::firstOrCreate(
            ['slug' => 'enterprise-test'],
            ['name' => 'Enterprise Test', 'price_monthly' => 0, 'is_active' => true]
        );
        PackageModule::firstOrCreate(['package_id' => $package->id, 'module_slug' => 'fees']);
        PackageModule::firstOrCreate(['package_id' => $package->id, 'module_slug' => 'students']);

        // School A setup
        $this->school = School::create([
            'name'       => 'Alpha Grammar School',
            'slug'       => 'alpha-grammar',
            'status'     => 'active',
            'timezone'   => 'Asia/Karachi',
            'currency'   => 'PKR',
        ]);

        SchoolSubscription::create([
            'school_id'  => $this->school->id,
            'package_id' => $package->id,
            'status'     => 'active',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date'   => now()->addYear()->toDateString(),
        ]);

        // School B setup (for multi-tenant isolation testing)
        $this->schoolB = School::create([
            'name'       => 'Beta International Academy',
            'slug'       => 'beta-international',
            'status'     => 'active',
            'timezone'   => 'Asia/Karachi',
            'currency'   => 'PKR',
        ]);

        SchoolSubscription::create([
            'school_id'  => $this->schoolB->id,
            'package_id' => $package->id,
            'status'     => 'active',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date'   => now()->addYear()->toDateString(),
        ]);

        // Academic Years
        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date'   => '2027-05-31',
            'is_current' => true,
        ]);

        $this->academicYearB = AcademicYear::create([
            'school_id'  => $this->schoolB->id,
            'name'       => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date'   => '2027-05-31',
            'is_current' => true,
        ]);

        // Users
        $this->adminUser = User::create([
            'name'      => 'School Admin',
            'email'     => 'admin@alphagrammar.edu',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
        ]);
        $this->adminUser->assignRole($schoolAdminRole);

        // Class & Students
        $this->class = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Grade 6',
            'numeric_name' => 6,
        ]);

        $this->student1 = Student::create([
            'school_id'    => $this->school->id,
            'class_id'     => $this->class->id,
            'admission_no' => 'ADM-001',
            'first_name'   => 'Ahmed',
            'last_name'    => 'Ali',
            'status'       => 'active',
        ]);

        $this->student2 = Student::create([
            'school_id'    => $this->school->id,
            'class_id'     => $this->class->id,
            'admission_no' => 'ADM-002',
            'first_name'   => 'Bilal',
            'last_name'    => 'Khan',
            'status'       => 'active',
        ]);

        // Fee Categories
        $this->tuitionCat = FeeCategory::create(['school_id' => $this->school->id, 'name' => 'Tuition Fee']);
        $this->transportCat = FeeCategory::create(['school_id' => $this->school->id, 'name' => 'Transport Fee']);
        $this->admissionCat = FeeCategory::create(['school_id' => $this->school->id, 'name' => 'Admission Fee']);

        // Structures
        $this->monthlyTuition = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'fee_category_id'          => $this->tuitionCat->id,
            'academic_year'            => '2026-2027',
            'amount'                   => '5000.00',
            'frequency'                => 'monthly',
            'is_active'                => true,
            'is_optional'              => false,
            'admission_voucher_policy' => 'required',
        ]);

        $this->optionalTransport = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'fee_category_id'          => $this->transportCat->id,
            'academic_year'            => '2026-2027',
            'amount'                   => '2000.00',
            'frequency'                => 'monthly',
            'is_active'                => true,
            'is_optional'              => true,
            'admission_voucher_policy' => 'optional',
        ]);

        $this->oneTimeAdmission = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'fee_category_id'          => $this->admissionCat->id,
            'academic_year'            => '2026-2027',
            'amount'                   => '10000.00',
            'frequency'                => 'one_time',
            'is_active'                => true,
            'is_optional'              => true,
            'admission_voucher_policy' => 'required',
        ]);

        $this->annualResourceFee = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'fee_category_id'          => $this->tuitionCat->id,
            'academic_year'            => '2026-2027',
            'amount'                   => '3000.00',
            'frequency'                => 'annual',
            'is_active'                => true,
            'is_optional'              => true,
            'admission_voucher_policy' => 'optional',
        ]);
    }

    public function test_auto_billing_settings_default_disabled_and_per_school_isolation(): void
    {
        // 1. Default for existing school must be disabled
        $settingsA = RecurringFeeBillingService::getSchoolSettings($this->school->id);
        $this->assertFalse($settingsA['auto_billing_enabled']);
        $this->assertSame(1, $settingsA['auto_billing_day']);
        $this->assertSame(10, $settingsA['recurring_fee_due_day']);

        // 2. Configure School A
        RecurringFeeBillingService::saveSchoolSettings($this->school->id, [
            'auto_billing_enabled'  => true,
            'auto_billing_day'      => 5,
            'recurring_fee_due_day' => 15,
        ]);

        $updatedA = RecurringFeeBillingService::getSchoolSettings($this->school->id);
        $this->assertTrue($updatedA['auto_billing_enabled']);
        $this->assertSame(5, $updatedA['auto_billing_day']);
        $this->assertSame(15, $updatedA['recurring_fee_due_day']);

        // 3. School B remains completely unaffected
        $settingsB = RecurringFeeBillingService::getSchoolSettings($this->schoolB->id);
        $this->assertFalse($settingsB['auto_billing_enabled']);
        $this->assertSame(1, $settingsB['auto_billing_day']);
        $this->assertSame(10, $settingsB['recurring_fee_due_day']);

        // 4. Clamping validation: day values outside 1..28 clamped safely
        RecurringFeeBillingService::saveSchoolSettings($this->school->id, [
            'auto_billing_enabled'  => true,
            'auto_billing_day'      => 31,
            'recurring_fee_due_day' => 0,
        ]);
        $clampedA = RecurringFeeBillingService::getSchoolSettings($this->school->id);
        $this->assertSame(28, $clampedA['auto_billing_day']);
        $this->assertSame(1, $clampedA['recurring_fee_due_day']);
    }

    public function test_monthly_fee_october_billed_november_generates_one_challan_and_december_continues(): void
    {
        // Student 1 assigned mandatory monthly tuition starting October 2026
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-10-01')
        );

        // Initial October voucher generated (e.g. at admission)
        $octoberChallan = FeeBillingService::createChallan(
            $this->student1,
            $this->academicYear,
            Carbon::parse('2026-10-01')
        );
        $this->assertNotNull($octoberChallan);
        $this->assertSame('5000.00', $octoberChallan->total_payable);
        $this->assertSame('AY' . $this->academicYear->id . '-2026-10', $octoberChallan->billing_period_key);

        // Execute November recurring billing cycle
        $novResult = RecurringFeeBillingService::executeBillingCycle(
            $this->school->id,
            Carbon::parse('2026-11-01'),
            'scheduler'
        );

        $this->assertSame('completed', $novResult['status']);
        $this->assertSame(1, $novResult['challans_created']);
        $this->assertSame(0, $novResult['challans_skipped']);

        // Verify exactly one November challan exists
        $novChallans = FeeChallan::where('student_id', $this->student1->id)
            ->where('billing_period_key', 'AY' . $this->academicYear->id . '-2026-11')
            ->get();
        $this->assertCount(1, $novChallans);
        $this->assertSame('5000.00', $novChallans->first()->total_payable);

        // SECOND November run creates ZERO duplicate challans
        $secondNovResult = RecurringFeeBillingService::executeBillingCycle(
            $this->school->id,
            Carbon::parse('2026-11-01'),
            'scheduler'
        );
        $this->assertSame('already_completed', $secondNovResult['status']);
        $this->assertCount(1, FeeChallan::where('student_id', $this->student1->id)->where('billing_period_key', 'AY' . $this->academicYear->id . '-2026-11')->get());

        // Execute December cycle creates exactly ONE next challan
        $decResult = RecurringFeeBillingService::executeBillingCycle(
            $this->school->id,
            Carbon::parse('2026-12-01'),
            'scheduler'
        );
        $this->assertSame('completed', $decResult['status']);
        $this->assertSame(1, $decResult['challans_created']);

        $totalStudentChallans = FeeChallan::where('student_id', $this->student1->id)->count();
        $this->assertSame(3, $totalStudentChallans); // Oct, Nov, Dec
    }

    public function test_billing_starts_on_future_month_skips_prior_month(): void
    {
        // Student 1 admitted with Billing Starts From November 2026
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        // October cycle must skip student 1
        $octResult = RecurringFeeBillingService::executeBillingCycle(
            $this->school->id,
            Carbon::parse('2026-10-01'),
            'scheduler'
        );
        $this->assertSame(0, $octResult['challans_created']);

        // November cycle must bill student 1
        $novResult = RecurringFeeBillingService::executeBillingCycle(
            $this->school->id,
            Carbon::parse('2026-11-01'),
            'scheduler'
        );
        $this->assertSame(1, $novResult['challans_created']);
    }

    public function test_assignment_ends_on_prevents_billing_after_end_month(): void
    {
        // Student 1 assignment active from October 2026 and ends on November 30, 2026
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-10-01'),
            Carbon::parse('2026-11-30')
        );

        // November cycle generates challan
        $novResult = RecurringFeeBillingService::executeBillingCycle(
            $this->school->id,
            Carbon::parse('2026-11-01'),
            'scheduler'
        );
        $this->assertSame(1, $novResult['challans_created']);

        // December cycle must skip because assignment ended in November
        $decResult = RecurringFeeBillingService::executeBillingCycle(
            $this->school->id,
            Carbon::parse('2026-12-01'),
            'scheduler'
        );
        $this->assertSame(0, $decResult['challans_created']);
    }

    public function test_standing_concession_repeats_across_cycles_and_one_time_adjustment_does_not_repeat(): void
    {
        // 1. Assign tuition
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-10-01')
        );

        // 2. Standing 20% concession created
        StudentFeeDiscount::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student1->id,
            'academic_year_id' => $this->academicYear->id,
            'title'            => 'Merit Scholarship 20%',
            'type'             => 'percentage',
            'value'            => 20.00,
            'is_active'        => true,
        ]);

        // 3. Generate October challan: 5,000 - 20% (1,000) = 4,000
        $octChallan = FeeBillingService::createChallan($this->student1, $this->academicYear, Carbon::parse('2026-10-01'));
        $this->assertSame('4000.00', $octChallan->total_payable);
        $this->assertSame('1000.00', $octChallan->discount_amount);

        // 4. Apply a one-time adjustment of PKR 500 to October challan
        FeeChallanAdjustment::create([
            'school_id'         => $this->school->id,
            'fee_challan_id'    => $octChallan->id,
            'student_id'        => $this->student1->id,
            'adjustment_type'   => 'fixed',
            'value'             => 500.00,
            'adjustment_amount' => 500.00,
            'previous_balance'  => 4000.00,
            'new_balance'       => 3500.00,
            'reason'            => 'Special one-time sports kit allowance',
            'created_by'        => $this->adminUser->id,
        ]);
        $octChallan->update(['adjustment_amount' => '500.00']);

        // 5. Run November cycle
        $novResult = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-01'));
        $this->assertSame(1, $novResult['challans_created']);

        $novChallan = FeeChallan::where('student_id', $this->student1->id)
            ->where('billing_period_key', 'AY' . $this->academicYear->id . '-2026-11')
            ->first();

        // Standing 20% concession repeated
        $this->assertSame('4000.00', $novChallan->total_payable);
        $this->assertSame('1000.00', $novChallan->discount_amount);
        // One-time adjustment from October did NOT leak or repeat
        $this->assertSame('0.00', (string) $novChallan->adjustment_amount);

        // 6. Run December cycle: standing concession continues to apply
        $decResult = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-12-01'));
        $decChallan = FeeChallan::where('student_id', $this->student1->id)
            ->where('billing_period_key', 'AY' . $this->academicYear->id . '-2026-12')
            ->first();
        $this->assertSame('4000.00', $decChallan->total_payable);
    }

    public function test_assigned_optional_fee_recurs_while_unassigned_optional_fee_does_not_bill(): void
    {
        // Student 1: mandatory tuition + optional transport
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [$this->optionalTransport->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-10-01')
        );

        // Student 2: mandatory tuition only (transport NOT selected)
        StudentFeeAssignmentService::initializeForStudent(
            $this->student2,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-10-01')
        );

        // Run November cycle
        RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-01'));

        $challan1 = FeeChallan::where('student_id', $this->student1->id)->where('billing_period_key', 'like', '%2026-11')->first();
        $challan2 = FeeChallan::where('student_id', $this->student2->id)->where('billing_period_key', 'like', '%2026-11')->first();

        // Student 1 has tuition (5,000) + transport (2,000) = 7,000
        $this->assertSame('7000.00', $challan1->total_payable);
        $this->assertCount(2, $challan1->items);

        // Student 2 has only tuition (5,000)
        $this->assertSame('5000.00', $challan2->total_payable);
        $this->assertCount(1, $challan2->items);
    }

    public function test_previously_billed_one_time_fee_never_auto_repeats(): void
    {
        // Student 1 assigned tuition + one-time admission fee
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [$this->oneTimeAdmission->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-10-01')
        );

        // October admission challan bills tuition + admission fee
        $octChallan = FeeBillingService::createChallan($this->student1, $this->academicYear, Carbon::parse('2026-10-01'));
        $this->assertSame('15000.00', $octChallan->total_payable); // 5000 + 10000

        // November recurring cycle runs
        $novResult = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-01'));
        $novChallan = FeeChallan::where('student_id', $this->student1->id)->where('billing_period_key', 'like', '%2026-11')->first();

        // November challan contains ONLY monthly tuition (5,000), one-time admission fee skipped
        $this->assertSame('5000.00', $novChallan->total_payable);
        $this->assertCount(1, $novChallan->items);
        $this->assertSame($this->monthlyTuition->id, $novChallan->items->first()->fee_structure_id);
    }

    public function test_annual_fee_billed_once_in_academic_year_and_subsequent_cycles_skip_it(): void
    {
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [$this->annualResourceFee->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-10-01')
        );

        // October cycle bills tuition (5000) + annual resource (3000) = 8000
        $octChallan = FeeBillingService::createChallan($this->student1, $this->academicYear, Carbon::parse('2026-10-01'));
        $this->assertSame('8000.00', $octChallan->total_payable);

        // November cycle skips annual resource fee because already billed in this AY
        RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-01'));
        $novChallan = FeeChallan::where('student_id', $this->student1->id)->where('billing_period_key', 'like', '%2026-11')->first();
        $this->assertSame('5000.00', $novChallan->total_payable);
    }

    public function test_dynamic_due_date_resolution(): void
    {
        // 1. Setting due day = 10
        RecurringFeeBillingService::saveSchoolSettings($this->school->id, [
            'auto_billing_enabled'  => true,
            'auto_billing_day'      => 1,
            'recurring_fee_due_day' => 10,
        ]);

        $dueNov = RecurringFeeBillingService::resolveRecurringDueDate(Carbon::parse('2026-11-01'), 10);
        $this->assertSame('2026-11-10', $dueNov->toDateString());

        $dueDec = RecurringFeeBillingService::resolveRecurringDueDate(Carbon::parse('2026-12-01'), 10);
        $this->assertSame('2026-12-10', $dueDec->toDateString());

        // 2. When cycle executes, challan inherits the resolved due date
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-01'));
        $challan = FeeChallan::where('student_id', $this->student1->id)->where('billing_period_key', 'like', '%2026-11')->first();
        $this->assertSame('2026-11-10', $challan->due_date->toDateString());
    }

    public function test_duplicate_safety_across_commands_jobs_and_manual_challans(): void
    {
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        // 1. First execution creates challan
        $result1 = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-01'));
        $this->assertSame('completed', $result1['status']);
        $this->assertSame(1, $result1['challans_created']);

        // 2. Second execution via service returns already_completed with 0 created
        $result2 = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-01'));
        $this->assertSame('already_completed', $result2['status']);

        // 3. Queued job execution returns cleanly without duplicating
        $job = new ProcessSchoolRecurringBillingJob($this->school->id, '2026-11');
        $job->handle();

        // 4. Database unique constraints ensure exactly 1 challan exists
        $challans = FeeChallan::where('student_id', $this->student1->id)
            ->where('billing_period_key', 'AY' . $this->academicYear->id . '-2026-11')
            ->get();
        $this->assertCount(1, $challans);

        // 5. Fee billing runs table has exactly 1 audit record
        $runs = FeeBillingRun::where('school_id', $this->school->id)->where('target_month', '2026-11')->get();
        $this->assertCount(1, $runs);
        $this->assertSame('completed', $runs->first()->status);
    }

    public function test_tenant_safety_and_failure_isolation(): void
    {
        // Student in School A
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        // Student in School B
        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'name' => 'Grade 7', 'numeric_name' => 7]);
        $studentB = Student::create([
            'school_id'    => $this->schoolB->id,
            'class_id'     => $classB->id,
            'admission_no' => 'ADM-B-001',
            'first_name'   => 'Zain',
            'last_name'    => 'Abbas',
            'status'       => 'active',
        ]);
        $tuitionB = FeeStructure::create([
            'school_id'                => $this->schoolB->id,
            'class_id'                 => $classB->id,
            'fee_category_id'          => FeeCategory::create(['school_id' => $this->schoolB->id, 'name' => 'Tuition'])->id,
            'academic_year'            => '2026-2027',
            'amount'                   => '7000.00',
            'frequency'                => 'monthly',
            'is_active'                => true,
            'is_optional'              => false,
            'admission_voucher_policy' => 'required',
        ]);
        StudentFeeAssignmentService::initializeForStudent(
            $studentB,
            $this->academicYearB,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        // Running School A cycle
        RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-01'));

        // School A challans strictly belong to School A
        $this->assertCount(1, FeeChallan::where('school_id', $this->school->id)->get());
        $this->assertCount(0, FeeChallan::where('school_id', $this->schoolB->id)->get());

        // Running School B cycle completes independently
        RecurringFeeBillingService::executeBillingCycle($this->schoolB->id, Carbon::parse('2026-11-01'));
        $this->assertCount(1, FeeChallan::where('school_id', $this->schoolB->id)->get());
    }

    public function test_scheduler_eligibility_and_missed_day_catch_up(): void
    {
        // 1. School disabled -> not eligible
        $this->assertFalse(RecurringFeeBillingService::isSchoolEligibleForCycle($this->school, Carbon::parse('2026-11-01 10:00:00')));

        // 2. Enable school with billing day = 5
        RecurringFeeBillingService::saveSchoolSettings($this->school->id, [
            'auto_billing_enabled'  => true,
            'auto_billing_day'      => 5,
            'recurring_fee_due_day' => 15,
        ]);

        // Day 4 (before billing day) -> not eligible
        $this->assertFalse(RecurringFeeBillingService::isSchoolEligibleForCycle($this->school, Carbon::parse('2026-11-04 10:00:00')));

        // Day 5 (exact billing day) -> eligible!
        $this->assertTrue(RecurringFeeBillingService::isSchoolEligibleForCycle($this->school, Carbon::parse('2026-11-05 10:00:00')));

        // Day 8 (missed exact billing day catch-up) -> eligible!
        $this->assertTrue(RecurringFeeBillingService::isSchoolEligibleForCycle($this->school, Carbon::parse('2026-11-08 10:00:00')));

        // After billing completes on Day 8, subsequent checks on Day 9 -> NOT eligible (already billed)
        RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-01'));
        $this->assertFalse(RecurringFeeBillingService::isSchoolEligibleForCycle($this->school, Carbon::parse('2026-11-09 10:00:00')));
    }

    public function test_dry_run_creates_zero_business_data_mutations(): void
    {
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        $initialChallans = FeeChallan::count();
        $initialItems = FeeChallanItem::count();
        $initialRuns = FeeBillingRun::count();

        $preview = RecurringFeeBillingService::executeBillingCycle(
            $this->school->id,
            Carbon::parse('2026-11-01'),
            'manual',
            true // dryRun = true
        );

        $this->assertSame(1, $preview['eligible_students_count']);
        $this->assertSame(1, $preview['expected_challans_count']);
        $this->assertSame('5000.00', $preview['expected_gross_amount']);

        // Assert ZERO database mutations
        $this->assertSame($initialChallans, FeeChallan::count());
        $this->assertSame($initialItems, FeeChallanItem::count());
        $this->assertSame($initialRuns, FeeBillingRun::count());
    }

    public function test_artisan_command_execution(): void
    {
        Queue::fake();

        RecurringFeeBillingService::saveSchoolSettings($this->school->id, [
            'auto_billing_enabled'  => true,
            'auto_billing_day'      => 1,
            'recurring_fee_due_day' => 10,
        ]);

        // Run command with explicit school and month
        $this->artisan('fees:run-billing-cycle', [
            '--school' => $this->school->id,
            '--month'  => '2026-11',
        ])->assertSuccessful();

        Queue::assertPushed(ProcessSchoolRecurringBillingJob::class, function ($job) {
            return $job->schoolId === $this->school->id && $job->targetMonth === '2026-11';
        });
    }

    public function test_web_endpoints_access_and_preview(): void
    {
        $this->actingAs($this->adminUser);

        // 1. Save settings
        $response = $this->post(route('school.fees.recurring-billing.settings'), [
            'auto_billing_enabled'  => 1,
            'auto_billing_day'      => 3,
            'recurring_fee_due_day' => 12,
        ]);
        $response->assertRedirect();

        $settings = RecurringFeeBillingService::getSchoolSettings($this->school->id);
        $this->assertTrue($settings['auto_billing_enabled']);
        $this->assertSame(3, $settings['auto_billing_day']);
        $this->assertSame(12, $settings['recurring_fee_due_day']);

        // 2. Request preview JSON
        $previewResp = $this->postJson(route('school.fees.recurring-billing.preview'), [
            'month' => '2026-11',
        ]);
        $previewResp->assertOk();
        $previewResp->assertJsonStructure([
            'school_id', 'target_month', 'eligible_students_count', 'expected_challans_count', 'expected_gross_amount',
        ]);
    }

    /**
     * Requirement 4: Exact Same-Month Late Admission Catch-Up Test
     */
    public function test_exact_same_month_late_admission_catch_up_scenario(): void
    {
        // Nov 5: Student 1 has monthly assignment starting Oct/Nov
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-10-01')
        );

        // Nov 5: Initial recurring run executes successfully
        $run1 = RecurringFeeBillingService::executeBillingCycle(
            $this->school->id,
            Carbon::parse('2026-11-05'),
            'manual'
        );

        $this->assertSame('completed', $run1['status']);
        $this->assertSame(1, $run1['challans_created']);
        $this->assertSame(1, FeeChallan::where('student_id', $this->student1->id)->where('billing_period_key', 'AY' . $this->academicYear->id . '-2026-11')->count());

        // Nov 12: Student 2 is newly admitted and assigned fee starting Nov 12
        StudentFeeAssignmentService::initializeForStudent(
            $this->student2,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-12')
        );

        // Nov 12: Admin clicks Run Now for November again
        $run2 = RecurringFeeBillingService::executeBillingCycle(
            $this->school->id,
            Carbon::parse('2026-11-12'),
            'manual'
        );

        $this->assertSame('completed', $run2['status']);
        $this->assertSame(1, $run2['challans_created']); // Exactly 1 new voucher created by catch-up
        $this->assertSame(1, $run2['challans_skipped']); // Student 1 safely skipped
        $this->assertSame(2, $run2['cumulative_challans']);
        $this->assertSame(1, FeeChallan::where('student_id', $this->student1->id)->where('billing_period_key', 'AY' . $this->academicYear->id . '-2026-11')->count());
        $this->assertSame(1, FeeChallan::where('student_id', $this->student2->id)->where('billing_period_key', 'AY' . $this->academicYear->id . '-2026-11')->count());

        // Run Now a third time: Both students remain 1, new challans created = 0
        $run3 = RecurringFeeBillingService::executeBillingCycle(
            $this->school->id,
            Carbon::parse('2026-11-15'),
            'manual'
        );

        $this->assertSame('already_completed', $run3['status']);
        $this->assertSame(0, $run3['challans_created']); // Zero new vouchers
        $this->assertSame(2, $run3['challans_skipped']);
        $this->assertSame(2, $run3['cumulative_challans']);
        $this->assertSame(1, FeeChallan::where('student_id', $this->student1->id)->where('billing_period_key', 'AY' . $this->academicYear->id . '-2026-11')->count());
        $this->assertSame(1, FeeChallan::where('student_id', $this->student2->id)->where('billing_period_key', 'AY' . $this->academicYear->id . '-2026-11')->count());
    }

    /**
     * Requirement 5 & 6: Automatic Scheduler Catch-Up & No Wasted Hourly Rescans
     */
    public function test_automatic_scheduler_catch_up_detects_unbilled_students_without_wasted_rescans(): void
    {
        RecurringFeeBillingService::saveSchoolSettings($this->school->id, [
            'auto_billing_enabled'  => true,
            'auto_billing_day'      => 5,
            'recurring_fee_due_day' => 15,
        ]);

        // Student 1 initialized
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-10-01')
        );

        // Nov 5: Automatic billing executes
        $this->assertTrue(RecurringFeeBillingService::isSchoolEligibleForCycle($this->school, Carbon::parse('2026-11-05 01:00:00')));
        RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-05'));
        $this->assertCount(1, FeeChallan::where('school_id', $this->school->id)->get());

        // Nov 6: Scheduler runs hourly -> NOT eligible (no unbilled students exist, no wasted rescans)
        $this->assertFalse(RecurringFeeBillingService::isSchoolEligibleForCycle($this->school, Carbon::parse('2026-11-06 01:00:00')));

        // Nov 12: New student admitted with monthly assignment
        StudentFeeAssignmentService::initializeForStudent(
            $this->student2,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-12')
        );

        // Nov 12: Scheduler evaluates school -> ELIGIBLE! (unbilled student detected)
        $this->assertTrue(RecurringFeeBillingService::isSchoolEligibleForCycle($this->school, Carbon::parse('2026-11-12 02:00:00')));

        // Queue job executes catch-up
        $job = new ProcessSchoolRecurringBillingJob($this->school->id, '2026-11', 'scheduler');
        $job->handle();

        // Exactly 2 total challans exist now (1 for Student 1, 1 for Student 2)
        $this->assertCount(2, FeeChallan::where('school_id', $this->school->id)->get());
        $this->assertSame(1, FeeChallan::where('student_id', $this->student1->id)->count());
        $this->assertSame(1, FeeChallan::where('student_id', $this->student2->id)->count());

        // Subsequent scheduler check on Nov 13 -> NOT eligible (all students satisfied)
        $this->assertFalse(RecurringFeeBillingService::isSchoolEligibleForCycle($this->school, Carbon::parse('2026-11-13 01:00:00')));
    }

    /**
     * Requirement 7: Manual Run Now Before Auto Billing Day
     */
    public function test_manual_run_now_before_billing_day_and_subsequent_automatic_scheduler(): void
    {
        RecurringFeeBillingService::saveSchoolSettings($this->school->id, [
            'auto_billing_enabled'  => true,
            'auto_billing_day'      => 5,
            'recurring_fee_due_day' => 15,
        ]);

        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-10-01')
        );

        // Nov 1: Admin manually executes Run Now
        $nov1Result = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-01'), 'manual');
        $this->assertSame(1, $nov1Result['challans_created']);

        // Nov 4: Student 2 becomes eligible
        StudentFeeAssignmentService::initializeForStudent(
            $this->student2,
            $this->academicYear,
            [],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-04')
        );

        // Nov 5: Auto billing day arrives -> Scheduler detects Student 2 as unbilled and runs
        $this->assertTrue(RecurringFeeBillingService::isSchoolEligibleForCycle($this->school, Carbon::parse('2026-11-05 01:00:00')));

        $nov5Result = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-05'), 'scheduler');
        $this->assertSame(1, $nov5Result['challans_created']); // Only Student 2 billed

        // Verify Student 1 has 1 voucher and Student 2 has 1 voucher
        $this->assertSame(1, FeeChallan::where('student_id', $this->student1->id)->count());
        $this->assertSame(1, FeeChallan::where('student_id', $this->student2->id)->count());
    }

    /**
     * Requirement 11: Phase 5B Fee Structure Safety Rules
     */
    public function test_phase_5b_fee_structure_deactivation_and_soft_delete_safety(): void
    {
        // Create structures initially active
        $inactiveCat = FeeCategory::create(['school_id' => $this->school->id, 'name' => 'Lab Fee']);
        $inactiveStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'academic_year'            => $this->academicYear->name,
            'fee_category_id'          => $inactiveCat->id,
            'amount'                   => '1200.00',
            'frequency'                => 'monthly',
            'is_optional'              => true,
            'is_active'                => true,
            'admission_voucher_policy' => 'optional',
        ]);

        $deletedCat = FeeCategory::create(['school_id' => $this->school->id, 'name' => 'Sports Fee']);
        $deletedStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'academic_year'            => $this->academicYear->name,
            'fee_category_id'          => $deletedCat->id,
            'amount'                   => '800.00',
            'frequency'                => 'monthly',
            'is_optional'              => true,
            'is_active'                => true,
            'admission_voucher_policy' => 'optional',
        ]);

        // Assign structures to Student 1
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [$inactiveStructure->id, $deletedStructure->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        // Administrator later deactivates one structure and soft-deletes the other (Phase 5B lifecycle)
        $inactiveStructure->update(['is_active' => false]);
        $deletedStructure->delete();

        // Execute recurring billing cycle
        RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-01'));

        $challan = FeeChallan::where('student_id', $this->student1->id)->first();
        $this->assertNotNull($challan);
        // Only active monthly tuition (5000.00) is billed; inactive (1200) and soft-deleted (800) are skipped
        $this->assertSame('5000.00', $challan->total_payable);
        $this->assertCount(1, $challan->items);
        $this->assertSame($this->monthlyTuition->id, $challan->items->first()->fee_structure_id);
    }

    /**
     * Matrix A: Tuition Nov billed -> late Transport Nov added -> catch-up bills Transport without duplicating Tuition
     */
    public function test_same_student_late_assignment_appends_to_existing_challan_without_duplication(): void
    {
        // 1. Initial assignment: Student 1 has Monthly Tuition
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [$this->monthlyTuition->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        // Nov 5: Initial recurring run
        $run1 = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-05'));
        $this->assertSame(1, $run1['challans_created']);
        $this->assertSame('5000.00', $run1['total_amount']);

        $challanAfterRun1 = FeeChallan::where('student_id', $this->student1->id)->first();
        $this->assertNotNull($challanAfterRun1);
        $this->assertSame('5000.00', $challanAfterRun1->total_payable);
        $this->assertCount(1, $challanAfterRun1->items);
        $this->assertSame('Tuition Fee', $challanAfterRun1->items->first()->fee_head_name);

        // 2. Nov 12: SAME Student 1 receives a NEW Monthly Transport assignment with starts_on = Nov 12
        StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student1->id,
            'academic_year_id' => $this->academicYear->id,
            'fee_structure_id' => $this->optionalTransport->id,
            'is_active'        => true,
            'starts_on'        => '2026-11-12',
            'assigned_by'      => $this->adminUser->id,
            'assigned_at'      => now(),
        ]);

        // Scheduler eligibility should detect the unbilled Transport charge!
        $this->assertTrue(RecurringFeeBillingService::hasUnbilledEligibleStudents($this->school, $this->academicYear, Carbon::parse('2026-11-12')));

        // 3. Nov 12: Admin clicks "Run Now" for November again
        $run2 = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-12'), 'manual');
        $this->assertSame(0, $run2['new_challans_created']);
        $this->assertSame(1, $run2['vouchers_updated']);
        $this->assertSame('2000.00', $run2['total_amount']); // Only appended amount generated in this run
        $this->assertSame('7000.00', $run2['cumulative_total_amount']);
        $this->assertSame(1, $run2['cumulative_challans']); // Exactly 1 new voucher cumulative
        $this->assertSame(1, $run2['cumulative_vouchers_updated']);
        $this->assertSame(2, $run2['execution_count']);

        // Check challan: Exactly ONE challan exists for Student 1 for November
        $allChallans = FeeChallan::where('student_id', $this->student1->id)->get();
        $this->assertCount(1, $allChallans, 'Student must not have two challans for the same month');

        $challanAfterRun2 = $allChallans->first();
        $this->assertSame('7000.00', $challanAfterRun2->gross_amount);
        $this->assertSame('7000.00', $challanAfterRun2->total_payable);
        $this->assertCount(2, $challanAfterRun2->items);

        // Verify items: Tuition NOT duplicated, Transport billed exactly once
        $tuitionItems = $challanAfterRun2->items()->where('fee_structure_id', $this->monthlyTuition->id)->get();
        $transportItems = $challanAfterRun2->items()->where('fee_structure_id', $this->optionalTransport->id)->get();
        $this->assertCount(1, $tuitionItems, 'Tuition must not be duplicated');
        $this->assertCount(1, $transportItems, 'Transport must be billed exactly once');

        // 4. Third run for November: Everything is fully billed
        $this->assertFalse(RecurringFeeBillingService::hasUnbilledEligibleStudents($this->school, $this->academicYear, Carbon::parse('2026-11-15')));
        $run3 = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-15'), 'scheduler');
        $this->assertSame(0, $run3['challans_created']);
        $this->assertSame('already_completed', $run3['status']);
        $this->assertSame('7000.00', $run3['cumulative_total_amount']);
        $this->assertSame(1, $run3['cumulative_challans']);
        $this->assertSame(1, $run3['cumulative_vouchers_updated']);
        $this->assertSame(3, $run3['execution_count']);
    }

    /**
     * Matrix B, C, D, E: Frequency-aware eligibility and scheduler invariants
     */
    public function test_frequency_aware_eligibility_and_scheduler_invariants_matrix(): void
    {
        // Student 1 has Tuition billed for November
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [$this->monthlyTuition->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );
        RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-05'));

        // Test B: Inactive structure added
        $inactiveStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'academic_year'            => $this->academicYear->name,
            'fee_category_id'          => $this->tuitionCat->id,
            'amount'                   => '1500.00',
            'frequency'                => 'monthly',
            'is_active'                => false, // INACTIVE
            'admission_voucher_policy' => 'optional',
        ]);
        StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student1->id,
            'academic_year_id' => $this->academicYear->id,
            'fee_structure_id' => $inactiveStructure->id,
            'is_active'        => true,
            'starts_on'        => '2026-11-10',
            'assigned_by'      => $this->adminUser->id,
            'assigned_at'      => now(),
        ]);
        $this->assertFalse(
            RecurringFeeBillingService::hasUnbilledEligibleStudents($this->school, $this->academicYear, Carbon::parse('2026-11-10')),
            'Inactive fee structure must not cause scheduler to wake up'
        );

        // Test C: One-time structure added that was already billed
        $oneTimeItem = FeeChallanItem::create([
            'fee_challan_id'    => FeeChallan::where('student_id', $this->student1->id)->first()->id,
            'school_id'         => $this->school->id,
            'student_id'        => $this->student1->id,
            'fee_structure_id'  => $this->oneTimeAdmission->id,
            'charge_period_key' => 'ONETIME',
            'active_charge_key' => "{$this->student1->id}_{$this->oneTimeAdmission->id}_ONETIME",
            'fee_head_name'     => 'Admission Fee',
            'gross_amount'      => '10000.00',
            'discount_amount'   => '0.00',
            'net_amount'        => '10000.00',
        ]);
        StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student1->id,
            'academic_year_id' => $this->academicYear->id,
            'fee_structure_id' => $this->oneTimeAdmission->id,
            'is_active'        => true,
            'starts_on'        => '2026-11-10',
            'assigned_by'      => $this->adminUser->id,
            'assigned_at'      => now(),
        ]);
        $this->assertFalse(
            RecurringFeeBillingService::hasUnbilledEligibleStudents($this->school, $this->academicYear, Carbon::parse('2026-11-10')),
            'One-time fee must not trigger recurring auto-billing'
        );

        // Test D: Quarterly structure that is NOT DUE in November (already billed for Q1 in October)
        $quarterlyCat = FeeCategory::create(['school_id' => $this->school->id, 'name' => 'Lab Term Fee']);
        $quarterlyStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'academic_year'            => $this->academicYear->name,
            'fee_category_id'          => $quarterlyCat->id,
            'amount'                   => '3000.00',
            'frequency'                => 'quarterly',
            'is_active'                => true,
            'admission_voucher_policy' => 'optional',
        ]);
        $q1ChargeKey = FeeBillingService::buildChargePeriodKey($quarterlyStructure, $this->academicYear, Carbon::parse('2026-11-01'));
        // Simulate that Q1 was already billed
        FeeChallanItem::create([
            'fee_challan_id'    => FeeChallan::where('student_id', $this->student1->id)->first()->id,
            'school_id'         => $this->school->id,
            'student_id'        => $this->student1->id,
            'fee_structure_id'  => $quarterlyStructure->id,
            'charge_period_key' => $q1ChargeKey,
            'active_charge_key' => "{$this->student1->id}_{$quarterlyStructure->id}_{$q1ChargeKey}",
            'fee_head_name'     => 'Lab Term Fee',
            'gross_amount'      => '3000.00',
            'discount_amount'   => '0.00',
            'net_amount'        => '3000.00',
        ]);
        StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student1->id,
            'academic_year_id' => $this->academicYear->id,
            'fee_structure_id' => $quarterlyStructure->id,
            'is_active'        => true,
            'starts_on'        => '2026-11-01',
            'assigned_by'      => $this->adminUser->id,
            'assigned_at'      => now(),
        ]);
        $this->assertFalse(
            RecurringFeeBillingService::hasUnbilledEligibleStudents($this->school, $this->academicYear, Carbon::parse('2026-11-10')),
            'Quarterly fee already billed for this quarter must not cause scheduler to wake up'
        );

        // Test E: Quarterly structure DUE in November (never billed for Q1)
        $quarterlyDueCat = FeeCategory::create(['school_id' => $this->school->id, 'name' => 'Sports Term Fee']);
        $quarterlyDueStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'academic_year'            => $this->academicYear->name,
            'fee_category_id'          => $quarterlyDueCat->id,
            'amount'                   => '4000.00',
            'frequency'                => 'quarterly',
            'is_active'                => true,
            'admission_voucher_policy' => 'optional',
        ]);
        StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student1->id,
            'academic_year_id' => $this->academicYear->id,
            'fee_structure_id' => $quarterlyDueStructure->id,
            'is_active'        => true,
            'starts_on'        => '2026-11-01',
            'assigned_by'      => $this->adminUser->id,
            'assigned_at'      => now(),
        ]);

        // Must be eligible!
        $this->assertTrue(
            RecurringFeeBillingService::hasUnbilledEligibleStudents($this->school, $this->academicYear, Carbon::parse('2026-11-10')),
            'Unbilled quarterly fee due in current quarter must trigger scheduler eligibility'
        );

        // Run catch-up: bills the quarterly fee
        $catchUp = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-10'));
        $this->assertSame(0, $catchUp['new_challans_created']);
        $this->assertSame(1, $catchUp['vouchers_updated']);
        $this->assertSame('4000.00', $catchUp['total_amount']);

        // Now fully billed: scheduler eligibility returns false
        $this->assertFalse(
            RecurringFeeBillingService::hasUnbilledEligibleStudents($this->school, $this->academicYear, Carbon::parse('2026-11-10'))
        );
    }

    /**
     * Matrix F: Tuition Nov billed -> standing concession exists -> late Transport Nov added -> concession correctly applied/preserved
     */
    public function test_standing_concession_preserved_when_late_fee_appended(): void
    {
        // Student 1 has Tuition (5,000) and a standing 20% discount on Tuition category
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [$this->monthlyTuition->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        StudentFeeDiscount::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student1->id,
            'fee_category_id'  => $this->tuitionCat->id,
            'title'            => 'Merit 20% Tuition Concession',
            'type'             => 'percentage',
            'value'            => 20.00,
            'is_active'        => true,
        ]);

        // Nov 5: Initial run bills 5,000 - 20% (1,000) = 4,000
        $run1 = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-05'));
        $this->assertSame(1, $run1['challans_created']);
        $this->assertSame('4000.00', $run1['total_amount']);

        $challan = FeeChallan::where('student_id', $this->student1->id)->first();
        $this->assertSame('4000.00', $challan->total_payable);
        $this->assertSame('1000.00', $challan->discount_amount);

        // Nov 12: Assign late Transport (2,000) with starts_on = Nov 12
        StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student1->id,
            'academic_year_id' => $this->academicYear->id,
            'fee_structure_id' => $this->optionalTransport->id,
            'is_active'        => true,
            'starts_on'        => '2026-11-12',
            'assigned_by'      => $this->adminUser->id,
            'assigned_at'      => now(),
        ]);

        // Run catch-up
        $run2 = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-12'));
        $this->assertSame(0, $run2['new_challans_created']);
        $this->assertSame(1, $run2['vouchers_updated']);
        $this->assertSame('2000.00', $run2['total_amount']); // Transport is full 2,000 because concession is Tuition-only
        $this->assertSame('6000.00', $run2['cumulative_total_amount']); // 4,000 + 2,000

        $updatedChallan = FeeChallan::where('student_id', $this->student1->id)->first();
        $this->assertSame('7000.00', $updatedChallan->gross_amount); // 5000 + 2000
        $this->assertSame('1000.00', $updatedChallan->discount_amount); // 1000 concession preserved
        $this->assertSame('6000.00', $updatedChallan->total_payable);
    }

    /**
     * Cross-Workflow Partial Coverage: Bulk partial -> Recurring; Admission partial -> Recurring; Recurring -> Bulk
     */
    public function test_cross_workflow_partial_coverage(): void
    {
        // Part 1: Bulk partial -> Recurring
        // Student 1 has Tuition (5,000) and Transport (2,000) assigned
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [$this->monthlyTuition->id, $this->optionalTransport->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        // Admin bills ONLY Tuition via Bulk Assign in November
        $bulkResult = \App\Services\FeeBulkAssignService::execute(
            $this->school->id,
            $this->monthlyTuition->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'selected_students',
                'execution_mode' => 'assign_and_bill',
                'issue_date'     => '2026-11-01',
                'billing_label'  => 'November 2026 Tuition',
                'student_ids'    => [$this->student1->id],
            ],
            $this->adminUser
        );
        $this->assertSame(1, $bulkResult['vouchers_generated']);

        // Now Recurring Billing runs for November: should generate ONLY Transport without duplicating Tuition!
        $this->assertTrue(RecurringFeeBillingService::hasUnbilledEligibleStudents($this->school, $this->academicYear, Carbon::parse('2026-11-05')));
        $recResult = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-05'));
        $this->assertSame(0, $recResult['new_challans_created']);
        $this->assertSame(1, $recResult['vouchers_updated']);
        $this->assertSame('2000.00', $recResult['total_amount']);

        $challan1 = FeeChallan::where('student_id', $this->student1->id)->first();
        $this->assertCount(2, $challan1->items);
        $this->assertSame('7000.00', $challan1->total_payable);

        // Part 2: Admission partial -> Recurring
        // Student 2 is admitted in November with Admission Voucher (Registration + Admission Fee only)
        $this->monthlyTuition->update(['admission_voucher_policy' => 'optional']);
        StudentFeeAssignmentService::initializeForStudent(
            $this->student2,
            $this->academicYear,
            [$this->monthlyTuition->id, $this->oneTimeAdmission->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        // Issue admission challan with ONLY one-time admission fee
        $admissionChallan = FeeBillingService::createAdmissionChallan(
            $this->student2,
            $this->academicYear,
            Carbon::parse('2026-11-01'),
            [$this->oneTimeAdmission->id]
        );
        $this->assertNotNull($admissionChallan);
        $this->assertSame('10000.00', $admissionChallan->total_payable);

        // Recurring billing runs for November: Tuition must be billed, Admission fee NOT duplicated!
        $this->assertTrue(RecurringFeeBillingService::hasUnbilledEligibleStudents($this->school, $this->academicYear, Carbon::parse('2026-11-10')));
        $recResult2 = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-10'));
        $this->assertSame(0, $recResult2['new_challans_created']);
        $this->assertSame(1, $recResult2['vouchers_updated']);
        $this->assertSame('5000.00', $recResult2['total_amount']);

        $student2Challan = FeeChallan::where('student_id', $this->student2->id)->first();
        $this->assertSame('15000.00', $student2Challan->total_payable); // 10000 + 5000
        $this->assertCount(2, $student2Challan->items);

        // Part 3: Recurring -> Bulk missing charge
        // Admin uses Bulk Assign to bill Lab Fee for class
        $labCat = FeeCategory::create(['school_id' => $this->school->id, 'name' => 'Lab Maintenance']);
        $labStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'academic_year'            => $this->academicYear->name,
            'fee_category_id'          => $labCat->id,
            'amount'                   => '1500.00',
            'frequency'                => 'monthly',
            'is_optional'              => true,
            'is_active'                => true,
            'admission_voucher_policy' => 'optional',
        ]);

        $bulkResult2 = \App\Services\FeeBulkAssignService::execute(
            $this->school->id,
            $labStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'selected_students',
                'execution_mode' => 'assign_and_bill',
                'issue_date'     => '2026-11-01',
                'student_ids'    => [$this->student1->id],
            ],
            $this->adminUser
        );
        $this->assertSame(0, $bulkResult2['vouchers_generated']);
        $this->assertSame(1, $bulkResult2['vouchers_updated']);

        // Verify Student 1 still has exactly 1 challan, now with 3 items (5000 + 2000 + 1500 = 8500)
        $this->assertSame(1, FeeChallan::where('student_id', $this->student1->id)->count());
        $finalChallan1 = FeeChallan::where('student_id', $this->student1->id)->first();
        $this->assertSame('8500.00', $finalChallan1->total_payable);
        $this->assertCount(3, $finalChallan1->items);
    }

    /**
     * Requirement 8: Audit Run Counters Semantics
     */
    public function test_audit_run_counters_semantics(): void
    {
        // Create 10 students
        $students = [];
        for ($i = 1; $i <= 10; $i++) {
            $student = Student::create([
                'school_id'       => $this->school->id,
                'class_id'        => $this->class->id,
                'admission_no'    => "ST-RUN-{$i}",
                'first_name'      => "CounterStudent",
                'last_name'       => "#{$i}",
                'gender'          => 'male',
                'status'          => 'active',
                'admission_date'  => '2026-09-01',
            ]);
            $students[] = $student;

            // 8 students have Tuition (5,000) starting Nov 1
            if ($i <= 8) {
                StudentFeeAssignment::create([
                    'school_id'        => $this->school->id,
                    'student_id'       => $student->id,
                    'academic_year_id' => $this->academicYear->id,
                    'fee_structure_id' => $this->monthlyTuition->id,
                    'is_active'        => true,
                    'starts_on'        => '2026-11-01',
                    'assigned_by'      => $this->adminUser->id,
                    'assigned_at'      => now(),
                ]);
            }
        }

        // Student 9 and 10 also get Tuition starting Nov 1, giving 10 initial eligible students
        for ($i = 8; $i < 10; $i++) {
            StudentFeeAssignment::create([
                'school_id'        => $this->school->id,
                'student_id'       => $students[$i]->id,
                'academic_year_id' => $this->academicYear->id,
                'fee_structure_id' => $this->monthlyTuition->id,
                'is_active'        => true,
                'starts_on'        => '2026-11-01',
                'assigned_by'      => $this->adminUser->id,
                'assigned_at'      => now(),
            ]);
        }

        // Initial Run: 10 students created (10 x 5,000 = 50,000.00)
        $run1 = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-05'));
        $this->assertSame(10, $run1['challans_created']);
        $this->assertSame('50000.00', $run1['total_amount']);
        $this->assertSame(1, $run1['execution_count']);

        $dbRun1 = FeeBillingRun::where('school_id', $this->school->id)->where('target_month', '2026-11')->first();
        $this->assertSame(10, $dbRun1->challans_created);
        $this->assertSame(10, $dbRun1->last_challans_created);
        $this->assertSame(1, $dbRun1->execution_count);
        $this->assertSame('50000.00', $dbRun1->total_amount);
        $this->assertSame(10, $dbRun1->students_scanned);

        // Catch-up: Student 1 and Student 2 receive a late Transport assignment (2,000 each)
        for ($i = 0; $i < 2; $i++) {
            StudentFeeAssignment::create([
                'school_id'        => $this->school->id,
                'student_id'       => $students[$i]->id,
                'academic_year_id' => $this->academicYear->id,
                'fee_structure_id' => $this->optionalTransport->id,
                'is_active'        => true,
                'starts_on'        => '2026-11-12',
                'assigned_by'      => $this->adminUser->id,
                'assigned_at'      => now(),
            ]);
        }

        // Catch-up Run: 0 new challans, 2 vouchers updated (2 x 2,000 = 4,000.00 appended)
        $run2 = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-12'));
        $this->assertSame(0, $run2['new_challans_created']);
        $this->assertSame(2, $run2['vouchers_updated']);
        $this->assertSame(2, $run2['charges_appended']);
        $this->assertSame('4000.00', $run2['total_amount']);
        $this->assertSame(10, $run2['cumulative_challans']); // Genuinely new vouchers stays 10
        $this->assertSame(2, $run2['cumulative_vouchers_updated']);
        $this->assertSame('54000.00', $run2['cumulative_total_amount']);
        $this->assertSame(2, $run2['execution_count']);

        $dbRun2 = FeeBillingRun::where('school_id', $this->school->id)->where('target_month', '2026-11')->first();
        $this->assertSame(10, $dbRun2->challans_created); // Truthful: 10 new vouchers
        $this->assertSame(2, $dbRun2->vouchers_updated); // Truthful: 2 vouchers updated
        $this->assertSame(2, $dbRun2->charges_appended);
        $this->assertSame(0, $dbRun2->last_challans_created);
        $this->assertSame(2, $dbRun2->last_vouchers_updated);
        $this->assertSame(2, $dbRun2->execution_count);
        $this->assertSame('54000.00', $dbRun2->total_amount); // NOT double-counted!
        $this->assertSame(10, $dbRun2->students_scanned);

        // Third Run: 0 created, 0 updated -> already completed
        $run3 = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-15'));
        $this->assertSame(0, $run3['new_challans_created']);
        $this->assertSame(0, $run3['vouchers_updated']);
        $this->assertSame('already_completed', $run3['status']);
        $this->assertSame(10, $run3['cumulative_challans']);
        $this->assertSame(2, $run3['cumulative_vouchers_updated']);
        $this->assertSame('54000.00', $run3['cumulative_total_amount']);
        $this->assertSame(3, $run3['execution_count']);

        $dbRun3 = FeeBillingRun::where('school_id', $this->school->id)->where('target_month', '2026-11')->first();
        $this->assertSame(10, $dbRun3->challans_created); // Cumulative 10 new vouchers
        $this->assertSame(2, $dbRun3->vouchers_updated); // Cumulative 2 vouchers updated
        $this->assertSame(0, $dbRun3->last_challans_created); // Latest run created 0
        $this->assertSame(0, $dbRun3->last_vouchers_updated);
        $this->assertSame(3, $dbRun3->execution_count); // Execution count 3
        $this->assertSame('54000.00', $dbRun3->total_amount); // Exact cumulative amount preserved
        $this->assertNotNull($dbRun3->last_executed_at);
    }

    /**
     * Requirement 10: Late charge on Unpaid, Partial, Paid, and Adjusted Challans
     * Verifies payment row immutability, receipt truthfulness, settlement date semantics,
     * and StudentFinancialLedgerService exact reconciliation.
     */
    public function test_paid_and_partial_challan_integrity_and_ledger_reconciliation(): void
    {
        $this->actingAs($this->adminUser);

        // ---------------------------------------------------------------
        // 1. UNPAID CHALLAN SCENARIO
        // ---------------------------------------------------------------
        StudentFeeAssignmentService::initializeForStudent(
            $this->student1,
            $this->academicYear,
            [$this->monthlyTuition->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        $unpaidRun = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-05'));
        $this->assertSame(1, $unpaidRun['challans_created']);

        $unpaidChallan = FeeChallan::where('student_id', $this->student1->id)->first();
        $this->assertSame('unpaid', $unpaidChallan->status);
        $this->assertSame('5000.00', $unpaidChallan->total_payable);
        $this->assertSame('0.00', $unpaidChallan->paid_amount);
        $this->assertSame('5000.00', $unpaidChallan->balance);

        // Late Transport assigned Nov 12
        StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student1->id,
            'academic_year_id' => $this->academicYear->id,
            'fee_structure_id' => $this->optionalTransport->id,
            'is_active'        => true,
            'starts_on'        => '2026-11-12',
            'assigned_by'      => $this->adminUser->id,
            'assigned_at'      => now(),
        ]);

        $unpaidCatchUp = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-12'));
        $this->assertSame(0, $unpaidCatchUp['new_challans_created']);
        $this->assertSame(1, $unpaidCatchUp['vouchers_updated']);

        $unpaidChallan->refresh();
        $this->assertSame('7000.00', $unpaidChallan->total_payable);
        $this->assertSame('0.00', $unpaidChallan->paid_amount);
        $this->assertSame('7000.00', $unpaidChallan->balance);
        $this->assertSame('unpaid', $unpaidChallan->status);
        $this->assertCount(2, $unpaidChallan->items);

        // ---------------------------------------------------------------
        // 2. PARTIAL CHALLAN SCENARIO
        // ---------------------------------------------------------------
        // Make partial payment of PKR 2,000 on student 1's challan
        $paymentResponse = $this->post(route('school.fees.payments.store'), [
            'student_id'      => $this->student1->id,
            'fee_challan_id'  => $unpaidChallan->id,
            'amount_paid'     => 2000.00,
            'payment_date'    => '2026-11-13',
            'method'          => 'cash',
            'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
        ]);
        $paymentResponse->assertSessionHasNoErrors();

        $unpaidChallan->refresh();
        $this->assertSame('partial', $unpaidChallan->status);
        $this->assertSame('2000.00', $unpaidChallan->paid_amount);
        $this->assertSame('5000.00', $unpaidChallan->balance); // 7000 - 2000

        // Create a new monthly structure (e.g. Science Lab)
        $scienceCat = FeeCategory::create(['school_id' => $this->school->id, 'name' => 'Science Lab']);
        $scienceLab = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'academic_year'            => $this->academicYear->name,
            'fee_category_id'          => $scienceCat->id,
            'amount'                   => '1000.00',
            'frequency'                => 'monthly',
            'is_optional'              => true,
            'is_active'                => true,
            'admission_voucher_policy' => 'optional',
        ]);

        StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student1->id,
            'academic_year_id' => $this->academicYear->id,
            'fee_structure_id' => $scienceLab->id,
            'is_active'        => true,
            'starts_on'        => '2026-11-14',
            'assigned_by'      => $this->adminUser->id,
            'assigned_at'      => now(),
        ]);

        // Catch-up runs on partial challan
        $partialCatchUp = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-14'));
        $this->assertSame(0, $partialCatchUp['new_challans_created']);
        $this->assertSame(1, $partialCatchUp['vouchers_updated']);
        $this->assertSame('1000.00', $partialCatchUp['total_amount']);

        $unpaidChallan->refresh();
        $this->assertSame('8000.00', $unpaidChallan->total_payable); // 7000 + 1000
        $this->assertSame('2000.00', $unpaidChallan->paid_amount); // Paid amount strictly untouched
        $this->assertSame('6000.00', $unpaidChallan->balance); // 8000 - 2000 = 6000
        $this->assertSame('partial', $unpaidChallan->status);
        $this->assertCount(3, $unpaidChallan->items);

        // Verify existing FeePayment row remains unchanged
        $existingPayment = FeePayment::where('fee_challan_id', $unpaidChallan->id)->first();
        $this->assertSame('2000.00', $existingPayment->amount_paid);

        // Verify StudentFinancialLedgerService summary
        $ledgerSummary1 = StudentFinancialLedgerService::summary($this->student1, $this->academicYear);
        $this->assertSame('8000.00', $ledgerSummary1['total_billed']);
        $this->assertSame('2000.00', $ledgerSummary1['total_paid']);
        $this->assertSame('6000.00', $ledgerSummary1['outstanding_balance']);

        // ---------------------------------------------------------------
        // 3. PAID CHALLAN SCENARIO + SETTLEMENT DATE + ADDITIONAL PAYMENT
        // ---------------------------------------------------------------
        StudentFeeAssignmentService::initializeForStudent(
            $this->student2,
            $this->academicYear,
            [$this->monthlyTuition->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        // Generate Student 2 challan for Nov
        $s2Challan = FeeBillingService::createChallan($this->student2, $this->academicYear, Carbon::parse('2026-11-01'));
        $this->assertNotNull($s2Challan);
        $this->assertSame('5000.00', $s2Challan->total_payable);

        // Student 2 pays full 5,000 on Nov 5
        $p1 = $this->post(route('school.fees.payments.store'), [
            'student_id'      => $this->student2->id,
            'fee_challan_id'  => $s2Challan->id,
            'amount_paid'     => 5000.00,
            'payment_date'    => '2026-11-05',
            'method'          => 'bank_transfer',
            'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
        ]);
        $p1->assertSessionHasNoErrors();

        $s2Challan->refresh();
        $this->assertSame('paid', $s2Challan->status);
        $this->assertSame('5000.00', $s2Challan->paid_amount);
        $this->assertSame('0.00', $s2Challan->balance);
        $this->assertSame('2026-11-05', $s2Challan->settlement_date);
        $this->assertSame('Paid', $s2Challan->settlement_classification);

        // Nov 12: Assign late Transport (2,000)
        StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student2->id,
            'academic_year_id' => $this->academicYear->id,
            'fee_structure_id' => $this->optionalTransport->id,
            'is_active'        => true,
            'starts_on'        => '2026-11-12',
            'assigned_by'      => $this->adminUser->id,
            'assigned_at'      => now(),
        ]);

        // Catch-up runs: reopens the obligation safely
        $paidCatchUp = RecurringFeeBillingService::executeBillingCycle($this->school->id, Carbon::parse('2026-11-12'));
        $this->assertSame(0, $paidCatchUp['new_challans_created']);
        $this->assertSame(1, $paidCatchUp['vouchers_updated']); // student 2 appended
        $this->assertSame('2000.00', $paidCatchUp['total_amount']);

        $s2Challan->refresh();
        $this->assertSame('7000.00', $s2Challan->total_payable);
        $this->assertSame('5000.00', $s2Challan->paid_amount); // strictly preserved
        $this->assertSame('2000.00', $s2Challan->balance); // balance is 2000
        $this->assertSame('partial', $s2Challan->status); // transitions to partial
        $this->assertNull($s2Challan->settlement_date, 'Settlement date must be null while pending balance exists');
        $this->assertSame('Partially Paid', $s2Challan->settlement_classification);

        // Previous payment row remains completely truthful and unchanged
        $firstPayment = FeePayment::where('fee_challan_id', $s2Challan->id)->orderBy('id')->first();
        $this->assertSame('5000.00', $firstPayment->amount_paid);
        $this->assertSame('2026-11-05', $firstPayment->payment_date->toDateString());
        $this->assertSame('0.00', (string) $firstPayment->balance_snapshot);

        // Ledger reflects exact new 2,000 outstanding
        $ledgerSummary2 = StudentFinancialLedgerService::summary($this->student2, $this->academicYear);
        $this->assertSame('7000.00', $ledgerSummary2['total_billed']);
        $this->assertSame('5000.00', $ledgerSummary2['total_paid']);
        $this->assertSame('2000.00', $ledgerSummary2['outstanding_balance']);

        // Later: Student 2 pays the additional 2,000 on Nov 16
        $p2 = $this->post(route('school.fees.payments.store'), [
            'student_id'      => $this->student2->id,
            'fee_challan_id'  => $s2Challan->id,
            'amount_paid'     => 2000.00,
            'payment_date'    => '2026-11-16',
            'method'          => 'cash',
            'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
        ]);
        $p2->assertSessionHasNoErrors();

        $s2Challan->refresh();
        $this->assertSame('paid', $s2Challan->status);
        $this->assertSame('7000.00', $s2Challan->paid_amount);
        $this->assertSame('0.00', $s2Challan->balance);
        $this->assertSame('2026-11-16', $s2Challan->settlement_date, 'Settlement date reflects latest payment clearing the obligation');
        $this->assertSame('Paid', $s2Challan->settlement_classification);

        // ---------------------------------------------------------------
        // 4. ADJUSTED CHALLAN SCENARIO
        // ---------------------------------------------------------------
        $student3 = Student::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'admission_no'    => 'ST-ADJ-001',
            'first_name'      => 'Adjusted',
            'last_name'       => 'Student',
            'gender'          => 'female',
            'status'          => 'active',
            'admission_date'  => '2026-09-01',
        ]);
        StudentFeeAssignmentService::initializeForStudent(
            $student3,
            $this->academicYear,
            [$this->monthlyTuition->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        $s3Challan = FeeBillingService::createChallan($student3, $this->academicYear, Carbon::parse('2026-11-01'));
        $this->assertSame('5000.00', $s3Challan->total_payable);

        // Apply a one-time fixed adjustment of PKR 500
        $adjustment = FeeAdjustmentService::applyAdjustment(
            $s3Challan,
            [
                'adjustment_type' => 'fixed',
                'value'           => 500.00,
                'reason'          => 'Special principal waiver',
                'idempotency_key' => 'ADJ-TEST-' . uniqid(),
            ],
            $this->adminUser
        );
        $this->assertNotNull($adjustment);

        $s3Challan->refresh();
        $this->assertSame('500.00', $s3Challan->adjustment_amount);
        $this->assertSame('4500.00', $s3Challan->total_payable);
        $this->assertSame('4500.00', $s3Challan->balance);

        // Late Transport (2,000) assigned
        StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $student3->id,
            'academic_year_id' => $this->academicYear->id,
            'fee_structure_id' => $this->optionalTransport->id,
            'is_active'        => true,
            'starts_on'        => '2026-11-12',
            'assigned_by'      => $this->adminUser->id,
            'assigned_at'      => now(),
        ]);

        // Catch-up runs
        $s3CatchUp = FeeBillingService::createChallan($student3, $this->academicYear, Carbon::parse('2026-11-12'));
        $this->assertNotNull($s3CatchUp);
        $this->assertSame($s3Challan->id, $s3CatchUp->id);

        $s3Challan->refresh();
        $this->assertSame('7000.00', $s3Challan->gross_amount); // 5000 + 2000
        $this->assertSame('500.00', $s3Challan->adjustment_amount); // adjustment NOT duplicated
        $this->assertSame('6500.00', $s3Challan->total_payable); // 4500 + 2000
        $this->assertSame('6500.00', $s3Challan->balance);
        $this->assertCount(1, $s3Challan->adjustments);
    }

    /**
     * Requirement 9A: Concurrent Different-Charge Append Race
     * Tuition = 5,000 initially.
     * Process A (Recurring / Transport = 2,000) and Process B (Bulk Assign / Lab = 1,000)
     * append to the same existing challan.
     * Verifies:
     * - challans = 1
     * - Tuition = 1, Transport = 1, Lab = 1
     * - challan gross_amount == SUM(items.gross_amount) == 8,000.00
     * - zero lost update
     */
    public function test_concurrent_different_charge_append_preserves_item_sum_and_prevents_lost_updates(): void
    {
        $this->actingAs($this->adminUser);

        $student = Student::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'admission_no'    => 'ST-RACE-DIFF-1',
            'first_name'      => 'RaceDiff',
            'last_name'       => 'Student',
            'gender'          => 'male',
            'status'          => 'active',
            'admission_date'  => '2026-09-01',
        ]);

        StudentFeeAssignmentService::initializeForStudent(
            $student,
            $this->academicYear,
            [$this->monthlyTuition->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        // Initial November challan: Tuition PKR 5,000
        $initialChallan = FeeBillingService::createChallan($student, $this->academicYear, Carbon::parse('2026-11-01'));
        $this->assertNotNull($initialChallan);
        $this->assertSame('5000.00', $initialChallan->total_payable);
        $this->assertCount(1, $initialChallan->items);

        // Setup Lab Fee structure (PKR 1,000)
        $labCat = FeeCategory::create(['school_id' => $this->school->id, 'name' => 'Lab Fee ' . uniqid()]);
        $labStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'academic_year'            => $this->academicYear->name,
            'fee_category_id'          => $labCat->id,
            'amount'                   => '1000.00',
            'frequency'                => 'monthly',
            'is_optional'              => true,
            'is_active'                => true,
            'admission_voucher_policy' => 'optional',
        ]);

        // Process A: Recurring assigns Transport (2,000) starting Nov 10
        StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $student->id,
            'academic_year_id' => $this->academicYear->id,
            'fee_structure_id' => $this->optionalTransport->id,
            'is_active'        => true,
            'starts_on'        => '2026-11-10',
            'assigned_by'      => $this->adminUser->id,
            'assigned_at'      => now(),
        ]);

        // Execute Process A (Recurring catch-up)
        $processA = FeeBillingService::createChallan($student, $this->academicYear, Carbon::parse('2026-11-10'));
        $this->assertNotNull($processA);
        $this->assertSame($initialChallan->id, $processA->id);

        // Process B: Bulk Assign assigns Lab Fee (1,000)
        $processB = FeeBulkAssignService::execute(
            $this->school->id,
            $labStructure->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'selected_students',
                'execution_mode' => 'assign_and_bill',
                'issue_date'     => '2026-11-10',
                'student_ids'    => [$student->id],
            ],
            $this->adminUser
        );
        $this->assertSame(0, $processB['vouchers_generated']);
        $this->assertSame(1, $processB['vouchers_updated']);

        // Verify final challan state
        $initialChallan->refresh();
        $this->assertSame(1, FeeChallan::where('student_id', $student->id)->count());
        $this->assertCount(3, $initialChallan->items);

        $itemsSum = $initialChallan->items->sum(fn ($i) => Money::toCents($i->gross_amount));
        $this->assertSame(800000, $itemsSum);
        $this->assertSame('8000.00', $initialChallan->gross_amount);
        $this->assertSame('8000.00', $initialChallan->total_payable);
        $this->assertSame('8000.00', $initialChallan->balance);
        $this->assertSame('unpaid', $initialChallan->status);
    }

    /**
     * Requirement 9B: Concurrent Same-Charge Append Race
     * Recurring and Bulk both attempt to append the SAME Transport structure to an existing challan.
     * Verifies:
     * - one Transport item only
     * - one financial increase only (5,000 -> 7,000, NOT 9,000)
     * - unique constraint handled idempotently without exception
     */
    public function test_concurrent_same_charge_append_is_idempotent_and_creates_no_duplicate_increase(): void
    {
        $this->actingAs($this->adminUser);

        $student = Student::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'admission_no'    => 'ST-RACE-SAME-1',
            'first_name'      => 'RaceSame',
            'last_name'       => 'Student',
            'gender'          => 'female',
            'status'          => 'active',
            'admission_date'  => '2026-09-01',
        ]);

        StudentFeeAssignmentService::initializeForStudent(
            $student,
            $this->academicYear,
            [$this->monthlyTuition->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-11-01')
        );

        $challan = FeeBillingService::createChallan($student, $this->academicYear, Carbon::parse('2026-11-01'));
        $this->assertSame('5000.00', $challan->total_payable);

        // Assign Transport
        StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $student->id,
            'academic_year_id' => $this->academicYear->id,
            'fee_structure_id' => $this->optionalTransport->id,
            'is_active'        => true,
            'starts_on'        => '2026-11-10',
            'assigned_by'      => $this->adminUser->id,
            'assigned_at'      => now(),
        ]);

        // Attempt 1: Recurring append
        $append1 = FeeBillingService::createChallan($student, $this->academicYear, Carbon::parse('2026-11-10'));
        $this->assertNotNull($append1);

        // Attempt 2: Bulk Assign attempts the EXACT same structure for November
        $append2 = FeeBulkAssignService::execute(
            $this->school->id,
            $this->optionalTransport->id,
            $this->academicYear->id,
            $this->class->id,
            [
                'target_mode'    => 'selected_students',
                'execution_mode' => 'assign_and_bill',
                'issue_date'     => '2026-11-10',
                'student_ids'    => [$student->id],
            ],
            $this->adminUser
        );
        $this->assertSame(0, $append2['vouchers_generated']);
        $this->assertSame(0, $append2['vouchers_updated']);
        $this->assertSame(1, $append2['already_billed']);

        // Verify: Exactly 1 Transport item, total is 7,000 (not 9,000)
        $challan->refresh();
        $this->assertCount(2, $challan->items); // Tuition + Transport
        $this->assertSame('7000.00', $challan->total_payable);
        $this->assertSame('7000.00', $challan->gross_amount);

        $itemsSum = $challan->items->sum(fn ($i) => Money::toCents($i->gross_amount));
        $this->assertSame(700000, $itemsSum);
    }
}
