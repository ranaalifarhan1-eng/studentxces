<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeChallan;
use App\Models\FeeChallanItem;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeDiscount;
use App\Models\User;
use App\Services\FeeBillingService;
use App\Services\StudentFeeAssignmentService;
use App\Services\StudentFinancialLedgerService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentFeeAssignmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $adminUser;
    protected User $accountantUser;
    protected User $teacherUser;
    protected AcademicYear $academicYear;
    protected SchoolClass $class;
    protected Section $section;
    protected Student $student;
    protected FeeCategory $tuitionCategory;
    protected FeeCategory $transportCategory;
    protected FeeCategory $admissionCategory;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions
        $pAssign = Permission::firstOrCreate(['name' => 'fees.assign', 'guard_name' => 'web']);
        $pDiscount = Permission::firstOrCreate(['name' => 'fees.discount', 'guard_name' => 'web']);
        $pView = Permission::firstOrCreate(['name' => 'fees.view', 'guard_name' => 'web']);
        $pCollect = Permission::firstOrCreate(['name' => 'fees.collect', 'guard_name' => 'web']);
        $pStructure = Permission::firstOrCreate(['name' => 'fees.structure', 'guard_name' => 'web']);

        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([$pView, $pCollect, $pStructure, $pAssign, $pDiscount]);

        $accountantRole = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $accountantRole->syncPermissions([$pView, $pCollect, $pStructure, $pAssign]);

        $teacherRole = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $teacherRole->syncPermissions([]);

        $this->school = School::create([
            'name'   => 'Greenfield Academy Test',
            'slug'   => 'greenfield-test-' . uniqid(),
            'email'  => 'info@greenfield-' . uniqid() . '.edu.pk',
            'status' => 'active',
        ]);

        $package = Package::create([
            'name'          => 'Pro Plan',
            'slug'          => 'pro-plan-' . uniqid(),
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
            'name'              => 'Admin Greenfield',
            'email'             => 'admin-' . uniqid() . '@greenfield.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->accountantUser = User::create([
            'name'              => 'Accountant Greenfield',
            'email'             => 'accountant-' . uniqid() . '@greenfield.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->accountantUser->assignRole('accountant');

        $this->teacherUser = User::create([
            'name'              => 'Teacher Greenfield',
            'email'             => 'teacher-' . uniqid() . '@greenfield.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->teacherUser->assignRole('teacher');

        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date'   => '2027-08-31',
            'is_current' => true,
        ]);

        $this->class = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Grade 10',
            'numeric_name' => 10,
        ]);

        $this->section = Section::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->class->id,
            'name'      => 'A',
        ]);

        $this->student = Student::create([
            'school_id'      => $this->school->id,
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'first_name'     => 'Bilal',
            'last_name'      => 'Ahmed',
            'admission_no'   => 'GFA-2026-001',
            'gender'         => 'male',
            'admission_date' => '2026-09-01',
            'status'         => 'active',
        ]);

        $this->tuitionCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Tuition Fee',
            'type'      => 'tuition',
            'is_active' => true,
        ]);

        $this->transportCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Transport Fee',
            'type'      => 'transport',
            'is_active' => true,
        ]);

        $this->admissionCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Admission Fee',
            'type'      => 'other',
            'is_active' => true,
        ]);
    }

    /** 1. mandatory fee fallback for legacy student */
    public function test_mandatory_fee_fallback_for_legacy_student(): void
    {
        $mandatoryTuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        $resolved = FeeBillingService::resolveBillableStructures(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-09-01')
        );

        $this->assertCount(1, $resolved);
        $this->assertEquals($mandatoryTuition->id, $resolved->first()->id);

        $challan = FeeBillingService::createChallan(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-09-01')
        );

        $this->assertNotNull($challan);
        $this->assertEquals('5000.00', $challan->total_payable);
    }

    /** 2. optional fee excluded under legacy fallback */
    public function test_optional_fee_excluded_under_legacy_fallback(): void
    {
        FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->transportCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 2000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => true, // Optional
        ]);

        // Legacy student with 0 StudentFeeAssignment records
        $resolved = FeeBillingService::resolveBillableStructures(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-09-01')
        );

        $this->assertCount(1, $resolved);
        $this->assertEquals('Tuition Fee', $resolved->first()->feeCategory->name);
    }

    /** 3. initialized assignments override fallback */
    public function test_initialized_assignments_override_fallback(): void
    {
        $tuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        $transport = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->transportCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 2500.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => true,
        ]);

        // Initialize both Tuition and Transport
        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$tuition->id, $transport->id],
            $this->adminUser->id
        );

        $resolved = FeeBillingService::resolveBillableStructures(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-09-01')
        );

        $this->assertCount(2, $resolved);

        $challan = FeeBillingService::createChallan(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-09-01')
        );

        $this->assertNotNull($challan);
        $this->assertEquals('7500.00', $challan->total_payable);
        $this->assertCount(2, $challan->items);
    }

    /** 4. all assignments inactive does NOT trigger fallback */
    public function test_all_assignments_inactive_does_not_trigger_fallback(): void
    {
        $optionalClub = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->transportCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 1500.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => true, // Optional fee
        ]);

        // Initialize assignment with optional structure
        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$optionalClub->id],
            $this->adminUser->id
        );

        // Deactivate the optional assignment
        $assignment = StudentFeeAssignment::where('student_id', $this->student->id)
            ->where('fee_structure_id', $optionalClub->id)
            ->first();
        StudentFeeAssignmentService::deactivateAssignment($assignment, $this->adminUser->id, 'Opted out');

        // Resolve billable structures
        $resolved = FeeBillingService::resolveBillableStructures(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-09-01')
        );

        $this->assertCount(0, $resolved, 'Must return 0 structures and NEVER fall back to class fees');

        $challan = FeeBillingService::createChallan(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-09-01')
        );

        $this->assertNull($challan);
    }

    /** 5. optional Transport selected for one student only */
    public function test_optional_transport_selected_for_one_student_only(): void
    {
        $student2 = Student::create([
            'school_id'      => $this->school->id,
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'first_name'     => 'Hamza',
            'last_name'      => 'Tariq',
            'admission_no'   => 'GFA-2026-002',
            'gender'         => 'male',
            'admission_date' => '2026-09-01',
            'status'         => 'active',
        ]);

        $tuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        $transport = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->transportCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 2000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => true,
        ]);

        // Student 1: Tuition + Transport
        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$tuition->id, $transport->id],
            $this->adminUser->id
        );

        // Student 2: Tuition only
        StudentFeeAssignmentService::initializeForStudent(
            $student2,
            $this->academicYear,
            [$tuition->id],
            $this->adminUser->id
        );

        $challan1 = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::parse('2026-09-01'));
        $challan2 = FeeBillingService::createChallan($student2, $this->academicYear, Carbon::parse('2026-09-01'));

        $this->assertEquals('7000.00', $challan1->total_payable);
        $this->assertCount(2, $challan1->items);

        $this->assertEquals('5000.00', $challan2->total_payable);
        $this->assertCount(1, $challan2->items);
    }

    /** 6. assignment start date */
    public function test_assignment_start_date(): void
    {
        $tuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        // Starts on October 1st
        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$tuition->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-10-01')
        );

        // September billing: should not be billable
        $resolvedSept = FeeBillingService::resolveBillableStructures(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-09-01')
        );
        $this->assertCount(0, $resolvedSept);

        // October billing: should be billable
        $resolvedOct = FeeBillingService::resolveBillableStructures(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-10-01')
        );
        $this->assertCount(1, $resolvedOct);
    }

    /** 7. assignment end date */
    public function test_assignment_end_date(): void
    {
        $tuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        // Starts Sept 1, Ends Sept 30
        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$tuition->id],
            $this->adminUser->id,
            null,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30')
        );

        // September billing: billable
        $resolvedSept = FeeBillingService::resolveBillableStructures(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-09-01')
        );
        $this->assertCount(1, $resolvedSept);

        // October billing: not billable
        $resolvedOct = FeeBillingService::resolveBillableStructures(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-10-01')
        );
        $this->assertCount(0, $resolvedOct);
    }

    /** 8. assignment reactivation */
    public function test_assignment_reactivation(): void
    {
        $transport = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->transportCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 2500.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => true, // Optional structure can be paused and resumed
        ]);

        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$transport->id],
            $this->adminUser->id
        );

        $assignment = StudentFeeAssignment::where('fee_structure_id', $transport->id)->first();
        $assignmentId = $assignment->id;

        StudentFeeAssignmentService::deactivateAssignment($assignment, $this->adminUser->id, 'Temporary pause');
        $this->assertFalse($assignment->fresh()->is_active);

        // Reactivate by initializing again
        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$transport->id],
            $this->adminUser->id
        );

        $reactivated = StudentFeeAssignment::where('fee_structure_id', $transport->id)->first();
        $this->assertEquals($assignmentId, $reactivated->id);
        $this->assertTrue($reactivated->is_active);
        $this->assertNull($reactivated->deactivated_by);
        $this->assertNull($reactivated->deactivated_at);
        $this->assertNull($reactivated->deactivation_reason);
    }

    /** 9. class mismatch rejected */
    public function test_class_mismatch_rejected(): void
    {
        $otherClass = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Grade 9',
            'numeric_name' => 9,
        ]);

        $structureClass9 = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $otherClass->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 4500.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Class mismatch/');

        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$structureClass9->id],
            $this->adminUser->id
        );
    }

    /** 10. school mismatch rejected */
    public function test_school_mismatch_rejected(): void
    {
        $schoolB = School::create([
            'name'   => 'School B',
            'slug'   => 'school-b-' . uniqid(),
            'email'  => 'info@schoolb-' . uniqid() . '.com',
            'status' => 'active',
        ]);

        $classB = SchoolClass::create([
            'school_id'    => $schoolB->id,
            'name'         => 'Grade 10',
            'numeric_name' => 10,
        ]);

        $catB = FeeCategory::create([
            'school_id' => $schoolB->id,
            'name'      => 'Tuition Fee',
            'type'      => 'tuition',
            'is_active' => true,
        ]);

        $structureB = FeeStructure::create([
            'school_id'       => $schoolB->id,
            'class_id'        => $classB->id,
            'fee_category_id' => $catB->id,
            'academic_year'   => '2026-2027',
            'amount'          => 6000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Cross-tenant violation/');

        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$structureB->id],
            $this->adminUser->id
        );
    }

    /** 11. AcademicYear mismatch rejected */
    public function test_academic_year_mismatch_rejected(): void
    {
        $tuitionOldAY = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2025-2026', // Mismatched year
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Academic year mismatch/');

        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear, // 2026-2027
            [$tuitionOldAY->id],
            $this->adminUser->id
        );
    }

    /** 12. one-time fee billed once */
    public function test_one_time_fee_billed_once(): void
    {
        $admissionFee = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->admissionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 15000.00,
            'frequency'       => 'one_time',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$admissionFee->id],
            $this->adminUser->id
        );

        // Bill month 1 (September)
        $challan1 = FeeBillingService::createChallan(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-09-01')
        );
        $this->assertNotNull($challan1);
        $this->assertEquals('15000.00', $challan1->total_payable);

        // Reactivate assignment
        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$admissionFee->id],
            $this->adminUser->id
        );

        // Bill month 2 (October): one-time fee must NOT be billed again
        $challan2 = FeeBillingService::createChallan(
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-10-01')
        );
        $this->assertNull($challan2, 'One-time fee already billed on an active challan must not bill again');
    }

    /** 13. discount does not alter FeeStructure */
    public function test_discount_does_not_alter_fee_structure(): void
    {
        $tuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        StudentFeeAssignmentService::createConcession(
            $this->student,
            $this->academicYear,
            [
                'title'           => 'Sibling Concession',
                'type'            => 'percentage',
                'value'           => 20.00,
                'fee_category_id' => $this->tuitionCategory->id,
            ],
            $this->adminUser->id
        );

        $freshStructure = $tuition->fresh();
        $this->assertEquals('5000.00', $freshStructure->amount, 'FeeStructure amount must remain unaltered');
    }

    /** 14. fixed discount bounds */
    public function test_fixed_discount_bounds(): void
    {
        FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        // Exceeds fee structure amount
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot exceed the fee head amount/');

        StudentFeeAssignmentService::createConcession(
            $this->student,
            $this->academicYear,
            [
                'title'           => 'Excessive Discount',
                'type'            => 'fixed',
                'value'           => 6000.00,
                'fee_category_id' => $this->tuitionCategory->id,
            ],
            $this->adminUser->id
        );
    }

    /** 15. percentage discount bounds */
    public function test_percentage_discount_bounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/greater than 0 and at most 100/');

        StudentFeeAssignmentService::createConcession(
            $this->student,
            $this->academicYear,
            [
                'title'           => 'Invalid Percentage',
                'type'            => 'percentage',
                'value'           => 120.00,
                'fee_category_id' => $this->tuitionCategory->id,
            ],
            $this->adminUser->id
        );
    }

    /** 16. no ambiguous active discount stacking */
    public function test_no_ambiguous_active_discount_stacking(): void
    {
        StudentFeeAssignmentService::createConcession(
            $this->student,
            $this->academicYear,
            [
                'title'           => 'First Discount',
                'type'            => 'percentage',
                'value'           => 10.00,
                'fee_category_id' => $this->tuitionCategory->id,
            ],
            $this->adminUser->id
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/An active discount already exists/');

        StudentFeeAssignmentService::createConcession(
            $this->student,
            $this->academicYear,
            [
                'title'           => 'Second Duplicate Discount',
                'type'            => 'percentage',
                'value'           => 15.00,
                'fee_category_id' => $this->tuitionCategory->id,
            ],
            $this->adminUser->id
        );
    }

    /** 17. historical challan unaffected by assignment change */
    public function test_historical_challan_unaffected_by_assignment_change(): void
    {
        $transport = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->transportCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 2000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => true, // Optional structure can be deactivated
        ]);

        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$transport->id],
            $this->adminUser->id
        );

        $challan = FeeBillingService::createChallan($this->student, $this->academicYear, Carbon::parse('2026-09-01'));
        $this->assertNotNull($challan);
        $challanId = $challan->id;

        // Now deactivate optional assignment in October
        $assignment = StudentFeeAssignment::where('fee_structure_id', $transport->id)->first();
        StudentFeeAssignmentService::deactivateAssignment($assignment, $this->adminUser->id, 'Opted out of transport');

        $freshChallan = FeeChallan::find($challanId);
        $this->assertEquals('2000.00', $freshChallan->total_payable);
        $this->assertEquals('unpaid', $freshChallan->status);
        $this->assertCount(1, $freshChallan->items);
    }

    /** 18. modern outstanding calculation */
    public function test_modern_outstanding_calculation(): void
    {
        $tuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        $ch1 = FeeChallan::create([
            'school_id'                     => $this->school->id,
            'challan_no'                    => 'CHL-TEST-001',
            'billing_period_key'            => 'AY1-2026-09',
            'active_period_key'             => "{$this->student->id}_AY1-2026-09",
            'student_id'                    => $this->student->id,
            'class_id'                      => $this->class->id,
            'academic_year_id'              => $this->academicYear->id,
            'student_name'                  => $this->student->full_name,
            'admission_no'                  => $this->student->admission_no,
            'class_name'                    => 'Grade 10',
            'academic_year_name'            => '2026-2027',
            'issue_date'                    => '2026-09-01',
            'due_date'                      => '2026-09-15',
            'gross_amount'                  => '5000.00',
            'total_payable'                 => '5000.00',
            'paid_amount'                   => '2000.00',
            'status'                        => 'partial',
        ]);

        $ch2 = FeeChallan::create([
            'school_id'                     => $this->school->id,
            'challan_no'                    => 'CHL-TEST-002',
            'billing_period_key'            => 'AY1-2026-10',
            'active_period_key'             => "{$this->student->id}_AY1-2026-10",
            'student_id'                    => $this->student->id,
            'class_id'                      => $this->class->id,
            'academic_year_id'              => $this->academicYear->id,
            'student_name'                  => $this->student->full_name,
            'admission_no'                  => $this->student->admission_no,
            'class_name'                    => 'Grade 10',
            'academic_year_name'            => '2026-2027',
            'issue_date'                    => '2026-10-01',
            'due_date'                      => '2026-10-15',
            'gross_amount'                  => '4000.00',
            'total_payable'                 => '4000.00',
            'paid_amount'                   => '0.00',
            'status'                        => 'unpaid',
        ]);

        $summary = StudentFinancialLedgerService::summary($this->student, $this->academicYear);

        // 3000 (from ch1) + 4000 (from ch2) = 7000
        $this->assertEquals('7000.00', $summary['outstanding_balance']);
        $this->assertEquals(700000, $summary['outstanding_cents']);
        $this->assertEquals('9000.00', $summary['total_billed']);
    }

    /** 19. legacy outstanding compatibility */
    public function test_legacy_outstanding_compatibility(): void
    {
        // Legacy standalone payment with balance
        FeePayment::create([
            'school_id'      => $this->school->id,
            'student_id'     => $this->student->id,
            'fee_challan_id' => null, // Standalone legacy
            'receipt_no'     => 'RCP-LEGACY-001',
            'amount_due'     => '3500.00',
            'amount_paid'    => '1000.00',
            'discount'       => '0.00',
            'fine'           => '0.00',
            'payment_date'   => '2026-08-01',
            'status'         => 'partial',
        ]);

        $summary = StudentFinancialLedgerService::summary($this->student);

        $this->assertEquals('2500.00', $summary['outstanding_balance']);
        $this->assertEquals('1000.00', $summary['total_paid']);
        $this->assertEquals('3500.00', $summary['total_billed']);
    }

    /** 20. mixed modern/legacy no double count */
    public function test_mixed_modern_legacy_no_double_count(): void
    {
        $ch = FeeChallan::create([
            'school_id'                     => $this->school->id,
            'challan_no'                    => 'CHL-TEST-003',
            'billing_period_key'            => 'AY1-2026-09',
            'active_period_key'             => "{$this->student->id}_AY1-2026-09",
            'student_id'                    => $this->student->id,
            'class_id'                      => $this->class->id,
            'academic_year_id'              => $this->academicYear->id,
            'student_name'                  => $this->student->full_name,
            'admission_no'                  => $this->student->admission_no,
            'class_name'                    => 'Grade 10',
            'academic_year_name'            => '2026-2027',
            'issue_date'                    => '2026-09-01',
            'due_date'                      => '2026-09-15',
            'gross_amount'                  => '5000.00',
            'total_payable'                 => '5000.00',
            'paid_amount'                   => '2000.00',
            'status'                        => 'partial',
        ]);

        // Modern receipt linked to challan
        FeePayment::create([
            'school_id'      => $this->school->id,
            'student_id'     => $this->student->id,
            'fee_challan_id' => $ch->id,
            'receipt_no'     => 'RCP-MODERN-001',
            'amount_due'     => '5000.00',
            'amount_paid'    => '2000.00',
            'discount'       => '0.00',
            'fine'           => '0.00',
            'payment_date'   => '2026-09-05',
            'status'         => 'partial',
        ]);

        // Legacy standalone fully paid record
        FeePayment::create([
            'school_id'      => $this->school->id,
            'student_id'     => $this->student->id,
            'fee_challan_id' => null,
            'receipt_no'     => 'RCP-LEGACY-002',
            'amount_due'     => '1500.00',
            'amount_paid'    => '1500.00',
            'discount'       => '0.00',
            'fine'           => '0.00',
            'payment_date'   => '2026-08-15',
            'status'         => 'paid',
        ]);

        $summary = StudentFinancialLedgerService::summary($this->student);

        $this->assertEquals('6500.00', $summary['total_billed']);
        $this->assertEquals('3500.00', $summary['total_paid']);
        $this->assertEquals('3000.00', $summary['outstanding_balance']);
    }

    /** 21. overdue derived from due date */
    public function test_overdue_derived_from_due_date(): void
    {
        $pastDueDate = Carbon::yesterday()->toDateString();

        $ch = FeeChallan::create([
            'school_id'                     => $this->school->id,
            'challan_no'                    => 'CHL-TEST-004',
            'billing_period_key'            => 'AY1-2026-09',
            'active_period_key'             => "{$this->student->id}_AY1-2026-09",
            'student_id'                    => $this->student->id,
            'class_id'                      => $this->class->id,
            'academic_year_id'              => $this->academicYear->id,
            'student_name'                  => $this->student->full_name,
            'admission_no'                  => $this->student->admission_no,
            'class_name'                    => 'Grade 10',
            'academic_year_name'            => '2026-2027',
            'issue_date'                    => Carbon::now()->subDays(10)->toDateString(),
            'due_date'                      => $pastDueDate,
            'gross_amount'                  => '5000.00',
            'total_payable'                 => '5000.00',
            'paid_amount'                   => '0.00',
            'status'                        => 'unpaid', // Canonical status in DB remains unpaid
        ]);

        $summary = StudentFinancialLedgerService::summary($this->student);
        $this->assertTrue($summary['is_overdue']);
        $this->assertEquals('5000.00', $summary['overdue_balance']);

        $challans = StudentFinancialLedgerService::challans($this->student);
        $retrieved = $challans->first();

        $this->assertTrue($retrieved->is_overdue);
        $this->assertEquals('overdue', $retrieved->display_status);

        // Invariant: Canonical database status is untouched
        $this->assertEquals('unpaid', $ch->fresh()->status);
    }

    /** 22. cross-tenant isolation */
    public function test_cross_tenant_isolation(): void
    {
        $schoolB = School::create([
            'name'   => 'School B',
            'slug'   => 'school-b-' . uniqid(),
            'email'  => 'info@schoolb-' . uniqid() . '.com',
            'status' => 'active',
        ]);

        $studentB = Student::create([
            'school_id'      => $schoolB->id,
            'class_id'       => $this->class->id,
            'first_name'     => 'Zain',
            'last_name'      => 'Malik',
            'admission_no'   => 'SCHB-001',
            'gender'         => 'male',
            'admission_date' => '2026-09-01',
            'status'         => 'active',
        ]);

        FeeChallan::create([
            'school_id'                     => $schoolB->id,
            'challan_no'                    => 'CHL-SCHB-001',
            'billing_period_key'            => 'AY1-2026-09',
            'active_period_key'             => "{$studentB->id}_AY1-2026-09",
            'student_id'                    => $studentB->id,
            'class_id'                      => $this->class->id,
            'student_name'                  => $studentB->full_name,
            'admission_no'                  => $studentB->admission_no,
            'class_name'                    => 'Grade 10',
            'academic_year_name'            => '2026-2027',
            'issue_date'                    => '2026-09-01',
            'due_date'                      => '2026-09-15',
            'gross_amount'                  => '9999.00',
            'total_payable'                 => '9999.00',
            'paid_amount'                   => '0.00',
            'status'                        => 'unpaid',
        ]);

        $summaryA = StudentFinancialLedgerService::summary($this->student);
        $this->assertEquals('0.00', $summaryA['outstanding_balance'], 'School A summary must never leak School B debt');
    }

    /** 23. permission denial */
    public function test_permission_denial(): void
    {
        // Teacher has no financial write permissions
        $this->expectException(AuthorizationException::class);
        StudentFeeAssignmentService::authorizeAssignment($this->teacherUser);
    }

    public function test_discount_permission_denial(): void
    {
        // Teacher cannot grant concessions
        $this->expectException(AuthorizationException::class);
        StudentFeeAssignmentService::authorizeConcession($this->teacherUser);
    }

    public function test_admin_and_accountant_permissions(): void
    {
        // School Admin can do both
        StudentFeeAssignmentService::authorizeAssignment($this->adminUser);
        StudentFeeAssignmentService::authorizeConcession($this->adminUser);

        // Accountant can assign fees
        StudentFeeAssignmentService::authorizeAssignment($this->accountantUser);
        $this->assertTrue(true);

        // Accountant cannot grant discount by default
        $this->expectException(AuthorizationException::class);
        StudentFeeAssignmentService::authorizeConcession($this->accountantUser);
    }

    /** 24. mandatory fee cannot be deactivated through deactivate_assignment */
    public function test_mandatory_fee_cannot_be_deactivated_through_deactivate_assignment(): void
    {
        $tuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$tuition->id],
            $this->adminUser->id
        );

        $assignment = StudentFeeAssignment::where('student_id', $this->student->id)
            ->where('fee_structure_id', $tuition->id)
            ->first();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Mandatory fee assignments .* cannot be deactivated/');

        StudentFeeAssignmentService::deactivateAssignment($assignment, $this->adminUser->id, 'Attempting to drop tuition');
    }

    /** 25. mandatory structure automatically included even if omitted from payload */
    public function test_mandatory_structure_automatically_included_even_if_omitted_from_payload(): void
    {
        $tuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        // Call with empty array
        $assignments = StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [], // Omitted mandatory fee
            $this->adminUser->id
        );

        $this->assertCount(1, $assignments);
        $this->assertEquals($tuition->id, $assignments->first()->fee_structure_id);
        $this->assertTrue($assignments->first()->is_active);
    }

    /** 26. malicious omission of mandatory structure cannot remove it */
    public function test_malicious_omission_of_mandatory_structure_cannot_remove_it(): void
    {
        $tuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        $transport = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->transportCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 2000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => true,
        ]);

        // Submit only optional transport
        $assignments = StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$transport->id], // Omitted tuition
            $this->adminUser->id
        );

        $this->assertCount(2, $assignments, 'Tuition must be automatically included alongside Transport');
        $structureIds = $assignments->pluck('fee_structure_id')->all();
        $this->assertContains($tuition->id, $structureIds);
        $this->assertContains($transport->id, $structureIds);
    }

    /** 27. optional structure can be deselected */
    public function test_optional_structure_can_be_deselected(): void
    {
        $tuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        $transport = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->transportCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 2000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => true,
        ]);

        // Initialize with both
        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$tuition->id, $transport->id],
            $this->adminUser->id
        );

        // Deselect transport by passing empty array (server retains mandatory tuition, deactivates transport)
        $updated = StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [],
            $this->adminUser->id
        );

        $this->assertCount(1, $updated);
        $this->assertEquals($tuition->id, $updated->first()->fee_structure_id);

        $transportAssignment = StudentFeeAssignment::where('student_id', $this->student->id)
            ->where('fee_structure_id', $transport->id)
            ->first();
        $this->assertFalse($transportAssignment->is_active);
    }

    /** 28. mandatory structure remains after edit synchronization */
    public function test_mandatory_structure_remains_after_edit_synchronization(): void
    {
        $tuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        // First sync
        StudentFeeAssignmentService::initializeForStudent($this->student, $this->academicYear, [$tuition->id], $this->adminUser->id);
        $this->assertTrue(StudentFeeAssignment::where('student_id', $this->student->id)->where('fee_structure_id', $tuition->id)->first()->is_active);

        // Second sync with no structures passed
        StudentFeeAssignmentService::initializeForStudent($this->student, $this->academicYear, [], $this->adminUser->id);
        $this->assertTrue(StudentFeeAssignment::where('student_id', $this->student->id)->where('fee_structure_id', $tuition->id)->first()->is_active);
    }

    /** 29. 100% concession can result in zero payable while assignment remains */
    public function test_100_percent_concession_can_result_in_zero_payable_while_assignment_remains(): void
    {
        $tuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        StudentFeeAssignmentService::initializeForStudent(
            $this->student,
            $this->academicYear,
            [$tuition->id],
            $this->adminUser->id
        );

        $discount = StudentFeeAssignmentService::createConcession(
            $this->student,
            $this->academicYear,
            [
                'title'           => '100% Merit Scholarship',
                'type'            => 'percentage',
                'value'           => 100.00,
                'fee_category_id' => $this->tuitionCategory->id,
            ],
            $this->adminUser->id
        );

        // Verify assignment is still active
        $assignment = StudentFeeAssignment::where('student_id', $this->student->id)->first();
        $this->assertTrue($assignment->is_active);

        // Line preparation: gross is 5000.00, discount is 5000.00, total payable is 0
        $lines = FeeBillingService::prepareChallanLines(
            collect([$tuition]),
            $this->student,
            $this->academicYear,
            Carbon::parse('2026-09-01'),
            $discount
        );

        $this->assertEquals(500000, $lines['gross_cents']);
        $this->assertEquals(500000, $lines['discount_cents']);
        $this->assertEquals(0, $lines['total_payable_cents']);
    }

    /** 30. is_optional backend validation and persistence */
    public function test_is_optional_backend_validation_and_persistence(): void
    {
        $this->actingAs($this->adminUser);

        // 1. Create with is_optional = true
        $response = $this->post(route('school.fees.structures.store'), [
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->transportCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 3000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => true,
        ]);
        $response->assertSessionHasNoErrors();

        $structure = FeeStructure::where('class_id', $this->class->id)
            ->where('fee_category_id', $this->transportCategory->id)
            ->first();

        $this->assertNotNull($structure);
        $this->assertTrue($structure->is_optional);

        // 2. Update to is_optional = false
        $updateResponse = $this->put(route('school.fees.structures.update', $structure), [
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->transportCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 3500.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);
        $updateResponse->assertSessionHasNoErrors();

        $this->assertFalse($structure->fresh()->is_optional);
    }

    /** 31. unauthorized user cannot forge fee assignment payload */
    public function test_unauthorized_user_cannot_forge_fee_assignment_payload(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessageMatches("/permission 'fees.assign'/");

        StudentFeeAssignmentService::processAdmissionFinancials(
            $this->teacherUser,
            $this->student,
            $this->academicYear,
            [
                'fee_structure_ids' => [999],
            ]
        );
    }

    /** 32. unauthorized user cannot forge concession payload */
    public function test_unauthorized_user_cannot_forge_concession_payload(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessageMatches("/permission 'fees.discount'/");

        // Accountant has fees.assign but NOT fees.discount
        StudentFeeAssignmentService::processAdmissionFinancials(
            $this->accountantUser,
            $this->student,
            $this->academicYear,
            [
                'concession' => [
                    'title' => 'Unauthorized Discount',
                    'type'  => 'percentage',
                    'value' => 50,
                ],
            ]
        );
    }

    /** 33. school timezone overdue calculation */
    public function test_school_timezone_overdue_calculation(): void
    {
        $this->school->update(['timezone' => 'Asia/Karachi']);

        $karachiToday = Carbon::now('Asia/Karachi')->startOfDay();
        $schoolToday = StudentFinancialLedgerService::getSchoolToday($this->student);

        $this->assertEquals($karachiToday->format('Y-m-d'), $schoolToday->format('Y-m-d'));

        // Challan due yesterday in school local time is overdue
        $ch = FeeChallan::create([
            'school_id'          => $this->school->id,
            'challan_no'         => 'CHL-TZ-001',
            'billing_period_key' => 'AY1-2026-09',
            'active_period_key'  => "{$this->student->id}_AY1-2026-09-TZ",
            'student_id'         => $this->student->id,
            'class_id'           => $this->class->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_name'       => $this->student->full_name,
            'admission_no'       => $this->student->admission_no,
            'class_name'         => 'Grade 10',
            'academic_year_name' => '2026-2027',
            'issue_date'         => $schoolToday->copy()->subDays(10)->toDateString(),
            'due_date'           => $schoolToday->copy()->subDay()->toDateString(), // Yesterday in Karachi
            'gross_amount'       => '5000.00',
            'total_payable'      => '5000.00',
            'paid_amount'        => '0.00',
            'status'             => 'unpaid',
        ]);

        $summary = StudentFinancialLedgerService::summary($this->student);
        $this->assertTrue($summary['is_overdue']);
        $this->assertEquals('5000.00', $summary['overdue_balance']);
    }

    /** 34. billing start month normalized behavior */
    public function test_billing_start_month_normalized_behavior(): void
    {
        $tuition = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        // Admission on 18 Sep 2026 with Billing Start Month = Sep 2026
        $result = StudentFeeAssignmentService::processAdmissionFinancials(
            $this->adminUser,
            $this->student,
            $this->academicYear,
            [
                'billing_start_month'    => '2026-09-18', // mid-month
                'fee_structure_ids'      => [$tuition->id],
                'generate_first_challan' => true,
            ]
        );

        $assignment = $result['assignments']->first();
        // Normalized to first day of billing start month
        $this->assertEquals('2026-09-01', $assignment->starts_on->toDateString());

        // Full monthly amount charged without proration
        $challan = $result['challan'];
        $this->assertNotNull($challan);
        $this->assertEquals('5000.00', $challan->total_payable);
        $this->assertEquals('2026-09-01', $challan->issue_date->toDateString());
    }
}
