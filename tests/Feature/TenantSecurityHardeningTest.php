<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\BookIssue;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Exam;
use App\Models\FeeCategory;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\Homework;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelRoom;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryPurchase;
use App\Models\LeaveType;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Staff;
use App\Models\StaffDocument;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Timetable;
use App\Models\TransportRoute;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantSecurityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    protected School $schoolA;
    protected School $schoolB;
    protected User $adminA;
    protected User $adminB;
    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake('public');

        // Ensure roles exist
        Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        // Create School A
        $this->schoolA = School::create([
            'name'   => 'School Alpha Test',
            'slug'   => 'school-alpha-test-' . uniqid(),
            'code'   => 'SAT' . rand(1000, 9999),
            'status' => 'active',
        ]);

        // Create School B
        $this->schoolB = School::create([
            'name'   => 'School Beta Test',
            'slug'   => 'school-beta-test-' . uniqid(),
            'code'   => 'SBT' . rand(1000, 9999),
            'status' => 'active',
        ]);

        $pkg = \App\Models\Package::firstOrCreate(
            ['slug' => 'test-all-access-pkg'],
            [
                'name'          => 'Test All Access',
                'currency'      => 'PKR',
                'price_monthly' => 100,
                'is_active'     => true,
                'is_internal'   => false,
            ]
        );

        \App\Models\SchoolSubscription::create([
            'school_id'   => $this->schoolA->id,
            'package_id'  => $pkg->id,
            'start_date'  => now()->subDay(),
            'end_date'    => now()->addYear(),
            'status'      => 'active',
            'amount_paid' => 100,
        ]);

        \App\Models\SchoolSubscription::create([
            'school_id'   => $this->schoolB->id,
            'package_id'  => $pkg->id,
            'start_date'  => now()->subDay(),
            'end_date'    => now()->addYear(),
            'status'      => 'active',
            'amount_paid' => 100,
        ]);

        // Create Admin A
        $this->adminA = User::create([
            'school_id' => $this->schoolA->id,
            'name'      => 'Admin Alpha',
            'email'     => 'admin.alpha.' . uniqid() . '@test.com',
            'password'  => bcrypt('password'),
            'status'    => 'active',
        ]);
        $this->adminA->assignRole('school-admin');

        // Create Admin B
        $this->adminB = User::create([
            'school_id' => $this->schoolB->id,
            'name'      => 'Admin Beta',
            'email'     => 'admin.beta.' . uniqid() . '@test.com',
            'password'  => bcrypt('password'),
            'status'    => 'active',
        ]);
        $this->adminB->assignRole('school-admin');

        // Create Super Admin
        $this->superAdmin = User::create([
            'school_id' => null,
            'name'      => 'Super Admin',
            'email'     => 'superadmin.' . uniqid() . '@test.com',
            'password'  => bcrypt('password'),
            'status'    => 'active',
        ]);
        $this->superAdmin->assignRole('super-admin');
    }

    /**
     * Requirement A: StaffDocument cross-tenant IDOR protection.
     */
    public function test_school_a_cannot_delete_school_b_staff_document(): void
    {
        $staffB = Staff::create([
            'school_id'  => $this->schoolB->id,
            'first_name' => 'John',
            'last_name'  => 'Beta',
            'gender'     => 'male',
            'salary_type'=> 'fixed',
            'status'     => 'active',
        ]);

        $docB = StaffDocument::create([
            'school_id'  => $this->schoolB->id,
            'staff_id'   => $staffB->id,
            'title'      => 'Beta Contract',
            'file_path'  => 'staff/' . $staffB->id . '/documents/contract.pdf',
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        // Attempt deletion by Admin A -> Must be denied (404 due to SchoolScope or 403 defense)
        $response = $this->actingAs($this->adminA)
            ->delete('/school/staff/documents/' . $docB->id);

        $this->assertTrue(in_array($response->status(), [403, 404]));
        $this->assertDatabaseHas('staff_documents', ['id' => $docB->id]);
    }

    /**
     * Requirement A: Super Admin can manage staff documents globally.
     */
    public function test_super_admin_can_delete_staff_document(): void
    {
        $staffB = Staff::create([
            'school_id'  => $this->schoolB->id,
            'first_name' => 'John',
            'last_name'  => 'Beta',
            'gender'     => 'male',
            'salary_type'=> 'fixed',
            'status'     => 'active',
        ]);

        $docB = StaffDocument::create([
            'school_id'  => $this->schoolB->id,
            'staff_id'   => $staffB->id,
            'title'      => 'Beta Contract Super',
            'file_path'  => 'staff/' . $staffB->id . '/documents/contract_super.pdf',
            'file_type'  => 'application/pdf',
            'file_size'  => 1024,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->withSession(['active_school_id' => $this->schoolB->id])
            ->delete('/school/staff/documents/' . $docB->id);

        $response->assertStatus(302);
        $this->assertDatabaseMissing('staff_documents', ['id' => $docB->id]);
    }

    /**
     * Requirement B: AcademicYear tenant scoping.
     */
    public function test_academic_year_is_properly_scoped_to_school(): void
    {
        $ayA = AcademicYear::create([
            'school_id'  => $this->schoolA->id,
            'name'       => '2026-2027 Alpha',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
            'is_current' => true,
        ]);

        $ayB = AcademicYear::create([
            'school_id'  => $this->schoolB->id,
            'name'       => '2026-2027 Beta',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
            'is_current' => true,
        ]);

        // When authenticated as Admin A, queries on AcademicYear must only return School A's records
        $this->actingAs($this->adminA);
        $scopedYears = AcademicYear::all();

        $this->assertTrue($scopedYears->contains('id', $ayA->id));
        $this->assertFalse($scopedYears->contains('id', $ayB->id));

        // makeCurrent should only affect School A
        $ayA2 = AcademicYear::create([
            'school_id'  => $this->schoolA->id,
            'name'       => '2027-2028 Alpha',
            'start_date' => '2027-01-01',
            'end_date'   => '2027-12-31',
            'is_current' => false,
        ]);

        $ayA2->makeCurrent();

        $this->assertFalse($ayA->fresh()->is_current);
        $this->assertTrue($ayA2->fresh()->is_current);
        $this->assertTrue($ayB->fresh()->is_current); // School B remains untouched
    }

    /**
     * Requirement C: Audit Log cross-school leak restriction.
     */
    public function test_school_a_audit_log_does_not_contain_school_b_activity(): void
    {
        // Activity for School A
        activity()
            ->causedBy($this->adminA)
            ->withProperties(['school_id' => $this->schoolA->id])
            ->log('Alpha Action Logged');

        // Activity for School B
        activity()
            ->causedBy($this->adminB)
            ->withProperties(['school_id' => $this->schoolB->id])
            ->log('Beta Confidential Action');

        $response = $this->actingAs($this->adminA)->get('/school/reports/audit-log');
        $response->assertStatus(200);

        $page = $response->viewData('page');
        $logs = collect($page['props']['logs']['data'] ?? []);

        // Assert logs contain School A's log but not School B's log
        $this->assertTrue($logs->contains(fn ($l) => $l['description'] === 'Alpha Action Logged'));
        $this->assertFalse($logs->contains(fn ($l) => $l['description'] === 'Beta Confidential Action'));
    }

    /**
     * Requirement D: Cross-Tenant Foreign Key Injection — Student / Class / Fee Payment.
     */
    public function test_school_a_cannot_create_fee_payment_for_school_b_student_or_structure(): void
    {
        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'name' => 'Class 1A', 'numeric_name' => 1]);
        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'name' => 'Class 1B', 'numeric_name' => 1]);

        $studentB = Student::create([
            'school_id'  => $this->schoolB->id,
            'class_id'   => $classB->id,
            'first_name' => 'Student',
            'last_name'  => 'Beta',
            'gender'     => 'male',
            'category'   => 'general',
            'status'     => 'active',
        ]);

        $catA = FeeCategory::create(['school_id' => $this->schoolA->id, 'name' => 'Tuition Alpha', 'type' => 'tuition']);
        $structA = FeeStructure::create([
            'school_id'       => $this->schoolA->id,
            'class_id'        => $classA->id,
            'fee_category_id' => $catA->id,
            'academic_year'   => '2026',
            'amount'          => 1000,
            'frequency'       => 'monthly',
        ]);

        // Attempt payment for School B's student
        $response = $this->actingAs($this->adminA)->post('/school/fees/payments', [
            'student_id'       => $studentB->id,
            'fee_structure_id' => $structA->id,
            'amount_due'       => 1000,
            'amount_paid'      => 1000,
            'payment_date'     => now()->toDateString(),
            'method'           => 'cash',
        ]);

        $response->assertSessionHasErrors(['student_id']);
    }

    /**
     * Requirement D: Cross-Tenant Foreign Key Injection — Student Admission with School B Class.
     */
    public function test_school_a_cannot_admit_student_into_school_b_class(): void
    {
        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'name' => 'Class 1B', 'numeric_name' => 1]);

        $response = $this->actingAs($this->adminA)->post('/school/students', [
            'first_name' => 'New',
            'last_name'  => 'Student',
            'gender'     => 'male',
            'category'   => 'general',
            'status'     => 'active',
            'class_id'   => $classB->id,
            'guardian'   => [
                'name'     => 'Guardian Name',
                'relation' => 'Father',
            ],
        ]);

        $response->assertSessionHasErrors(['class_id']);
    }

    /**
     * Requirement D: Cross-Tenant Foreign Key Injection — Transport Assignment.
     */
    public function test_school_a_cannot_assign_school_b_student_to_transport_route(): void
    {
        $routeA = TransportRoute::create([
            'school_id' => $this->schoolA->id,
            'name'      => 'Route Alpha',
        ]);

        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'name' => 'Class 1B', 'numeric_name' => 1]);
        $studentB = Student::create([
            'school_id'  => $this->schoolB->id,
            'class_id'   => $classB->id,
            'first_name' => 'Student',
            'last_name'  => 'Beta',
            'gender'     => 'male',
            'category'   => 'general',
            'status'     => 'active',
        ]);

        $response = $this->actingAs($this->adminA)
            ->post("/school/transport/routes/{$routeA->id}/assign", [
                'student_id' => $studentB->id,
            ]);

        $response->assertSessionHasErrors(['student_id']);
    }

    /**
     * Requirement D: Cross-Tenant Foreign Key Injection — Hostel Allocation.
     */
    public function test_school_a_cannot_allocate_school_b_student_or_room_in_hostel(): void
    {
        $hostelA = Hostel::create([
            'school_id' => $this->schoolA->id,
            'name'      => 'Alpha Hostel',
            'type'      => 'boys',
            'status'    => 'active',
        ]);

        $roomA = HostelRoom::create([
            'school_id' => $this->schoolA->id,
            'hostel_id' => $hostelA->id,
            'room_no'   => '101',
            'type'      => 'single',
            'capacity'  => 1,
            'status'    => 'available',
        ]);

        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'name' => 'Class 1B', 'numeric_name' => 1]);
        $studentB = Student::create([
            'school_id'  => $this->schoolB->id,
            'class_id'   => $classB->id,
            'first_name' => 'Student',
            'last_name'  => 'Beta',
            'gender'     => 'male',
            'category'   => 'general',
            'status'     => 'active',
        ]);

        $response = $this->actingAs($this->adminA)->post('/school/hostel/allocations', [
            'hostel_id'    => $hostelA->id,
            'room_id'      => $roomA->id,
            'student_id'   => $studentB->id,
            'joining_date' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors(['student_id']);
    }

    /**
     * Requirement D: Cross-Tenant Foreign Key Injection — Library Book Issue.
     */
    public function test_school_a_cannot_issue_foreign_book_or_to_foreign_student(): void
    {
        $bookB = Book::create([
            'school_id'        => $this->schoolB->id,
            'title'            => 'Beta Physics',
            'author'           => 'Dr. Beta',
            'total_copies'     => 5,
            'available_copies' => 5,
            'is_active'        => true,
        ]);

        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'name' => 'Class 1A', 'numeric_name' => 1]);
        $studentA = Student::create([
            'school_id'  => $this->schoolA->id,
            'class_id'   => $classA->id,
            'first_name' => 'Student',
            'last_name'  => 'Alpha',
            'gender'     => 'male',
            'category'   => 'general',
            'status'     => 'active',
        ]);

        $response = $this->actingAs($this->adminA)->post('/school/library/issues', [
            'book_id'     => $bookB->id,
            'member_type' => 'student',
            'member_id'   => $studentA->id,
            'issued_date' => now()->toDateString(),
            'due_date'    => now()->addDays(7)->toDateString(),
        ]);

        $response->assertSessionHasErrors(['book_id']);
    }

    /**
     * Requirement D: Cross-Tenant Foreign Key Injection — Exam Marks.
     */
    public function test_school_a_cannot_submit_foreign_student_or_subject_marks(): void
    {
        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'name' => 'Class 1A', 'numeric_name' => 1]);
        $examA = Exam::create([
            'school_id' => $this->schoolA->id,
            'name'      => 'Midterm Alpha',
            'type'      => 'mid_term',
            'class_id'  => $classA->id,
            'status'    => 'draft',
        ]);

        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'name' => 'Class 1B', 'numeric_name' => 1]);
        $studentB = Student::create([
            'school_id'  => $this->schoolB->id,
            'class_id'   => $classB->id,
            'first_name' => 'Student',
            'last_name'  => 'Beta',
            'gender'     => 'male',
            'category'   => 'general',
            'status'     => 'active',
        ]);
        $subjectA = Subject::create([
            'school_id' => $this->schoolA->id,
            'class_id'  => $classA->id,
            'name'      => 'Math A',
            'type'      => 'theory',
        ]);

        $response = $this->actingAs($this->adminA)->post("/school/exams/{$examA->id}/marks", [
            'marks' => [
                [
                    'student_id'     => $studentB->id,
                    'subject_id'     => $subjectA->id,
                    'marks_obtained' => 85,
                ],
            ],
        ]);

        $response->assertSessionHasErrors(['marks.0.student_id']);
    }

    /**
     * Requirement D: Cross-Tenant Foreign Key Injection — Inventory Purchases & Issues.
     */
    public function test_school_a_cannot_purchase_or_issue_foreign_inventory_item(): void
    {
        $catB = InventoryCategory::create(['school_id' => $this->schoolB->id, 'name' => 'Stationery Beta']);
        $itemB = InventoryItem::create([
            'school_id'     => $this->schoolB->id,
            'category_id'   => $catB->id,
            'name'          => 'Markers Beta',
            'unit'          => 'box',
            'minimum_stock' => 5,
        ]);

        $response = $this->actingAs($this->adminA)->post('/school/inventory/purchases', [
            'item_id'       => $itemB->id,
            'purchase_date' => now()->toDateString(),
            'quantity'      => 10,
            'unit_price'    => 5.0,
        ]);

        $response->assertSessionHasErrors(['item_id']);
    }

    /**
     * Same-school workflows continue to work smoothly.
     */
    public function test_valid_same_school_workflows_succeed(): void
    {
        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'name' => 'Class 1A', 'numeric_name' => 1]);
        $sectionA = Section::create(['school_id' => $this->schoolA->id, 'class_id' => $classA->id, 'name' => 'A']);

        // Admit student
        $response = $this->actingAs($this->adminA)->post('/school/students', [
            'first_name' => 'Valid',
            'last_name'  => 'Student',
            'gender'     => 'male',
            'category'   => 'general',
            'status'     => 'active',
            'class_id'   => $classA->id,
            'section_id' => $sectionA->id,
            'guardian'   => [
                'name'     => 'Guardian Alpha',
                'relation' => 'Mother',
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('school.students.index'));

        $this->assertDatabaseHas('students', [
            'school_id'  => $this->schoolA->id,
            'first_name' => 'Valid',
        ]);
    }
}
