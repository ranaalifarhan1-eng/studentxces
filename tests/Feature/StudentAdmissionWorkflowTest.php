<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeChallan;
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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentAdmissionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $otherSchool;
    protected User $adminUser;
    protected User $accountantUser;
    protected User $teacherUser;
    protected AcademicYear $academicYear;
    protected SchoolClass $class;
    protected Section $section;
    protected FeeCategory $tuitionCategory;
    protected FeeCategory $transportCategory;
    protected FeeCategory $admissionCategory;
    protected FeeStructure $tuitionStructure;
    protected FeeStructure $transportStructure;
    protected FeeStructure $admissionStructure;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Permissions & Roles
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

        // 2. Setup Schools
        $this->school = School::create([
            'name'     => 'Greenfield Academy Test',
            'slug'     => 'greenfield-test-' . uniqid(),
            'email'    => 'info@greenfield-' . uniqid() . '.edu.pk',
            'status'   => 'active',
            'timezone' => 'Asia/Karachi',
        ]);

        $this->otherSchool = School::create([
            'name'     => 'Foreign Academy',
            'slug'     => 'foreign-' . uniqid(),
            'email'    => 'foreign@edu.pk',
            'status'   => 'active',
            'timezone' => 'Asia/Karachi',
        ]);

        // 3. Setup Package & Subscription with both students and fees modules
        $package = Package::create([
            'name'          => 'Enterprise Plan',
            'slug'          => 'enterprise-' . uniqid(),
            'price_monthly' => 50,
            'price_yearly'  => 500,
            'max_students'  => 1000,
            'max_staff'     => 100,
            'storage_gb'    => 50,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'students']);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'fees']);

        SchoolSubscription::create([
            'school_id'   => $this->school->id,
            'package_id'  => $package->id,
            'status'      => 'active',
            'start_date'  => Carbon::now()->subDays(10),
            'end_date'    => Carbon::now()->addDays(30),
        ]);

        // 4. Setup Users
        $this->adminUser = User::create([
            'name'              => 'Admin Greenfield',
            'email'             => 'admin-' . uniqid() . '@greenfield.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'status'            => 'active',
            'email_verified_at' => now(),
        ]);
        $this->adminUser->assignRole($adminRole);

        $this->accountantUser = User::create([
            'name'              => 'Accountant Greenfield',
            'email'             => 'accountant-' . uniqid() . '@greenfield.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'status'            => 'active',
            'email_verified_at' => now(),
        ]);
        $this->accountantUser->assignRole($accountantRole);

        $this->teacherUser = User::create([
            'name'              => 'Teacher Greenfield',
            'email'             => 'teacher-' . uniqid() . '@greenfield.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'status'            => 'active',
            'email_verified_at' => now(),
        ]);
        $this->teacherUser->assignRole($teacherRole);

        // 5. Setup Academic Year
        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date'   => '2027-05-31',
            'is_current' => true,
        ]);

        // 6. Setup Class & Section
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

        // 7. Setup Categories
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

        // 8. Setup Fee Structures: Mandatory Tuition, Optional Transport, Mandatory One-Time Admission
        $this->tuitionStructure = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 5000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false, // Mandatory
        ]);

        $this->transportStructure = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->transportCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 1500.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => true, // Optional
        ]);

        $this->admissionStructure = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $this->admissionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 3000.00,
            'frequency'       => 'one_time',
            'is_active'       => true,
            'is_optional'     => false, // Mandatory One-Time
        ]);
    }

    protected function validAdmissionPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'first_name'             => 'Ali',
            'last_name'              => 'Khan',
            'gender'                 => 'male',
            'category'               => 'general',
            'status'                 => 'active',
            'admission_date'         => '2026-09-18',
            'roll_no'                => '101',
            'class_id'               => $this->class->id,
            'section_id'             => $this->section->id,
            'guardian'               => [
                'name'     => 'Tariq Khan',
                'relation' => 'Father',
                'phone'    => '+923001234567',
            ],
            'academic_year_id'       => $this->academicYear->id,
            'billing_start_month'    => '2026-09',
            'initialize_fees'        => true,
            'generate_first_challan' => false,
            'due_date'               => '2026-09-25',
        ], $overrides);
    }

    /** 1. admission payload initializes mandatory assignments */
    public function test_admission_payload_initializes_mandatory_assignments(): void
    {
        $payload = $this->validAdmissionPayload([
            'fee_structure_ids' => [], // Operator explicitly selects nothing optional
        ]);

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);

        $response->assertSessionHasNoErrors();
        $student = Student::where('first_name', 'Ali')->where('last_name', 'Khan')->first();
        $this->assertNotNull($student);
        $response->assertRedirect(route('school.students.show', $student));

        // Invariant: Both mandatory structures (Tuition + Admission) are automatically assigned
        $assignments = StudentFeeAssignment::where('student_id', $student->id)->get();
        $this->assertCount(2, $assignments);
        $this->assertTrue($assignments->pluck('fee_structure_id')->contains($this->tuitionStructure->id));
        $this->assertTrue($assignments->pluck('fee_structure_id')->contains($this->admissionStructure->id));
        $this->assertFalse($assignments->pluck('fee_structure_id')->contains($this->transportStructure->id));
    }

    /** 2. selected optional assignment created */
    public function test_selected_optional_assignment_created(): void
    {
        $payload = $this->validAdmissionPayload([
            'fee_structure_ids' => [$this->transportStructure->id],
        ]);

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);

        $response->assertSessionHasNoErrors();
        $student = Student::where('first_name', 'Ali')->first();

        $assignments = StudentFeeAssignment::where('student_id', $student->id)->get();
        $this->assertCount(3, $assignments);
        $this->assertTrue($assignments->pluck('fee_structure_id')->contains($this->tuitionStructure->id));
        $this->assertTrue($assignments->pluck('fee_structure_id')->contains($this->admissionStructure->id));
        $this->assertTrue($assignments->pluck('fee_structure_id')->contains($this->transportStructure->id));
    }

    /** 3. unselected optional not assigned */
    public function test_unselected_optional_not_assigned(): void
    {
        $hostelCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Hostel Fee',
            'type'      => 'hostel',
            'is_active' => true,
        ]);

        $hostelStructure = FeeStructure::create([
            'school_id'       => $this->school->id,
            'class_id'        => $this->class->id,
            'fee_category_id' => $hostelCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 4000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => true,
        ]);

        // Select transport, but omit hostel
        $payload = $this->validAdmissionPayload([
            'fee_structure_ids' => [$this->transportStructure->id],
        ]);

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Ali')->first();
        $assignments = StudentFeeAssignment::where('student_id', $student->id)->get();

        $this->assertTrue($assignments->pluck('fee_structure_id')->contains($this->transportStructure->id));
        $this->assertFalse($assignments->pluck('fee_structure_id')->contains($hostelStructure->id));
    }

    /** 4. admission discount created with permission */
    public function test_admission_discount_created_with_permission(): void
    {
        $payload = $this->validAdmissionPayload([
            'concession' => [
                'title' => 'Sibling Concession',
                'type'  => 'percentage',
                'value' => 20,
            ],
        ]);

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Ali')->first();
        $this->assertDatabaseHas('student_fee_discounts', [
            'school_id'  => $this->school->id,
            'student_id' => $student->id,
            'title'      => 'Sibling Concession',
            'type'       => 'percentage',
            'value'      => 20.00,
            'is_active'  => true,
        ]);
    }

    /** 5. admission discount denied without permission */
    public function test_admission_discount_denied_without_permission(): void
    {
        // Accountant has fees.assign but lacks fees.discount
        $payload = $this->validAdmissionPayload([
            'concession' => [
                'title' => 'Unauthorized Discount',
                'type'  => 'percentage',
                'value' => 50,
            ],
        ]);

        $response = $this->actingAs($this->accountantUser)->post('/school/students', $payload);
        $response->assertStatus(403);

        $this->assertDatabaseMissing('students', ['first_name' => 'Ali']);
    }

    /** 6. student still creatable without fees.assign */
    public function test_student_still_creatable_without_fees_assign(): void
    {
        // Teacher has no fees.assign, submits non-financial student admission
        $payload = [
            'first_name'     => 'Fatima',
            'last_name'      => 'Zahra',
            'gender'         => 'female',
            'category'       => 'general',
            'status'         => 'active',
            'admission_date' => '2026-09-18',
            'roll_no'        => '102',
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'guardian'       => [
                'name'     => 'Zahra Ahmed',
                'relation' => 'Mother',
            ],
        ];

        $response = $this->actingAs($this->teacherUser)->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Fatima')->first();
        $this->assertNotNull($student);
        $response->assertRedirect(route('school.students.show', $student));

        // Zero fee assignments created
        $this->assertEquals(0, StudentFeeAssignment::where('student_id', $student->id)->count());
    }

    /** 7. forged assignment payload denied */
    public function test_forged_assignment_payload_denied(): void
    {
        // Teacher tries to forge financial assignment payload
        $payload = [
            'first_name'        => 'Forged',
            'last_name'         => 'Student',
            'gender'            => 'male',
            'category'          => 'general',
            'status'            => 'active',
            'class_id'          => $this->class->id,
            'section_id'        => $this->section->id,
            'guardian'          => [
                'name'     => 'Guardian',
                'relation' => 'Father',
            ],
            'initialize_fees'   => true,
            'fee_structure_ids' => [$this->tuitionStructure->id],
        ];

        $response = $this->actingAs($this->teacherUser)->post('/school/students', $payload);
        $response->assertStatus(403);

        $this->assertDatabaseMissing('students', ['first_name' => 'Forged']);
    }

    /** 8. first challan generated when selected */
    public function test_first_challan_generated_when_selected(): void
    {
        $payload = $this->validAdmissionPayload([
            'fee_structure_ids'      => [$this->transportStructure->id],
            'generate_first_challan' => true,
            'billing_start_month'    => '2026-09',
            'due_date'               => '2026-09-25',
        ]);

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Ali')->first();
        $this->assertNotNull($student);

        // Flash message contains voucher notice
        $response->assertSessionHas('success', 'Student admitted and first fee voucher generated successfully.');

        // Verify FeeChallan created
        $challan = FeeChallan::where('student_id', $student->id)->first();
        $this->assertNotNull($challan);
        $this->assertEquals('unpaid', $challan->status);
        $this->assertEquals('2026-09-25', $challan->due_date->toDateString());
    }

    /** 9. no first challan when unchecked */
    public function test_no_first_challan_when_unchecked(): void
    {
        $payload = $this->validAdmissionPayload([
            'generate_first_challan' => false,
        ]);

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Ali')->first();
        $response->assertSessionHas('success', 'Student admitted successfully.');
        $this->assertEquals(0, FeeChallan::where('student_id', $student->id)->count());
    }

    /** 10. no FeePayment created during admission */
    public function test_no_fee_payment_created_during_admission(): void
    {
        $payload = $this->validAdmissionPayload([
            'generate_first_challan' => true,
            'due_date'               => '2026-09-25',
        ]);

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Ali')->first();
        $this->assertEquals(0, FeePayment::where('student_id', $student->id)->count());
    }

    /** 11. first challan contains mandatory + selected optional fees */
    public function test_first_challan_contains_mandatory_plus_selected_optional_fees(): void
    {
        $payload = $this->validAdmissionPayload([
            'fee_structure_ids'      => [$this->transportStructure->id],
            'generate_first_challan' => true,
            'due_date'               => '2026-09-25',
        ]);

        $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $student = Student::where('first_name', 'Ali')->first();

        $challan = FeeChallan::where('student_id', $student->id)->with('items')->first();
        $this->assertNotNull($challan);

        // Mandatory Tuition (5000) + Optional Transport (1500) + Mandatory One-Time Admission (3000) = 9500
        $this->assertEquals('9500.00', $challan->gross_amount);
        $this->assertEquals('9500.00', $challan->total_payable);

        $itemStructureIds = $challan->items->pluck('fee_structure_id')->toArray();
        $this->assertContains($this->tuitionStructure->id, $itemStructureIds);
        $this->assertContains($this->transportStructure->id, $itemStructureIds);
        $this->assertContains($this->admissionStructure->id, $itemStructureIds);
    }

    /** 12. first challan applies admission concession */
    public function test_first_challan_applies_admission_concession(): void
    {
        $payload = $this->validAdmissionPayload([
            'fee_structure_ids'      => [$this->transportStructure->id],
            'generate_first_challan' => true,
            'due_date'               => '2026-09-25',
            'concession'             => [
                'title'           => 'Sibling Concession',
                'fee_category_id' => $this->tuitionCategory->id,
                'type'            => 'percentage',
                'value'           => 20, // 20% on 5,000 = 1,000 discount
            ],
        ]);

        $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $student = Student::where('first_name', 'Ali')->first();

        $challan = FeeChallan::where('student_id', $student->id)->first();
        $this->assertNotNull($challan);
        $this->assertEquals('9500.00', $challan->gross_amount);
        $this->assertEquals('8500.00', $challan->total_payable); // 9500 - 1000
    }

    /** 13. one-time admission fee included once */
    public function test_one_time_admission_fee_included_once(): void
    {
        // First admission challan
        $payload = $this->validAdmissionPayload([
            'generate_first_challan' => true,
            'billing_start_month'    => '2026-09',
            'due_date'               => '2026-09-25',
        ]);
        $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $student = Student::where('first_name', 'Ali')->first();

        $firstChallan = FeeChallan::where('student_id', $student->id)->with('items')->first();
        $this->assertTrue($firstChallan->items->contains('fee_structure_id', $this->admissionStructure->id));

        // Next month billing (October)
        $octoberMonth = Carbon::parse('2026-10-01');
        $secondChallan = FeeBillingService::createChallan(
            $student,
            $this->academicYear,
            $octoberMonth,
            $octoberMonth->copy()->endOfMonth()
        );

        // One-time admission fee must NOT appear in subsequent challan
        $this->assertFalse($secondChallan->items->contains('fee_structure_id', $this->admissionStructure->id));
    }

    /** 14. transaction rolls back if financial initialization fails */
    public function test_transaction_rolls_back_if_financial_initialization_fails(): void
    {
        // Due date before start period triggers ValidationException
        $payload = $this->validAdmissionPayload([
            'billing_start_month'    => '2026-09',
            'generate_first_challan' => true,
            'due_date'               => '2026-08-15', // Before September!
        ]);

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasErrors(['due_date']);

        // Invariant: Transaction rolled back; student and guardian were NOT created
        $this->assertDatabaseMissing('students', ['first_name' => 'Ali']);
        $this->assertDatabaseMissing('guardians', ['name' => 'Tariq Khan']);
    }

    /** 15. cross-tenant class/fee IDs rejected */
    public function test_cross_tenant_class_or_fee_ids_rejected(): void
    {
        $foreignClass = SchoolClass::create([
            'school_id'    => $this->otherSchool->id,
            'name'         => 'Foreign Class',
            'numeric_name' => 5,
        ]);

        $foreignFeeStructure = FeeStructure::create([
            'school_id'       => $this->otherSchool->id,
            'class_id'        => $foreignClass->id,
            'fee_category_id' => $this->tuitionCategory->id,
            'academic_year'   => '2026-2027',
            'amount'          => 7000.00,
            'frequency'       => 'monthly',
            'is_active'       => true,
            'is_optional'     => false,
        ]);

        // Attempt 1: Foreign class ID
        $payload1 = $this->validAdmissionPayload(['class_id' => $foreignClass->id]);
        $response1 = $this->actingAs($this->adminUser)->post('/school/students', $payload1);
        $response1->assertSessionHasErrors(['class_id']);

        // Attempt 2: Foreign fee structure ID
        $payload2 = $this->validAdmissionPayload([
            'fee_structure_ids' => [$foreignFeeStructure->id],
        ]);
        $response2 = $this->actingAs($this->adminUser)->post('/school/students', $payload2);
        $response2->assertSessionHasErrors(['fee_structure_ids.0']);

        $this->assertDatabaseMissing('students', ['first_name' => 'Ali']);
    }

    /** 16. billing start month normalized */
    public function test_billing_start_month_normalized(): void
    {
        $payload = $this->validAdmissionPayload([
            'billing_start_month' => '2026-09-18', // Mid-month date string
        ]);

        $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $student = Student::where('first_name', 'Ali')->first();

        $assignment = StudentFeeAssignment::where('student_id', $student->id)->first();
        $this->assertNotNull($assignment);
        // Normalized to first day of month
        $this->assertEquals('2026-09-01', $assignment->starts_on->toDateString());
    }

    /** 17. review payload totals match backend calculation */
    public function test_review_payload_totals_match_backend_calculation(): void
    {
        $payload = $this->validAdmissionPayload([
            'fee_structure_ids'      => [$this->transportStructure->id],
            'generate_first_challan' => true,
            'due_date'               => '2026-09-25',
            'concession'             => [
                'title'           => 'Sibling Concession',
                'fee_category_id' => $this->tuitionCategory->id,
                'type'            => 'fixed',
                'value'           => 1000,
            ],
        ]);

        $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $student = Student::where('first_name', 'Ali')->first();

        $challan = FeeChallan::where('student_id', $student->id)->first();
        // Gross: 5000 (Tuition) + 1500 (Transport) + 3000 (Admission) = 9500
        // Concession: 1000 fixed
        // Net: 8500
        $this->assertEquals('9500.00', $challan->gross_amount);
        $this->assertEquals('8500.00', $challan->total_payable);
    }

    /** 18. dedicated tenant-scoped fee structures endpoint works */
    public function test_dedicated_tenant_scoped_fee_structures_endpoint(): void
    {
        $response = $this->actingAs($this->adminUser)->getJson(
            route('school.students.fee-structures', [
                'class_id'      => $this->class->id,
                'academic_year' => $this->academicYear->name,
            ])
        );

        $response->assertOk();
        $data = $response->json();

        $this->assertCount(3, $data);
        $ids = array_column($data, 'id');
        $this->assertContains($this->tuitionStructure->id, $ids);
        $this->assertContains($this->transportStructure->id, $ids);
        $this->assertContains($this->admissionStructure->id, $ids);
    }

    /** 19. mandatory assignment excluded from first voucher when policy is optional and operator omits it */
    public function test_mandatory_assignment_excluded_from_first_voucher_when_policy_is_optional(): void
    {
        $this->tuitionStructure->update(['admission_voucher_policy' => 'optional']);
        $this->admissionStructure->update(['admission_voucher_policy' => 'required']);

        $payload = $this->validAdmissionPayload([
            'generate_first_challan'       => true,
            'first_voucher_structure_ids'  => [$this->admissionStructure->id], // Omits tuition
        ]);

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Ali')->first();
        $this->assertNotNull($student);

        // Invariant: Mandatory tuition assignment is still created and active on student account
        $tuitionAssignment = StudentFeeAssignment::where('student_id', $student->id)
            ->where('fee_structure_id', $this->tuitionStructure->id)
            ->first();
        $this->assertNotNull($tuitionAssignment);
        $this->assertTrue($tuitionAssignment->is_active);

        // Challan created with Admission Fee only (3000.00), Tuition (5000.00) is excluded
        $challan = FeeChallan::where('student_id', $student->id)->first();
        $this->assertNotNull($challan);
        $this->assertEquals('3000.00', $challan->gross_amount);
        $this->assertEquals('3000.00', $challan->total_payable);
        $this->assertCount(1, $challan->items);
        $this->assertEquals($this->admissionStructure->id, $challan->items->first()->fee_structure_id);
    }

    /** 20. required first voucher fee cannot be omitted */
    public function test_required_first_voucher_fee_cannot_be_omitted(): void
    {
        $this->admissionStructure->update(['admission_voucher_policy' => 'required']);
        $this->tuitionStructure->update(['admission_voucher_policy' => 'optional']);

        // Operator attempts to generate first voucher omitting Admission Fee
        $payload = $this->validAdmissionPayload([
            'generate_first_challan'      => true,
            'first_voucher_structure_ids' => [$this->tuitionStructure->id],
        ]);

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasErrors(['first_voucher_structure_ids']);

        // Atomic transaction rolled back
        $this->assertDatabaseMissing('students', ['first_name' => 'Ali']);
        $this->assertDatabaseMissing('fee_challans', ['school_id' => $this->school->id]);
    }

    /** 21. excluded admission fee head cannot be included on first voucher */
    public function test_excluded_admission_fee_head_cannot_be_included_on_first_voucher(): void
    {
        $this->admissionStructure->update(['admission_voucher_policy' => 'required']);

        $examCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Annual Exam Fee',
            'type'      => 'exam',
            'is_active' => true,
        ]);

        $examStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'fee_category_id'          => $examCategory->id,
            'academic_year'            => '2026-2027',
            'amount'                   => 2000.00,
            'frequency'                => 'annual',
            'is_active'                => true,
            'is_optional'              => false,
            'admission_voucher_policy' => 'excluded',
        ]);

        // Operator attempts to include excluded exam fee on first voucher
        $payload = $this->validAdmissionPayload([
            'generate_first_challan'      => true,
            'first_voucher_structure_ids' => [$this->admissionStructure->id, $examStructure->id],
        ]);

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasErrors(['first_voucher_structure_ids']);

        $this->assertDatabaseMissing('students', ['first_name' => 'Ali']);
    }

    /** 22. optional first voucher fee can be toggled independently of assignment */
    public function test_optional_first_voucher_fee_can_be_toggled_independently_of_assignment(): void
    {
        $this->admissionStructure->update(['admission_voucher_policy' => 'required']);
        $this->tuitionStructure->update(['admission_voucher_policy' => 'optional']);
        $this->transportStructure->update(['admission_voucher_policy' => 'optional']);

        // Transport is assigned to the student, but omitted from first voucher
        $payload = $this->validAdmissionPayload([
            'fee_structure_ids'           => [$this->transportStructure->id], // Transport assigned
            'generate_first_challan'      => true,
            'first_voucher_structure_ids' => [$this->admissionStructure->id, $this->tuitionStructure->id], // Transport omitted from voucher
        ]);

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Ali')->first();
        $this->assertNotNull($student);

        // Transport is assigned on account
        $transportAssignment = StudentFeeAssignment::where('student_id', $student->id)
            ->where('fee_structure_id', $this->transportStructure->id)
            ->first();
        $this->assertNotNull($transportAssignment);
        $this->assertTrue($transportAssignment->is_active);

        // Challan has Tuition (5000) + Admission (3000) = 8000; Transport (1500) is NOT on voucher
        $challan = FeeChallan::where('student_id', $student->id)->first();
        $this->assertEquals('8000.00', $challan->gross_amount);
        $this->assertFalse($challan->items->pluck('fee_structure_id')->contains($this->transportStructure->id));
    }

    /** 23. unchecking first voucher inclusion does not deactivate StudentFeeAssignment */
    public function test_unchecking_first_voucher_inclusion_does_not_deactivate_assignment(): void
    {
        $this->tuitionStructure->update(['admission_voucher_policy' => 'optional']);

        $payload = $this->validAdmissionPayload([
            'generate_first_challan'      => true,
            'first_voucher_structure_ids' => [$this->admissionStructure->id], // Tuition omitted
        ]);

        $this->actingAs($this->adminUser)->post('/school/students', $payload);

        $student = Student::where('first_name', 'Ali')->first();
        $assignment = StudentFeeAssignment::where('student_id', $student->id)
            ->where('fee_structure_id', $this->tuitionStructure->id)
            ->first();

        $this->assertTrue($assignment->is_active);
        $this->assertNull($assignment->deactivated_at);
        $this->assertNull($assignment->deactivation_reason);
    }

    /** 24. normal future billing continues to include assigned fees omitted from first voucher */
    public function test_normal_future_billing_continues_to_include_assigned_fees_omitted_from_first_voucher(): void
    {
        $this->tuitionStructure->update(['admission_voucher_policy' => 'optional']);

        // Admit student with Tuition omitted from first voucher
        $payload = $this->validAdmissionPayload([
            'billing_start_month'         => '2026-09',
            'generate_first_challan'      => true,
            'first_voucher_structure_ids' => [$this->admissionStructure->id],
        ]);
        $this->actingAs($this->adminUser)->post('/school/students', $payload);

        $student = Student::where('first_name', 'Ali')->first();

        // Perform standard regular billing for October 2026
        $octoberChallan = FeeBillingService::createChallan(
            $student,
            $this->academicYear,
            Carbon::parse('2026-10-01')
        );

        $this->assertNotNull($octoberChallan);
        $this->assertTrue($octoberChallan->items->pluck('fee_structure_id')->contains($this->tuitionStructure->id));
        $this->assertEquals('5000.00', $octoberChallan->gross_amount);
    }

    /** 25. one time fee excluded from admission remains eligible for later billing */
    public function test_one_time_fee_excluded_from_admission_remains_eligible_for_later_billing(): void
    {
        $labCategory = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Annual Lab Fee',
            'type'      => 'other',
            'is_active' => true,
        ]);

        $labStructure = FeeStructure::create([
            'school_id'                => $this->school->id,
            'class_id'                 => $this->class->id,
            'fee_category_id'          => $labCategory->id,
            'academic_year'            => '2026-2027',
            'amount'                   => 2500.00,
            'frequency'                => 'one_time',
            'is_active'                => true,
            'is_optional'              => false, // Mandatory class assignment
            'admission_voucher_policy' => 'excluded', // But excluded from first voucher
        ]);

        $payload = $this->validAdmissionPayload([
            'generate_first_challan'      => true,
            'first_voucher_structure_ids' => [$this->admissionStructure->id],
        ]);

        $this->actingAs($this->adminUser)->post('/school/students', $payload);

        $student = Student::where('first_name', 'Ali')->first();

        // 1st voucher has only admission fee
        $firstChallan = FeeChallan::where('student_id', $student->id)->first();
        $this->assertFalse($firstChallan->items->pluck('fee_structure_id')->contains($labStructure->id));

        // Subsequent billing run picks up the one-time lab fee
        $octoberChallan = FeeBillingService::createChallan(
            $student,
            $this->academicYear,
            Carbon::parse('2026-10-01')
        );

        $this->assertTrue($octoberChallan->items->pluck('fee_structure_id')->contains($labStructure->id));
    }

    /** 26. first voucher calculation includes selected fees only */
    public function test_first_voucher_calculation_includes_selected_fees_only(): void
    {
        $this->tuitionStructure->update(['admission_voucher_policy' => 'optional']);

        $payload = $this->validAdmissionPayload([
            'generate_first_challan'      => true,
            'first_voucher_structure_ids' => [$this->admissionStructure->id],
        ]);

        $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $student = Student::where('first_name', 'Ali')->first();

        $challan = FeeChallan::where('student_id', $student->id)->first();
        // Gross and payable reflect only Admission Fee (3000), not Tuition (5000)
        $this->assertEquals('3000.00', $challan->gross_amount);
        $this->assertEquals('3000.00', $challan->total_payable);
    }

    /** 27. concession applies only to first voucher applicable fees */
    public function test_concession_applies_only_to_first_voucher_applicable_fees(): void
    {
        $this->tuitionStructure->update(['admission_voucher_policy' => 'optional']);

        // Concession target is Tuition Category (1000 fixed), but Tuition is omitted from first voucher
        $payload = $this->validAdmissionPayload([
            'generate_first_challan'      => true,
            'first_voucher_structure_ids' => [$this->admissionStructure->id], // Admission fee only (3000)
            'concession'                  => [
                'title'           => 'Tuition Scholarship',
                'fee_category_id' => $this->tuitionCategory->id,
                'type'            => 'fixed',
                'value'           => 1000,
            ],
        ]);

        $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $student = Student::where('first_name', 'Ali')->first();

        // 1st voucher has 0 concession because tuition is not on voucher
        $firstChallan = FeeChallan::where('student_id', $student->id)->first();
        $this->assertEquals('3000.00', $firstChallan->gross_amount);
        $this->assertEquals('3000.00', $firstChallan->total_payable);
        $this->assertEquals(0, $firstChallan->items->first()->discount_amount);

        // Student's account has the registered concession saved
        $discount = StudentFeeDiscount::where('student_id', $student->id)->first();
        $this->assertNotNull($discount);
        $this->assertEquals('Tuition Scholarship', $discount->title);

        // When regular billing runs next month with Tuition Fee, the discount applies!
        $octoberChallan = FeeBillingService::createChallan(
            $student,
            $this->academicYear,
            Carbon::parse('2026-10-01')
        );
        // Tuition: 5000 - 1000 = 4000
        $this->assertEquals('5000.00', $octoberChallan->gross_amount);
        $this->assertEquals('4000.00', $octoberChallan->total_payable);
    }

    /** 28. cross-tenant first voucher fee ids rejected */
    public function test_cross_tenant_first_voucher_fee_ids_rejected(): void
    {
        $foreignClass = SchoolClass::create([
            'school_id'    => $this->otherSchool->id,
            'name'         => 'Foreign Class',
            'numeric_name' => 10,
        ]);

        $foreignStructure = FeeStructure::create([
            'school_id'                => $this->otherSchool->id,
            'class_id'                 => $foreignClass->id,
            'fee_category_id'          => $this->tuitionCategory->id,
            'academic_year'            => '2026-2027',
            'amount'                   => 7000.00,
            'frequency'                => 'monthly',
            'is_active'                => true,
            'is_optional'              => false,
            'admission_voucher_policy' => 'optional',
        ]);

        $payload = $this->validAdmissionPayload([
            'generate_first_challan'      => true,
            'first_voucher_structure_ids' => [$foreignStructure->id],
        ]);

        $response = $this->actingAs($this->adminUser)->post('/school/students', $payload);
        $response->assertSessionHasErrors(['first_voucher_structure_ids.0']);

        $this->assertDatabaseMissing('students', ['first_name' => 'Ali']);
    }
}
