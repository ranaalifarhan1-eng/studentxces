<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeeBulkAssignAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;
    protected School $schoolB;
    protected User $superAdmin;
    protected User $schoolAdminWithPerm;
    protected User $accountantWithoutPerm;
    protected User $teacher;
    protected SchoolClass $classA;
    protected SchoolClass $classB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Spatie Roles and Permissions
        $pBulkBill = Permission::firstOrCreate(['name' => 'fees.bulk_bill', 'guard_name' => 'web']);
        $pFeeView = Permission::firstOrCreate(['name' => 'fees.view', 'guard_name' => 'web']);
        $pStudentView = Permission::firstOrCreate(['name' => 'students.view', 'guard_name' => 'web']);

        $rSuperAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        // Note: Super admin does NOT have fees.bulk_bill explicitly in role_has_permissions
        $rSuperAdmin->syncPermissions([$pFeeView]);

        $rSchoolAdmin = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $rSchoolAdmin->syncPermissions([$pBulkBill, $pFeeView, $pStudentView]);

        $rAccountant = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $rAccountant->syncPermissions([$pFeeView]); // Has fees.view but NOT fees.bulk_bill

        $rTeacher = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $rTeacher->syncPermissions([$pStudentView]); // No fee permissions

        // 2. Setup Schools
        $this->schoolA = School::create([
            'name'      => 'Alpha School',
            'slug'      => 'alpha-' . uniqid(),
            'is_active' => true,
        ]);

        $this->schoolB = School::create([
            'name'      => 'Beta School',
            'slug'      => 'beta-' . uniqid(),
            'is_active' => true,
        ]);

        // Entitlement subscriptions for both schools
        $package = Package::create([
            'name'          => 'Pro Package',
            'slug'          => 'pro-' . uniqid(),
            'price_monthly' => 50,
            'price_yearly'  => 500,
            'max_students'  => 500,
            'max_staff'     => 50,
            'storage_gb'    => 50,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'fees']);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'students']);

        foreach ([$this->schoolA, $this->schoolB] as $sch) {
            SchoolSubscription::create([
                'school_id'  => $sch->id,
                'package_id' => $package->id,
                'status'     => 'active',
                'start_date' => Carbon::now()->subDays(10),
                'end_date'   => Carbon::now()->addDays(50),
            ]);
        }

        // Classes
        $this->classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'name' => 'Grade 10']);
        $this->classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'name' => 'Grade 11']);

        // 3. Setup Users
        $this->superAdmin = User::create([
            'name'      => 'Platform Super Admin',
            'email'     => 'superadmin@platform.test',
            'password'  => bcrypt('password'),
            'school_id' => null,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super-admin');

        $this->schoolAdminWithPerm = User::create([
            'name'      => 'School Admin A',
            'email'     => 'admina@schoola.test',
            'password'  => bcrypt('password'),
            'school_id' => $this->schoolA->id,
            'is_active' => true,
        ]);
        $this->schoolAdminWithPerm->assignRole('school-admin');

        $this->accountantWithoutPerm = User::create([
            'name'      => 'Accountant A',
            'email'     => 'accountant@schoola.test',
            'password'  => bcrypt('password'),
            'school_id' => $this->schoolA->id,
            'is_active' => true,
        ]);
        $this->accountantWithoutPerm->assignRole('accountant');

        $this->teacher = User::create([
            'name'      => 'Teacher A',
            'email'     => 'teacher@schoola.test',
            'password'  => bcrypt('password'),
            'school_id' => $this->schoolA->id,
            'is_active' => true,
        ]);
        $this->teacher->assignRole('teacher');
    }

    public function test_super_admin_with_active_school_context_can_access_bulk_assign(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->withSession(['active_school_id' => $this->schoolA->id])
            ->get('/school/fees/structures/bulk-assign');

        $response->assertStatus(200);
    }

    public function test_school_admin_with_bulk_bill_permission_can_access_bulk_assign(): void
    {
        $response = $this->actingAs($this->schoolAdminWithPerm)
            ->get('/school/fees/structures/bulk-assign');

        $response->assertStatus(200);
    }

    public function test_unauthorized_users_without_permission_receive_403(): void
    {
        // Accountant lacking fees.bulk_bill
        $responseAccountant = $this->actingAs($this->accountantWithoutPerm)
            ->get('/school/fees/structures/bulk-assign');
        $responseAccountant->assertStatus(403);

        // Teacher lacking fees.bulk_bill
        $responseTeacher = $this->actingAs($this->teacher)
            ->get('/school/fees/structures/bulk-assign');
        $responseTeacher->assertStatus(403);
    }

    public function test_super_admin_without_active_school_context_is_redirected_or_denied(): void
    {
        // Super Admin accessing tenant route without selecting an active school context fails closed
        $response = $this->actingAs($this->superAdmin)
            ->get('/school/fees/structures/bulk-assign');

        // Redirected to school selection
        $response->assertRedirect(route('super-admin.schools.index'));
    }

    public function test_tenant_isolation_is_strictly_enforced_for_super_admin(): void
    {
        // Super admin managing School A attempts to access Class of School B
        $response = $this->actingAs($this->superAdmin)
            ->withSession(['active_school_id' => $this->schoolA->id])
            ->getJson("/school/fees/structures/classes/{$this->classB->id}/students");

        // Cross-tenant access fails closed (404 via SchoolScope route model binding or 403 controller abort)
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_gate_before_does_not_grant_permissions_to_ordinary_roles(): void
    {
        // Assert that accountant does NOT pass the fees.bulk_bill gate
        $this->assertFalse($this->accountantWithoutPerm->can('fees.bulk_bill'));
        $this->assertFalse(Gate::forUser($this->accountantWithoutPerm)->allows('fees.bulk_bill'));

        // Assert that teacher does NOT pass fee permissions
        $this->assertFalse($this->teacher->can('fees.bulk_bill'));
        $this->assertFalse($this->teacher->can('fees.view'));

        // Assert that super-admin passes via Gate::before
        $this->assertTrue($this->superAdmin->can('fees.bulk_bill'));
        $this->assertTrue(Gate::forUser($this->superAdmin)->allows('fees.bulk_bill'));
    }
}
