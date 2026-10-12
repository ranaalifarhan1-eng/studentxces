<?php

namespace Tests\Feature\SchoolAdmin;

use App\Models\Department;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\Payroll;
use App\Models\SalaryStructure;
use App\Models\School;
use App\Models\SchoolSubscription;
use App\Models\Staff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $otherSchool;
    protected User $adminUser;
    protected User $studentUser;
    protected Department $department;
    protected Department $otherDepartment;
    protected Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Roles & Permissions setup
        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $studentRole = Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        // 2. Schools & Subscriptions
        $this->school = School::create([
            'name'       => 'Test School',
            'domain'     => 'testschool',
            'is_active'  => true,
            'created_by' => 1,
        ]);

        $this->otherSchool = School::create([
            'name'       => 'Other School',
            'domain'     => 'otherschool',
            'is_active'  => true,
            'created_by' => 1,
        ]);

        $package = Package::create([
            'name'          => 'HR Enterprise Plan',
            'slug'          => 'plan-' . uniqid(),
            'price_monthly' => 50,
            'price_yearly'  => 500,
            'max_students'  => 500,
            'max_staff'     => 50,
            'storage_gb'    => 50,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'hr']);

        SchoolSubscription::create([
            'school_id'  => $this->school->id,
            'package_id' => $package->id,
            'status'     => 'active',
            'start_date' => Carbon::now()->subDays(10),
            'end_date'   => Carbon::now()->addDays(50),
        ]);

        SchoolSubscription::create([
            'school_id'  => $this->otherSchool->id,
            'package_id' => $package->id,
            'status'     => 'active',
            'start_date' => Carbon::now()->subDays(10),
            'end_date'   => Carbon::now()->addDays(50),
        ]);

        // 3. Users
        $this->adminUser = User::create([
            'name'      => 'Admin Tester',
            'email'     => 'admin@testschool.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->studentUser = User::create([
            'name'      => 'Student Tester',
            'email'     => 'student@testschool.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->studentUser->assignRole('student');

        // 4. Departments
        $this->department = Department::create([
            'school_id' => $this->school->id,
            'name'      => 'Science Dept',
        ]);

        $this->otherDepartment = Department::create([
            'school_id' => $this->otherSchool->id,
            'name'      => 'Other School Dept',
        ]);

        // 5. Staff with Salary Structure
        $this->staff = Staff::create([
            'school_id'      => $this->school->id,
            'department_id'  => $this->department->id,
            'first_name'     => 'John',
            'last_name'      => 'Doe',
            'emp_id'         => 'EMP-001',
            'gender'         => 'male',
            'joining_date'   => Carbon::now()->subYears(1),
            'status'         => 'active',
        ]);

        SalaryStructure::create([
            'school_id'    => $this->school->id,
            'staff_id'     => $this->staff->id,
            'basic_salary' => 50000,
            'allowances'   => [],
            'deductions'   => [],
            'is_active'    => true,
        ]);
    }

    public function test_authorized_school_admin_can_open_payroll_page(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get(route('school.hr.payroll.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/HR/Payroll')
            ->has('payrolls')
            ->has('departments')
            ->has('filters')
            ->has('stats')
        );
    }

    public function test_unauthorized_role_is_denied(): void
    {
        $response = $this->actingAs($this->studentUser)
            ->get(route('school.hr.payroll.index'));

        $response->assertStatus(403);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('school.hr.payroll.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_generate_requires_month_year(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('school.hr.payroll.generate'), [
                'working_days' => 30,
            ]);

        $response->assertSessionHasErrors(['month_year']);
    }

    public function test_generate_requires_working_days(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('school.hr.payroll.generate'), [
                'month_year' => '2026-10',
            ]);

        $response->assertSessionHasErrors(['working_days']);
    }

    public function test_working_days_less_than_one_is_rejected(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('school.hr.payroll.generate'), [
                'month_year'   => '2026-10',
                'working_days' => 0,
            ]);

        $response->assertSessionHasErrors(['working_days']);
    }

    public function test_working_days_greater_than_31_is_rejected(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('school.hr.payroll.generate'), [
                'month_year'   => '2026-10',
                'working_days' => 32,
            ]);

        $response->assertSessionHasErrors(['working_days']);
    }

    public function test_department_must_belong_to_active_school_and_cross_tenant_department_is_rejected(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('school.hr.payroll.generate'), [
                'month_year'    => '2026-10',
                'department_id' => $this->otherDepartment->id,
                'working_days'  => 30,
            ]);

        $response->assertSessionHasErrors(['department_id']);
    }

    public function test_valid_generation_path_succeeds_and_preserves_tenant_isolation(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->post(route('school.hr.payroll.generate'), [
                'month_year'    => '2026-10',
                'department_id' => $this->department->id,
                'working_days'  => 30,
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $this->assertDatabaseHas('payrolls', [
            'school_id'    => $this->school->id,
            'staff_id'     => $this->staff->id,
            'month_year'   => '2026-10',
            'working_days' => 30,
            'basic_salary' => 50000,
        ]);

        // Assert no records created for other school
        $this->assertDatabaseMissing('payrolls', [
            'school_id' => $this->otherSchool->id,
        ]);
    }

    public function test_duplicate_payroll_generation_is_idempotent_and_does_not_create_duplicate_rows(): void
    {
        // First generation
        $this->actingAs($this->adminUser)
            ->post(route('school.hr.payroll.generate'), [
                'month_year'    => '2026-10',
                'department_id' => $this->department->id,
                'working_days'  => 30,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertEquals(1, Payroll::where('school_id', $this->school->id)->where('month_year', '2026-10')->count());

        // Second generation with same parameters
        $this->actingAs($this->adminUser)
            ->post(route('school.hr.payroll.generate'), [
                'month_year'    => '2026-10',
                'department_id' => $this->department->id,
                'working_days'  => 30,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // Still exactly 1 record, not 2
        $this->assertEquals(1, Payroll::where('school_id', $this->school->id)->where('month_year', '2026-10')->count());
    }

    public function test_paid_payroll_is_never_overwritten_or_modified_during_generation(): void
    {
        // Create an already paid payroll record with custom amount
        $payroll = Payroll::create([
            'school_id'           => $this->school->id,
            'staff_id'            => $this->staff->id,
            'month_year'          => '2026-10',
            'basic_salary'        => 50000,
            'total_allowances'    => 5000,
            'total_deductions'    => 1000,
            'net_salary'          => 54000,
            'working_days'        => 28,
            'present_days'        => 28,
            'leave_days'          => 0,
            'status'              => 'paid',
            'paid_on'             => '2026-10-05',
        ]);

        // Attempt to regenerate payroll for the same month with 30 working days
        $response = $this->actingAs($this->adminUser)
            ->post(route('school.hr.payroll.generate'), [
                'month_year'    => '2026-10',
                'department_id' => $this->department->id,
                'working_days'  => 30,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('warning');

        // Verify the record remained completely intact and untouched
        $payroll->refresh();
        $this->assertEquals('paid', $payroll->status);
        $this->assertEquals('2026-10-05', $payroll->paid_on->toDateString());
        $this->assertEquals(28, $payroll->working_days);
        $this->assertEquals('54000.00', (string) $payroll->net_salary);
    }

    public function test_payroll_generation_with_empty_staff_returns_warning(): void
    {
        $emptyDept = Department::create([
            'school_id' => $this->school->id,
            'name'      => 'Empty Dept',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->post(route('school.hr.payroll.generate'), [
                'month_year'    => '2026-10',
                'department_id' => $emptyDept->id,
                'working_days'  => 30,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('warning', 'No active staff found for payroll generation.');
    }

    public function test_payroll_database_unique_index_exists(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM payrolls'));
        $uniqueIndexRows = $indexes->where('Key_name', 'payroll_staff_month_unique');

        $this->assertNotEmpty($uniqueIndexRows, 'Unique index payroll_staff_month_unique must exist on payrolls table.');
        $columns = $uniqueIndexRows->sortBy('Seq_in_index')->pluck('Column_name')->all();
        $this->assertEquals(['school_id', 'staff_id', 'month_year'], $columns);
        $this->assertTrue((int) $uniqueIndexRows->first()->Non_unique === 0, 'Index must be unique.');
    }

    public function test_database_enforces_unique_constraint_preventing_duplicate_insertions(): void
    {
        Payroll::create([
            'school_id'        => $this->school->id,
            'staff_id'         => $this->staff->id,
            'month_year'       => '2026-10',
            'basic_salary'     => 50000,
            'total_allowances' => 0,
            'total_deductions' => 0,
            'net_salary'       => 50000,
            'status'           => 'generated',
        ]);

        $this->expectException(QueryException::class);

        // Attempting to insert a duplicate row with the same school_id, staff_id, month_year must fail
        DB::table('payrolls')->insert([
            'school_id'        => $this->school->id,
            'staff_id'         => $this->staff->id,
            'month_year'       => '2026-10',
            'basic_salary'     => 50000,
            'total_allowances' => 0,
            'total_deductions' => 0,
            'net_salary'       => 50000,
            'working_days'     => 30,
            'present_days'     => 30,
            'leave_days'       => 0,
            'status'           => 'generated',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function test_same_month_year_is_permitted_for_different_schools(): void
    {
        // School A payroll record
        Payroll::create([
            'school_id'        => $this->school->id,
            'staff_id'         => $this->staff->id,
            'month_year'       => '2026-10',
            'basic_salary'     => 50000,
            'total_allowances' => 0,
            'total_deductions' => 0,
            'net_salary'       => 50000,
            'status'           => 'generated',
        ]);

        // Staff in School B
        $otherStaff = Staff::create([
            'school_id'     => $this->otherSchool->id,
            'department_id' => $this->otherDepartment->id,
            'first_name'    => 'Jane',
            'last_name'     => 'Smith',
            'emp_id'        => 'EMP-002',
            'gender'        => 'female',
            'joining_date'  => Carbon::now()->subYears(1),
            'status'        => 'active',
        ]);

        // School B payroll record for the same month_year
        $otherPayroll = Payroll::create([
            'school_id'        => $this->otherSchool->id,
            'staff_id'         => $otherStaff->id,
            'month_year'       => '2026-10',
            'basic_salary'     => 60000,
            'total_allowances' => 0,
            'total_deductions' => 0,
            'net_salary'       => 60000,
            'status'           => 'generated',
        ]);

        $this->assertDatabaseHas('payrolls', [
            'id'        => $otherPayroll->id,
            'school_id' => $this->otherSchool->id,
            'staff_id'  => $otherStaff->id,
        ]);
    }
}
