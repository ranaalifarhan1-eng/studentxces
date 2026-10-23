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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PayrollPayActionTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $otherSchool;
    protected User $adminUser;
    protected User $superAdminUser;
    protected User $studentUser;
    protected Department $department;
    protected Department $otherDepartment;
    protected Staff $staff;
    protected Staff $otherStaff;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Roles & Permissions setup
        $markPaidPerm = Permission::firstOrCreate(['name' => 'payroll.mark_paid', 'guard_name' => 'web']);
        $schoolAdminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $superAdminRole = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'principal', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'librarian', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $schoolAdminRole->givePermissionTo($markPaidPerm);
        $superAdminRole->givePermissionTo($markPaidPerm);

        // 2. Schools setup
        $this->school = School::create([
            'name'       => 'Alpha Grammar School',
            'domain'     => 'alphagrammar',
            'is_active'  => true,
            'created_by' => 1,
        ]);

        $this->otherSchool = School::create([
            'name'       => 'Beta International Academy',
            'domain'     => 'betaintl',
            'is_active'  => true,
            'created_by' => 1,
        ]);

        // 3. Package & Entitlements (HR module active)
        $package = Package::create([
            'name'          => 'HR Standard Plan',
            'slug'          => 'hr-plan-' . uniqid(),
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

        // 4. Standard test users
        $this->adminUser = User::create([
            'name'      => 'HR School Admin',
            'email'     => 'admin@alphagrammar.test',
            'password'  => bcrypt('password123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->superAdminUser = User::create([
            'name'      => 'Platform Super Admin',
            'email'     => 'superadmin@alphagrammar.test',
            'password'  => bcrypt('password123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->superAdminUser->assignRole('super-admin');

        $this->studentUser = User::create([
            'name'      => 'Student Bob',
            'email'     => 'bob@alphagrammar.test',
            'password'  => bcrypt('password123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->studentUser->assignRole('student');

        // 5. Departments and Staff
        $this->department = Department::create([
            'school_id' => $this->school->id,
            'name'      => 'Mathematics',
        ]);

        $this->otherDepartment = Department::create([
            'school_id' => $this->otherSchool->id,
            'name'      => 'Languages',
        ]);

        $this->staff = Staff::create([
            'school_id'     => $this->school->id,
            'department_id' => $this->department->id,
            'first_name'    => 'Tariq',
            'last_name'     => 'Mahmood',
            'emp_id'        => 'EMP-101',
            'gender'        => 'male',
            'joining_date'  => Carbon::now()->subYears(2),
            'status'        => 'active',
        ]);

        SalaryStructure::create([
            'school_id'    => $this->school->id,
            'staff_id'     => $this->staff->id,
            'basic_salary' => 65000,
            'allowances'   => [['label' => 'Medical', 'amount' => 5000]],
            'deductions'   => [['label' => 'Tax', 'amount' => 2000]],
            'is_active'    => true,
        ]);

        $this->otherStaff = Staff::create([
            'school_id'     => $this->otherSchool->id,
            'department_id' => $this->otherDepartment->id,
            'first_name'    => 'Farah',
            'last_name'     => 'Naz',
            'emp_id'        => 'EMP-202',
            'gender'        => 'female',
            'joining_date'  => Carbon::now()->subYears(1),
            'status'        => 'active',
        ]);
    }

    private function createPayroll(string $status = 'generated', ?string $paidOn = null): Payroll
    {
        return Payroll::create([
            'school_id'           => $this->school->id,
            'staff_id'            => $this->staff->id,
            'month_year'          => '2026-10',
            'basic_salary'        => 65000,
            'total_allowances'    => 5000,
            'total_deductions'    => 2000,
            'net_salary'          => 68000,
            'working_days'        => 30,
            'present_days'        => 30,
            'leave_days'          => 0,
            'allowances_snapshot' => [['label' => 'Medical', 'amount' => 5000]],
            'deductions_snapshot' => [['label' => 'Tax', 'amount' => 2000]],
            'status'              => $status,
            'paid_on'             => $paidOn,
        ]);
    }

    public function test_school_admin_is_allowed_to_mark_payroll_as_paid(): void
    {
        $payroll = $this->createPayroll();

        $response = $this->actingAs($this->adminUser)
            ->put(route('school.hr.payroll.paid', $payroll));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Payroll marked as paid.');

        $payroll->refresh();
        $this->assertEquals('paid', $payroll->status);
        $this->assertNotNull($payroll->paid_on);
        $this->assertEquals(now()->toDateString(), $payroll->paid_on->toDateString());
    }

    public function test_school_admin_with_permission_explicitly_removed_receives_403(): void
    {
        // Explicitly revoke payroll.mark_paid from school-admin role to prove absence of hardcoded role bypass
        $role = Role::findByName('school-admin', 'web');
        $role->revokePermissionTo('payroll.mark_paid');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $payroll = $this->createPayroll();

        try {
            $response = $this->actingAs($this->adminUser)
                ->put(route('school.hr.payroll.paid', $payroll));

            $response->assertStatus(403);

            $payroll->refresh();
            $this->assertEquals('generated', $payroll->status);
            $this->assertNull($payroll->paid_on);
        } finally {
            $role->givePermissionTo('payroll.mark_paid');
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
        }
    }

    public function test_super_admin_is_allowed_to_mark_payroll_as_paid(): void
    {
        $payroll = $this->createPayroll();

        $response = $this->actingAs($this->superAdminUser)
            ->withSession(['active_school_id' => $this->school->id])
            ->put(route('school.hr.payroll.paid', $payroll));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Payroll marked as paid.');

        $payroll->refresh();
        $this->assertEquals('paid', $payroll->status);
        $this->assertNotNull($payroll->paid_on);
        $this->assertEquals(now()->toDateString(), $payroll->paid_on->toDateString());
    }

    public function test_unauthorized_teacher_receives_403(): void
    {
        $teacher = User::create([
            'name'      => 'Teacher Ali',
            'email'     => 'ali@alphagrammar.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $teacher->assignRole('teacher');

        $payroll = $this->createPayroll();

        $response = $this->actingAs($teacher)
            ->put(route('school.hr.payroll.paid', $payroll));

        $response->assertStatus(403);

        $payroll->refresh();
        $this->assertEquals('generated', $payroll->status);
        $this->assertNull($payroll->paid_on);
    }

    public function test_unauthorized_librarian_receives_403(): void
    {
        $librarian = User::create([
            'name'      => 'Librarian Sana',
            'email'     => 'sana@alphagrammar.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $librarian->assignRole('librarian');

        $payroll = $this->createPayroll();

        $response = $this->actingAs($librarian)
            ->put(route('school.hr.payroll.paid', $payroll));

        $response->assertStatus(403);

        $payroll->refresh();
        $this->assertEquals('generated', $payroll->status);
        $this->assertNull($payroll->paid_on);
    }

    public function test_unauthorized_principal_receives_403_without_explicit_permission(): void
    {
        $principal = User::create([
            'name'      => 'Principal Kamran',
            'email'     => 'kamran@alphagrammar.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $principal->assignRole('principal');

        $payroll = $this->createPayroll();

        $response = $this->actingAs($principal)
            ->put(route('school.hr.payroll.paid', $payroll));

        $response->assertStatus(403);

        $payroll->refresh();
        $this->assertEquals('generated', $payroll->status);
        $this->assertNull($payroll->paid_on);
    }

    public function test_principal_with_explicit_permission_is_allowed(): void
    {
        $principal = User::create([
            'name'      => 'Principal Kamran Permitted',
            'email'     => 'kamran.perm@alphagrammar.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $principal->assignRole('principal');
        $principal->givePermissionTo('payroll.mark_paid');

        $payroll = $this->createPayroll();

        $response = $this->actingAs($principal)
            ->put(route('school.hr.payroll.paid', $payroll));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Payroll marked as paid.');

        $payroll->refresh();
        $this->assertEquals('paid', $payroll->status);
        $this->assertNotNull($payroll->paid_on);
    }

    public function test_unauthorized_accountant_receives_403_without_explicit_permission(): void
    {
        $accountant = User::create([
            'name'      => 'Accountant Rashid',
            'email'     => 'rashid@alphagrammar.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $accountant->assignRole('accountant');

        $payroll = $this->createPayroll();

        $response = $this->actingAs($accountant)
            ->put(route('school.hr.payroll.paid', $payroll));

        $response->assertStatus(403);

        $payroll->refresh();
        $this->assertEquals('generated', $payroll->status);
        $this->assertNull($payroll->paid_on);
    }

    public function test_accountant_with_explicit_permission_is_allowed(): void
    {
        $accountant = User::create([
            'name'      => 'Accountant Rashid Permitted',
            'email'     => 'rashid.perm@alphagrammar.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $accountant->assignRole('accountant');
        $accountant->givePermissionTo('payroll.mark_paid');

        $payroll = $this->createPayroll();

        $response = $this->actingAs($accountant)
            ->put(route('school.hr.payroll.paid', $payroll));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Payroll marked as paid.');

        $payroll->refresh();
        $this->assertEquals('paid', $payroll->status);
        $this->assertNotNull($payroll->paid_on);
    }

    public function test_custom_role_with_permission_is_allowed(): void
    {
        $customRole = Role::firstOrCreate(['name' => 'payroll-officer', 'guard_name' => 'web']);
        $customRole->givePermissionTo('payroll.mark_paid');

        $user = User::create([
            'name'      => 'Payroll Officer',
            'email'     => 'payrollofficer@alphagrammar.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        // Staff route access requires membership in the school route group
        $user->assignRole(['teacher', 'payroll-officer']);

        $payroll = $this->createPayroll();

        $response = $this->actingAs($user)
            ->put(route('school.hr.payroll.paid', $payroll));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Payroll marked as paid.');

        $payroll->refresh();
        $this->assertEquals('paid', $payroll->status);
    }

    public function test_custom_role_without_permission_receives_403(): void
    {
        Role::firstOrCreate(['name' => 'internal-auditor', 'guard_name' => 'web']);

        $user = User::create([
            'name'      => 'Internal Auditor',
            'email'     => 'auditor@alphagrammar.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $user->assignRole(['teacher', 'internal-auditor']);

        $payroll = $this->createPayroll();

        $response = $this->actingAs($user)
            ->put(route('school.hr.payroll.paid', $payroll));

        $response->assertStatus(403);

        $payroll->refresh();
        $this->assertEquals('generated', $payroll->status);
        $this->assertNull($payroll->paid_on);
    }

    public function test_cross_school_payroll_payment_is_rejected(): void
    {
        $otherPayroll = Payroll::create([
            'school_id'        => $this->otherSchool->id,
            'staff_id'         => $this->otherStaff->id,
            'month_year'       => '2026-10',
            'basic_salary'     => 70000,
            'total_allowances' => 0,
            'total_deductions' => 0,
            'net_salary'       => 70000,
            'working_days'     => 30,
            'present_days'     => 30,
            'leave_days'       => 0,
            'status'           => 'generated',
            'paid_on'          => null,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->put("/school/hr/payroll/{$otherPayroll->id}/paid");

        $this->assertContains($response->getStatusCode(), [403, 404]);

        $otherPayroll->refresh();
        $this->assertEquals('generated', $otherPayroll->status);
        $this->assertNull($otherPayroll->paid_on);
    }

    public function test_already_paid_payroll_is_protected_against_duplicate_payment_and_date_overwrite(): void
    {
        $historicalPaidDate = '2026-09-25';
        $payroll = $this->createPayroll('paid', $historicalPaidDate);

        $response = $this->actingAs($this->adminUser)
            ->put(route('school.hr.payroll.paid', $payroll));

        $response->assertRedirect();
        $response->assertSessionHas('warning', 'Payroll is already marked as paid.');

        $payroll->refresh();
        $this->assertEquals('paid', $payroll->status);
        $this->assertEquals($historicalPaidDate, $payroll->paid_on->toDateString());
    }

    public function test_old_pay_endpoint_returns_404_confirming_root_cause_isolation(): void
    {
        $payroll = $this->createPayroll();

        $response = $this->actingAs($this->adminUser)
            ->put("/school/hr/payroll/{$payroll->id}/pay");

        $response->assertStatus(404);

        $payroll->refresh();
        $this->assertEquals('generated', $payroll->status);
        $this->assertNull($payroll->paid_on);
    }

    public function test_guest_cannot_mark_payroll_as_paid(): void
    {
        $payroll = $this->createPayroll();

        $response = $this->put(route('school.hr.payroll.paid', $payroll));

        $response->assertRedirect(route('login'));

        $payroll->refresh();
        $this->assertEquals('generated', $payroll->status);
    }

    public function test_payroll_index_exposes_can_mark_paid_inertia_prop_accurately(): void
    {
        // 1. School Admin receives can_mark_paid => true
        $resAdmin = $this->actingAs($this->adminUser)
            ->get(route('school.hr.payroll.index'));
        $resAdmin->assertStatus(200);
        $resAdmin->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/HR/Payroll')
            ->where('can_mark_paid', true)
        );

        // 2. Unauthorized accountant receives can_mark_paid => false
        $accountant = User::create([
            'name'      => 'Accountant QA',
            'email'     => 'acctqa@alphagrammar.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $accountant->assignRole('accountant');

        $resAcct = $this->actingAs($accountant)
            ->get(route('school.hr.payroll.index'));
        $resAcct->assertStatus(200);
        $resAcct->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/HR/Payroll')
            ->where('can_mark_paid', false)
        );

        // 3. Accountant granted payroll.mark_paid receives can_mark_paid => true
        $accountant->givePermissionTo('payroll.mark_paid');
        $resAcctPerm = $this->actingAs($accountant)
            ->get(route('school.hr.payroll.index'));
        $resAcctPerm->assertStatus(200);
        $resAcctPerm->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/HR/Payroll')
            ->where('can_mark_paid', true)
        );

        // 4. School Admin with permission revoked receives can_mark_paid => false
        $role = Role::findByName('school-admin', 'web');
        $role->revokePermissionTo('payroll.mark_paid');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        try {
            $resAdminRevoked = $this->actingAs($this->adminUser)
                ->get(route('school.hr.payroll.index'));
            $resAdminRevoked->assertStatus(200);
            $resAdminRevoked->assertInertia(fn (Assert $page) => $page
                ->component('SchoolAdmin/HR/Payroll')
                ->where('can_mark_paid', false)
            );
        } finally {
            $role->givePermissionTo('payroll.mark_paid');
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
        }
    }

    public function test_full_payroll_workflow_from_generation_to_payment_and_subsequent_regeneration(): void
    {
        // 1. Generate payroll for 2026-10
        $genResponse = $this->actingAs($this->adminUser)
            ->post(route('school.hr.payroll.generate'), [
                'month_year'    => '2026-10',
                'department_id' => $this->department->id,
                'working_days'  => 30,
            ]);

        $genResponse->assertRedirect();
        $genResponse->assertSessionHas('success');

        $payroll = Payroll::where('school_id', $this->school->id)
            ->where('staff_id', $this->staff->id)
            ->where('month_year', '2026-10')
            ->firstOrFail();

        $this->assertEquals('generated', $payroll->status);
        $this->assertNull($payroll->paid_on);

        // 2. Mark payroll as paid
        $payResponse = $this->actingAs($this->adminUser)
            ->put(route('school.hr.payroll.paid', $payroll));

        $payResponse->assertRedirect();
        $payResponse->assertSessionHas('success');

        $payroll->refresh();
        $this->assertEquals('paid', $payroll->status);
        $this->assertNotNull($payroll->paid_on);

        // 3. Attempt regeneration for same month: must preserve paid record
        $regenResponse = $this->actingAs($this->adminUser)
            ->post(route('school.hr.payroll.generate'), [
                'month_year'    => '2026-10',
                'department_id' => $this->department->id,
                'working_days'  => 30,
            ]);

        $regenResponse->assertRedirect();
        $regenResponse->assertSessionHas('warning');

        $payroll->refresh();
        $this->assertEquals('paid', $payroll->status);
        $this->assertEquals(now()->toDateString(), $payroll->paid_on->toDateString());
    }
}
