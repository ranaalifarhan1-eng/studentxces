<?php

namespace Tests\Feature\SchoolAdmin;

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Designation;
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
use App\Models\Staff;
use App\Models\Student;
use App\Models\TransportRoute;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MultiWordSearchNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;
    protected School $schoolB;
    protected User $adminA;
    protected User $adminB;
    protected SchoolClass $classA;
    protected Section $sectionA;
    protected Student $studentA;
    protected Student $studentB;
    protected Student $studentC;
    protected Student $studentD;
    protected Staff $staffA;
    protected Staff $staffB;
    protected TransportRoute $routeA;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure permissions exist
        $permissions = [
            'students.view',
            'staff.view',
            'fees.view',
            'fees.collect',
            'transport.view',
            'transport.manage',
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions($permissions);

        // School A
        $this->schoolA = School::create([
            'name'     => 'School A MultiWord',
            'slug'     => 'school-a-mw-' . uniqid(),
            'email'    => 'admin-a-' . uniqid() . '@school.test',
            'status'   => 'active',
            'timezone' => 'Asia/Karachi',
        ]);

        $this->adminA = User::factory()->create([
            'school_id' => $this->schoolA->id,
            'email'     => 'admin-a-' . uniqid() . '@school.test',
            'status'    => 'active',
        ]);
        $this->adminA->assignRole($adminRole);

        // School B (for tenant isolation)
        $this->schoolB = School::create([
            'name'     => 'School B MultiWord',
            'slug'     => 'school-b-mw-' . uniqid(),
            'email'    => 'admin-b-' . uniqid() . '@school.test',
            'status'   => 'active',
            'timezone' => 'Asia/Karachi',
        ]);

        $this->adminB = User::factory()->create([
            'school_id' => $this->schoolB->id,
            'email'     => 'admin-b-' . uniqid() . '@school.test',
            'status'    => 'active',
        ]);
        $this->adminB->assignRole($adminRole);

        $package = Package::create([
            'name'          => 'Pro Plan MultiWord',
            'slug'          => 'pro-mw-' . uniqid(),
            'price_monthly' => 50,
            'price_yearly'  => 500,
            'max_students'  => 500,
            'max_staff'     => 50,
            'storage_gb'    => 20,
            'is_active'     => true,
            'is_internal'   => false,
        ]);

        foreach (['students', 'staff', 'fees', 'transport'] as $mod) {
            PackageModule::create(['package_id' => $package->id, 'module_slug' => $mod]);
        }

        SchoolSubscription::create([
            'school_id'  => $this->schoolA->id,
            'package_id' => $package->id,
            'status'     => 'active',
            'start_date' => Carbon::now()->subDays(10),
            'end_date'   => Carbon::now()->addDays(30),
        ]);

        SchoolSubscription::create([
            'school_id'  => $this->schoolB->id,
            'package_id' => $package->id,
            'status'     => 'active',
            'start_date' => Carbon::now()->subDays(10),
            'end_date'   => Carbon::now()->addDays(30),
        ]);

        // Academic structure for School A
        $this->classA = SchoolClass::create([
            'school_id'    => $this->schoolA->id,
            'name'         => 'Grade 9',
            'numeric_name' => 9,
        ]);

        $this->sectionA = Section::create([
            'school_id' => $this->schoolA->id,
            'class_id'  => $this->classA->id,
            'name'      => 'Section Green',
        ]);

        // Synthetic Fixtures as required by spec:
        // Student A: Ali Khan, TEST-2026-001
        $this->studentA = Student::create([
            'school_id'      => $this->schoolA->id,
            'class_id'       => $this->classA->id,
            'section_id'     => $this->sectionA->id,
            'admission_no'   => 'TEST-2026-001',
            'first_name'     => 'Ali',
            'last_name'      => 'Khan',
            'gender'         => 'male',
            'status'         => 'active',
            'admission_date' => '2026-08-01',
            'date_of_birth'  => '2012-05-15',
        ]);

        // Student B: Muhammad Ali Khan, TEST-2026-002
        $this->studentB = Student::create([
            'school_id'      => $this->schoolA->id,
            'class_id'       => $this->classA->id,
            'section_id'     => $this->sectionA->id,
            'admission_no'   => 'TEST-2026-002',
            'first_name'     => 'Muhammad Ali',
            'last_name'      => 'Khan',
            'gender'         => 'male',
            'status'         => 'active',
            'admission_date' => '2026-08-01',
            'date_of_birth'  => '2012-06-20',
        ]);

        // Student C: Hassan, last_name = NULL, TEST-2026-003
        $this->studentC = Student::create([
            'school_id'      => $this->schoolA->id,
            'class_id'       => $this->classA->id,
            'section_id'     => $this->sectionA->id,
            'admission_no'   => 'TEST-2026-003',
            'first_name'     => 'Hassan',
            'last_name'      => null,
            'gender'         => 'male',
            'status'         => 'active',
            'admission_date' => '2026-08-01',
            'date_of_birth'  => '2012-07-25',
        ]);

        // Student D: Another school (School B), same name as Student A
        $classB = SchoolClass::create([
            'school_id'    => $this->schoolB->id,
            'name'         => 'Grade 9 B',
            'numeric_name' => 9,
        ]);

        $this->studentD = Student::create([
            'school_id'      => $this->schoolB->id,
            'class_id'       => $classB->id,
            'admission_no'   => 'SCHB-2026-001',
            'first_name'     => 'Ali',
            'last_name'      => 'Khan',
            'gender'         => 'male',
            'status'         => 'active',
            'admission_date' => '2026-08-01',
            'date_of_birth'  => '2012-05-15',
        ]);

        // Staff fixtures
        $dept = Department::create(['school_id' => $this->schoolA->id, 'name' => 'Academics']);
        $desig = Designation::create(['school_id' => $this->schoolA->id, 'department_id' => $dept->id, 'name' => 'Senior Teacher']);

        $this->staffA = Staff::create([
            'school_id'      => $this->schoolA->id,
            'department_id'  => $dept->id,
            'designation_id' => $desig->id,
            'emp_id'         => 'EMP-1001',
            'first_name'     => 'Tariq',
            'last_name'      => 'Mahmood',
            'email'          => 'tariq.mahmood@school.test',
            'gender'         => 'male',
            'status'         => 'active',
            'salary_type'    => 'fixed',
            'salary'         => 50000,
            'joining_date'   => '2025-01-01',
        ]);

        $this->staffB = Staff::create([
            'school_id'      => $this->schoolA->id,
            'department_id'  => $dept->id,
            'designation_id' => $desig->id,
            'emp_id'         => 'EMP-1002',
            'first_name'     => 'Usman',
            'last_name'      => null,
            'email'          => 'usman@school.test',
            'gender'         => 'male',
            'status'         => 'active',
            'salary_type'    => 'fixed',
            'salary'         => 45000,
            'joining_date'   => '2025-01-01',
        ]);

        // Transport fixtures
        $vehicle = Vehicle::create([
            'school_id'       => $this->schoolA->id,
            'registration_no' => 'LEA-2026',
            'name'            => 'Bus 1',
            'type'            => 'bus',
            'capacity'        => 30,
        ]);

        $this->routeA = TransportRoute::create([
            'school_id'   => $this->schoolA->id,
            'vehicle_id'  => $vehicle->id,
            'name'        => 'Route Model Town',
            'start_point' => 'Campus',
            'end_point'   => 'Model Town',
            'monthly_fee' => 3000,
            'is_active'   => true,
        ]);
    }

    // ── 1. Student List Search Matrix ─────────────────────────────

    public function test_student_search_single_word_first_name_finds_all_matching(): void
    {
        $response = $this->actingAs($this->adminA)->get('/school/students?search=Ali');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Index')
            ->has('students.data', 2)
        );

        $studentIds = collect($response->original->getData()['page']['props']['students']['data'])->pluck('id');
        $this->assertTrue($studentIds->contains($this->studentA->id));
        $this->assertTrue($studentIds->contains($this->studentB->id));
    }

    public function test_student_search_single_word_last_name(): void
    {
        $response = $this->actingAs($this->adminA)->get('/school/students?search=Khan');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Index')
            ->has('students.data', 2)
        );
    }

    public function test_student_search_full_name(): void
    {
        $response = $this->actingAs($this->adminA)->get('/school/students?search=Ali Khan');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Index')
            ->has('students.data', 2)
        );
    }

    public function test_student_search_leading_trailing_whitespace(): void
    {
        $response = $this->actingAs($this->adminA)->get('/school/students?search=%20%20Ali%20Khan%20%20');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Index')
            ->has('students.data', 2)
        );
    }

    public function test_student_search_multiple_internal_spaces(): void
    {
        $response = $this->actingAs($this->adminA)->get('/school/students?search=Ali%20%20Khan');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Index')
            ->has('students.data', 2)
        );
    }

    public function test_student_search_multi_word_specific_person(): void
    {
        $response = $this->actingAs($this->adminA)->get('/school/students?search=Muhammad Ali Khan');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Index')
            ->has('students.data', 1)
            ->where('students.data.0.id', $this->studentB->id)
        );
    }

    public function test_student_search_null_last_name(): void
    {
        $response = $this->actingAs($this->adminA)->get('/school/students?search=Hassan');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Index')
            ->has('students.data', 1)
            ->where('students.data.0.id', $this->studentC->id)
        );
    }

    public function test_student_search_exact_admission_number(): void
    {
        $response = $this->actingAs($this->adminA)->get('/school/students?search=TEST-2026-001');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Index')
            ->has('students.data', 1)
            ->where('students.data.0.id', $this->studentA->id)
        );
    }

    public function test_student_search_partial_admission_number(): void
    {
        $response = $this->actingAs($this->adminA)->get('/school/students?search=2026-001');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Index')
            ->has('students.data', 1)
            ->where('students.data.0.id', $this->studentA->id)
        );
    }

    public function test_student_search_mixed_case(): void
    {
        $response = $this->actingAs($this->adminA)->get('/school/students?search=aLi kHaN');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Index')
            ->has('students.data', 2)
        );
    }

    public function test_student_search_tenant_isolation_never_returns_other_school(): void
    {
        // Searching for "Ali Khan" in School A must NEVER return Student D from School B
        $response = $this->actingAs($this->adminA)->get('/school/students?search=Ali Khan');
        $response->assertOk();

        $studentIds = collect($response->original->getData()['page']['props']['students']['data'])->pluck('id');
        $this->assertTrue($studentIds->contains($this->studentA->id));
        $this->assertTrue($studentIds->contains($this->studentB->id));
        $this->assertFalse($studentIds->contains($this->studentD->id), 'Foreign school student must not be returned');

        // Searching in School B returns Student D and never students from School A
        $responseB = $this->actingAs($this->adminB)->get('/school/students?search=Ali Khan');
        $responseB->assertOk();

        $studentIdsB = collect($responseB->original->getData()['page']['props']['students']['data'])->pluck('id');
        $this->assertTrue($studentIdsB->contains($this->studentD->id));
        $this->assertFalse($studentIdsB->contains($this->studentA->id));
        $this->assertFalse($studentIdsB->contains($this->studentB->id));
    }

    public function test_student_search_whitespace_only_behaves_as_no_filter(): void
    {
        $response = $this->actingAs($this->adminA)->get('/school/students?search=%20%20%20');
        $response->assertOk();

        // Must return all 3 students in School A
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Index')
            ->has('students.data', 3)
        );
    }

    // ── 2. Fee Payments & Challans Search ─────────────────────────

    public function test_fee_payments_list_full_name_search(): void
    {
        // Create fee challan and payment for Student A
        $ay = AcademicYear::create([
            'school_id'  => $this->schoolA->id,
            'name'       => '2026-2027',
            'start_date' => '2026-04-01',
            'end_date'   => '2027-03-31',
            'is_active'  => true,
        ]);

        $challanA = FeeChallan::create([
            'school_id'          => $this->schoolA->id,
            'class_id'           => $this->classA->id,
            'class_name'         => 'Grade 9',
            'challan_no'         => 'CH-LCS-001',
            'student_id'         => $this->studentA->id,
            'academic_year_id'   => $ay->id,
            'academic_year_name' => '2026-2027',
            'student_name'       => 'Ali Khan',
            'admission_no'       => 'TEST-2026-001',
            'billing_period_key' => 'AY1-2026-10',
            'issue_date'         => '2026-10-01',
            'due_date'           => '2026-10-15',
            'gross_amount'       => 5000,
            'total_payable'      => 5000,
            'paid_amount'        => 5000,
            'status'             => 'paid',
        ]);

        $paymentA = FeePayment::create([
            'school_id'      => $this->schoolA->id,
            'student_id'     => $this->studentA->id,
            'fee_challan_id' => $challanA->id,
            'receipt_no'     => 'REC-001',
            'amount_due'     => 5000,
            'amount_paid'    => 5000,
            'payment_date'   => '2026-10-10',
            'month_year'     => '2026-10',
            'method'         => 'cash',
            'status'         => 'paid',
        ]);

        // Search payments tab by full student name
        $response = $this->actingAs($this->adminA)->get('/school/fees/payments?tab=payments&search=Ali Khan');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Payments')
            ->has('payments.data', 1)
            ->where('payments.data.0.id', $paymentA->id)
        );

        // Search challans tab by full student name with extra space
        $responseChallan = $this->actingAs($this->adminA)->get('/school/fees/payments?tab=challans&search=Ali%20%20Khan');
        $responseChallan->assertOk();

        $responseChallan->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Payments')
            ->has('challans.data', 1)
            ->where('challans.data.0.id', $challanA->id)
        );
    }

    public function test_fee_collection_candidate_lookup_supports_full_name_and_null_last_name(): void
    {
        // 1. Full name search matching Student A and B
        $response = $this->actingAs($this->adminA)->get('/school/fees/payments/collect?query=Ali Khan');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Collect')
            ->has('studentCandidates', 2)
        );

        // 2. Specific multi-word search selecting Student B directly
        $responseSingle = $this->actingAs($this->adminA)->get('/school/fees/payments/collect?query=Muhammad Ali Khan');
        $responseSingle->assertOk();

        $responseSingle->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Collect')
            ->where('student.id', $this->studentB->id)
        );

        // 3. Search for student with NULL last_name
        $responseNullLast = $this->actingAs($this->adminA)->get('/school/fees/payments/collect?query=Hassan');
        $responseNullLast->assertOk();

        $responseNullLast->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Collect')
            ->where('student.id', $this->studentC->id)
        );
    }

    // ── 3. Staff Directory Search ─────────────────────────────────

    public function test_staff_search_full_name_and_whitespace(): void
    {
        // Search full name
        $response = $this->actingAs($this->adminA)->get('/school/staff?search=Tariq Mahmood');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Staff/Index')
            ->has('staff.data', 1)
            ->where('staff.data.0.id', $this->staffA->id)
        );

        // Search with double space
        $responseDouble = $this->actingAs($this->adminA)->get('/school/staff?search=Tariq%20%20Mahmood');
        $responseDouble->assertOk();

        $responseDouble->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Staff/Index')
            ->has('staff.data', 1)
            ->where('staff.data.0.id', $this->staffA->id)
        );

        // Search staff with NULL last_name
        $responseNullLast = $this->actingAs($this->adminA)->get('/school/staff?search=Usman');
        $responseNullLast->assertOk();

        $responseNullLast->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Staff/Index')
            ->has('staff.data', 1)
            ->where('staff.data.0.id', $this->staffB->id)
        );
    }

    // ── 4. Transport Assignment Search ────────────────────────────

    public function test_transport_assignment_student_search(): void
    {
        $response = $this->actingAs($this->adminA)->get("/school/transport/routes/{$this->routeA->id}/assignments?search=Ali Khan");
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Transport/Assignments')
            ->has('available', 2)
        );

        // Multi-word specific
        $responseSpecific = $this->actingAs($this->adminA)->get("/school/transport/routes/{$this->routeA->id}/assignments?search=Muhammad Ali Khan");
        $responseSpecific->assertOk();

        $responseSpecific->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Transport/Assignments')
            ->has('available', 1)
            ->where('available.0.id', $this->studentB->id)
        );
    }
}
