<?php

namespace Tests\Feature;

use App\Mail\FeeVoucherIssuedMail;
use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeChallan;
use App\Models\FeeChallanItem;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\Guardian;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\User;
use App\Services\FeeBulkAssignService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeeBulkWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $adminUser;
    protected User $accountantUser;
    protected User $teacherUser;
    protected AcademicYear $academicYear;
    protected SchoolClass $class;
    protected Section $secA;
    protected Section $secB;
    protected Student $student1;
    protected Student $student2;
    protected Student $student3;
    protected Student $student4;
    protected Student $student5Inactive;
    protected FeeCategory $category;
    protected FeeStructure $structure;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Roles & Permissions setup
        $permissions = [
            'students.view', 'students.create', 'students.edit',
            'fees.view', 'fees.collect', 'fees.structure', 'fees.assign', 'fees.bulk_bill',
        ];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions($permissions);

        $accountantRole = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        // Accountant by default has fees.view, fees.collect, fees.structure, fees.assign but NOT fees.bulk_bill
        $accountantRole->syncPermissions(['fees.view', 'fees.collect', 'fees.structure', 'fees.assign']);

        $teacherRole = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $teacherRole->syncPermissions(['students.view']);

        // 2. School & Subscription
        $this->school = School::create([
            'name'       => 'Test Academy',
            'domain'     => 'testacademy',
            'is_active'  => true,
            'created_by' => 1,
        ]);

        $package = Package::create([
            'name'          => 'Enterprise Plan',
            'slug'          => 'plan-' . uniqid(),
            'price_monthly' => 50,
            'price_yearly'  => 500,
            'max_students'  => 500,
            'max_staff'     => 50,
            'storage_gb'    => 50,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'students']);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'fees']);

        SchoolSubscription::create([
            'school_id'  => $this->school->id,
            'package_id' => $package->id,
            'status'     => 'active',
            'start_date' => Carbon::now()->subDays(10),
            'end_date'   => Carbon::now()->addDays(50),
        ]);

        // 3. Users
        $this->adminUser = User::create([
            'name'      => 'Admin Tester',
            'email'     => 'admin@testacademy.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->accountantUser = User::create([
            'name'      => 'Accountant Tester',
            'email'     => 'acct@testacademy.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->accountantUser->assignRole('accountant');

        $this->teacherUser = User::create([
            'name'      => 'Teacher Tester',
            'email'     => 'teacher@testacademy.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->teacherUser->assignRole('teacher');

        // 4. Academic Year & Classes
        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => '2026-2027',
            'start_date' => '2026-01-01',
            'end_date'   => '2027-12-31',
            'is_current' => true,
        ]);

        $this->class = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Class 9',
            'numeric_name' => 9,
        ]);

        $this->secA = Section::create(['school_id' => $this->school->id, 'class_id' => $this->class->id, 'name' => 'A']);
        $this->secB = Section::create(['school_id' => $this->school->id, 'class_id' => $this->class->id, 'name' => 'B']);

        // 5. Guardians & Students
        $g1 = Guardian::create([
            'school_id' => $this->school->id,
            'name'      => 'Guardian One',
            'email'     => 'guardian1@example.test',
            'phone'     => '03001111111',
        ]);
        $g2 = Guardian::create([
            'school_id' => $this->school->id,
            'name'      => 'Guardian Two',
            'email'     => 'guardian2@example.test',
            'phone'     => '03002222222',
        ]);

        // Student 1: Sec A, active, student email + guardian email
        $this->student1 = Student::create([
            'school_id'    => $this->school->id,
            'class_id'     => $this->class->id,
            'section_id'   => $this->secA->id,
            'guardian_id'  => $g1->id,
            'admission_no' => 'ADM-001',
            'first_name'   => 'Jewel',
            'last_name'    => 'Student',
            'email'        => 'jewel@testacademy.test',
            'status'       => 'active',
        ]);

        // Student 2: Sec A, active, guardian email only (no student email)
        $this->student2 = Student::create([
            'school_id'    => $this->school->id,
            'class_id'     => $this->class->id,
            'section_id'   => $this->secA->id,
            'guardian_id'  => $g2->id,
            'admission_no' => 'ADM-002',
            'first_name'   => 'Suma',
            'last_name'    => 'Student',
            'email'        => null,
            'status'       => 'active',
        ]);

        // Student 3: Sec B, active, student email only (no guardian email)
        $this->student3 = Student::create([
            'school_id'    => $this->school->id,
            'class_id'     => $this->class->id,
            'section_id'   => $this->secB->id,
            'guardian_id'  => null,
            'admission_no' => 'ADM-003',
            'first_name'   => 'Tanvir',
            'last_name'    => 'Student',
            'email'        => 'tanvir@testacademy.test',
            'status'       => 'active',
        ]);

        // Student 4: Sec B, active, no emails
        $this->student4 = Student::create([
            'school_id'    => $this->school->id,
            'class_id'     => $this->class->id,
            'section_id'   => $this->secB->id,
            'guardian_id'  => null,
            'admission_no' => 'ADM-004',
            'first_name'   => 'Riya',
            'last_name'    => 'Student',
            'email'        => null,
            'status'       => 'active',
        ]);

        // Student 5: Sec A, inactive/withdrawn
        $this->student5Inactive = Student::create([
            'school_id'    => $this->school->id,
            'class_id'     => $this->class->id,
            'section_id'   => $this->secA->id,
            'guardian_id'  => null,
            'admission_no' => 'ADM-005',
            'first_name'   => 'Tina',
            'last_name'    => 'Inactive',
            'email'        => 'tina@testacademy.test',
            'status'       => 'inactive',
        ]);

        // 6. Fee Category & Fee Structure
        $this->category = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Test Session Fee 2026',
            'type'      => 'exam',
            'is_active' => true,
        ]);

        $this->structure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'fee_category_id'          => $this->category->id,
            'academic_year'            => '2026-2027',
            'amount'                   => '2000.00',
            'frequency'                => 'one_time',
            'is_active'                => true,
            'is_optional'              => false,
            'admission_voucher_policy' => 'optional',
        ]);
    }

    public function test_class_wide_preview_calculates_correct_counts_and_amounts(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.preview'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'entire_class',
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
            ]);

        $response->assertOk();
        $summary = $response->json('summary');

        // Total active students in class = 4 (student 5 is withdrawn and active_only=true)
        $this->assertEquals(4, $summary['total_students']);
        $this->assertEquals(4, $summary['eligible']);
        $this->assertEquals(0, $summary['already_assigned']);
        $this->assertEquals(0, $summary['already_billed']);
        $this->assertEquals(4, $summary['new_assignments']);
        $this->assertEquals(4, $summary['vouchers_to_generate']);
        $this->assertEquals(800000, $summary['estimated_billing_cents']);
        $this->assertEquals('8000.00', $summary['estimated_billing_amount']);
    }

    public function test_section_preview_filters_students(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.preview'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'selected_sections',
                'section_ids'      => [$this->secA->id],
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
            ]);

        $response->assertOk();
        $summary = $response->json('summary');

        // Sec A active students: student1, student2
        $this->assertEquals(2, $summary['total_students']);
        $this->assertEquals(2, $summary['eligible']);
        $this->assertEquals(400000, $summary['estimated_billing_cents']);
    }

    public function test_selected_student_preview_targets_exact_subset(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.preview'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'selected_students',
                'student_ids'      => [$this->student1->id, $this->student3->id],
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
            ]);

        $response->assertOk();
        $summary = $response->json('summary');

        $this->assertEquals(2, $summary['total_students']);
        $this->assertEquals(2, $summary['eligible']);
        $this->assertEquals(400000, $summary['estimated_billing_cents']);
    }

    public function test_inactive_student_excluded_when_active_only_true(): void
    {
        // When active_only is false, student 5 is included but flagged as inactive
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.preview'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'entire_class',
                'active_only'      => false,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
            ]);

        $response->assertOk();
        $summary = $response->json('summary');

        $this->assertEquals(5, $summary['total_students']);
        $this->assertEquals(4, $summary['eligible']);
        $this->assertEquals(1, $summary['inactive_skipped']);
    }

    public function test_existing_assignment_identified_in_preview(): void
    {
        // Pre-create an active assignment for student 1
        StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $this->student1->id,
            'fee_structure_id' => $this->structure->id,
            'academic_year_id' => $this->academicYear->id,
            'is_active'        => true,
            'starts_on'        => '2026-01-01',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.preview'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'entire_class',
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
            ]);

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertEquals(1, $summary['already_assigned']);
        $this->assertEquals(3, $summary['new_assignments']);

        $students = collect($response->json('students'));
        $s1 = $students->firstWhere('id', $this->student1->id);
        $this->assertEquals('already_assigned', $s1['assignment_status']);
    }

    public function test_existing_one_time_bill_identified_in_preview(): void
    {
        // Pre-create an active challan for student 1 with this billing label
        FeeChallan::create([
            'school_id'                     => $this->school->id,
            'challan_no'                    => 'CHL-TEST-01',
            'billing_period_key'            => 'Test Session 2026',
            'active_period_key'             => "{$this->student1->id}_Test Session 2026",
            'student_id'                    => $this->student1->id,
            'class_id'                      => $this->class->id,
            'class_name'                    => $this->class->name,
            'academic_year_id'              => $this->academicYear->id,
            'academic_year_name'            => $this->academicYear->name,
            'student_name'                  => $this->student1->full_name,
            'admission_no'                  => $this->student1->admission_no,
            'issue_date'                    => now()->toDateString(),
            'due_date'                      => now()->addDays(15)->toDateString(),
            'gross_amount'                  => '2000.00',
            'discount_amount'               => '0.00',
            'total_payable'                 => '2000.00',
            'paid_amount'                   => '0.00',
            'previous_outstanding_snapshot' => '0.00',
            'status'                        => 'unpaid',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.preview'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'entire_class',
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
            ]);

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertEquals(1, $summary['already_billed']);
        $this->assertEquals(3, $summary['vouchers_to_generate']);
        $this->assertEquals(600000, $summary['estimated_billing_cents']);
    }

    public function test_assign_fee_only_mode_creates_assignments_without_vouchers(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.execute'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'entire_class',
                'active_only'      => true,
                'execution_mode'   => 'assign_only',
                'billing_label'    => 'Test Session 2026',
            ]);

        $response->assertOk();
        $this->assertEquals(4, $response->json('assignments_created'));
        $this->assertEquals(0, $response->json('vouchers_generated'));

        $this->assertEquals(4, StudentFeeAssignment::where('school_id', $this->school->id)->count());
        $this->assertEquals(0, FeeChallan::where('school_id', $this->school->id)->count());
        $this->assertEquals(0, FeePayment::count());
    }

    public function test_assign_and_voucher_mode_creates_both_assignments_and_challans(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.execute'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'entire_class',
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
                'due_date'         => '2026-10-15',
                'issue_date'       => '2026-09-21',
            ]);

        $response->assertOk();
        $this->assertEquals(4, $response->json('assignments_created'));
        $this->assertEquals(4, $response->json('vouchers_generated'));

        $this->assertEquals(4, StudentFeeAssignment::where('school_id', $this->school->id)->count());
        $this->assertEquals(4, FeeChallan::where('school_id', $this->school->id)->count());
        $this->assertEquals(4, FeeChallanItem::where('school_id', $this->school->id)->count());

        // Zero FeePayment created
        $this->assertEquals(0, FeePayment::count());

        // Verify challan attributes
        $firstChallan = FeeChallan::first();
        $this->assertEquals('2000.00', $firstChallan->total_payable);
        $this->assertEquals('unpaid', $firstChallan->status);
        $this->assertEquals('2026-10-15', $firstChallan->due_date->format('Y-m-d'));
        $this->assertEquals('Test Session 2026', $firstChallan->billing_period_label);
    }

    public function test_rerun_operation_is_strictly_idempotent_and_creates_no_duplicates(): void
    {
        // First execution
        $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.execute'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'entire_class',
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
                'due_date'         => '2026-10-15',
            ]);

        $this->assertEquals(4, StudentFeeAssignment::count());
        $this->assertEquals(4, FeeChallan::count());

        // Re-run execution with identical payload
        $response2 = $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.execute'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'entire_class',
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
                'due_date'         => '2026-10-15',
            ]);

        $response2->assertOk();
        $this->assertEquals(0, $response2->json('assignments_created'));
        $this->assertEquals(4, $response2->json('already_assigned'));
        $this->assertEquals(0, $response2->json('vouchers_generated'));
        $this->assertEquals(4, $response2->json('already_billed'));

        // Still exactly 4 assignments and 4 challans
        $this->assertEquals(4, StudentFeeAssignment::count());
        $this->assertEquals(4, FeeChallan::count());
    }

    public function test_billing_label_is_persisted_correctly(): void
    {
        $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.execute'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'selected_students',
                'student_ids'      => [$this->student1->id],
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Sports Fund 2026',
            ]);

        $challan = FeeChallan::where('student_id', $this->student1->id)->firstOrFail();
        $this->assertEquals('Sports Fund 2026', $challan->billing_period_key);
        $this->assertEquals('Sports Fund 2026', $challan->billing_period_label);

        $item = FeeChallanItem::where('fee_challan_id', $challan->id)->firstOrFail();
        $this->assertEquals('Sports Fund 2026', $item->charge_period_key);
    }

    public function test_notification_with_mail_fake_dispatches_for_student_only(): void
    {
        Mail::fake();

        $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.execute'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'entire_class',
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
                'notify_student'   => true,
                'notify_guardian'  => false,
            ]);

        // Student 1 & 3 have student emails, Student 2 & 4 do not
        Mail::assertQueued(FeeVoucherIssuedMail::class, function ($mail) {
            return in_array($mail->to[0]['address'], ['jewel@testacademy.test', 'tanvir@testacademy.test']);
        });

        // No mail sent to guardians
        Mail::assertNotQueued(FeeVoucherIssuedMail::class, function ($mail) {
            return in_array($mail->to[0]['address'], ['guardian1@example.test', 'guardian2@example.test']);
        });
    }

    public function test_notification_with_mail_fake_dispatches_for_guardian_only(): void
    {
        Mail::fake();

        $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.execute'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'entire_class',
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
                'notify_student'   => false,
                'notify_guardian'  => true,
            ]);

        // Student 1 & 2 have guardians with emails
        Mail::assertQueued(FeeVoucherIssuedMail::class, function ($mail) {
            return in_array($mail->to[0]['address'], ['guardian1@example.test', 'guardian2@example.test']);
        });

        // No mail sent to student addresses
        Mail::assertNotQueued(FeeVoucherIssuedMail::class, function ($mail) {
            return in_array($mail->to[0]['address'], ['jewel@testacademy.test', 'tanvir@testacademy.test']);
        });
    }

    public function test_both_recipients_notification_sends_independent_emails(): void
    {
        Mail::fake();

        $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.execute'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'selected_students',
                'student_ids'      => [$this->student1->id],
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
                'notify_student'   => true,
                'notify_guardian'  => true,
            ]);

        // Student 1 has student email and guardian email -> 2 distinct emails queued
        Mail::assertQueued(FeeVoucherIssuedMail::class, 2);
        Mail::assertQueued(FeeVoucherIssuedMail::class, fn ($mail) => $mail->to[0]['address'] === 'jewel@testacademy.test');
        Mail::assertQueued(FeeVoucherIssuedMail::class, fn ($mail) => $mail->to[0]['address'] === 'guardian1@example.test');
    }

    public function test_missing_email_handled_gracefully(): void
    {
        Mail::fake();

        // Student 4 has neither student email nor guardian email
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.execute'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'selected_students',
                'student_ids'      => [$this->student4->id],
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
                'notify_student'   => true,
                'notify_guardian'  => true,
            ]);

        $response->assertOk();
        $this->assertEquals(1, $response->json('vouchers_generated'));
        $this->assertEquals(0, $response->json('notifications_queued'));
        $this->assertEquals(2, $response->json('missing_email_count')); // 1 missing student email + 1 missing guardian email
        Mail::assertNothingSent();
    }

    public function test_cross_tenant_request_rejected(): void
    {
        $school2 = School::create([
            'name'       => 'Foreign Academy',
            'domain'     => 'foreignacademy',
            'is_active'  => true,
            'created_by' => 1,
        ]);

        $classForeign = SchoolClass::create([
            'school_id'    => $school2->id,
            'name'         => 'Foreign Class',
            'numeric_name' => 1,
        ]);

        // Attempt to run preview with foreign class
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.preview'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $classForeign->id,
                'target_mode'      => 'entire_class',
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
            ]);

        $response->assertStatus(422); // Validation fails because class_id does not exist for school 1
    }

    public function test_unauthorized_users_are_rejected(): void
    {
        // 1. Teacher is denied
        $this->actingAs($this->teacherUser)
            ->get(route('school.fees.structures.bulk-assign'))
            ->assertStatus(403);

        $this->actingAs($this->teacherUser)
            ->postJson(route('school.fees.structures.bulk-assign.preview'), [])
            ->assertStatus(403);

        // 2. Accountant without explicit fees.bulk_bill is denied
        $this->actingAs($this->accountantUser)
            ->get(route('school.fees.structures.bulk-assign'))
            ->assertStatus(403);

        // 3. Accountant granted explicit fees.bulk_bill is allowed
        $this->accountantUser->givePermissionTo('fees.bulk_bill');
        $this->actingAs($this->accountantUser)
            ->get(route('school.fees.structures.bulk-assign'))
            ->assertOk();
    }

    public function test_copy_fee_structure_to_other_classes(): void
    {
        $class10 = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Class 10',
            'numeric_name' => 10,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('school.fees.structures.copy', $this->structure->id), [
                'target_class_ids' => [$class10->id],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('fee_structures', [
            'school_id'       => $this->school->id,
            'class_id'        => $class10->id,
            'fee_category_id' => $this->category->id,
            'academic_year'   => '2026-2027',
            'amount'          => '2000.00',
            'frequency'       => 'one_time',
        ]);
    }

    public function test_notification_failure_does_not_rollback_billing(): void
    {
        // Mock Mail facade to throw an exception on send
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Mail server connection failed'));

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('school.fees.structures.bulk-assign.execute'), [
                'fee_structure_id' => $this->structure->id,
                'academic_year_id' => $this->academicYear->id,
                'class_id'         => $this->class->id,
                'target_mode'      => 'selected_students',
                'student_ids'      => [$this->student1->id],
                'active_only'      => true,
                'execution_mode'   => 'assign_and_bill',
                'billing_label'    => 'Test Session 2026',
                'notify_student'   => true,
            ]);

        // Response is successful despite email failure
        $response->assertOk();
        $this->assertEquals(1, $response->json('vouchers_generated'));

        // Database records remain committed and intact
        $this->assertDatabaseHas('student_fee_assignments', [
            'school_id'        => $this->school->id,
            'student_id'       => $this->student1->id,
            'fee_structure_id' => $this->structure->id,
        ]);
        $this->assertDatabaseHas('fee_challans', [
            'school_id'          => $this->school->id,
            'student_id'         => $this->student1->id,
            'billing_period_key' => 'Test Session 2026',
        ]);
    }

    public function test_raw_id_not_rendered_as_user_label(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get(route('school.fees.structures.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Structures')
            ->has('structures.data.0', fn (Assert $s) => $s
                ->where('class_id', $this->class->id)
                ->where('school_class.name', 'Class 9')
                ->where('fee_category.name', 'Test Session Fee 2026')
                ->etc()
            )
            ->has('classes.0', fn (Assert $c) => $c
                ->where('id', $this->class->id)
                ->where('name', 'Class 9')
                ->etc()
            )
            ->has('categories.0', fn (Assert $cat) => $cat
                ->where('id', $this->category->id)
                ->where('name', 'Test Session Fee 2026')
                ->etc()
            )
        );
    }
}
