<?php

namespace Tests\Feature\SchoolAdmin;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolSubscription;
use App\Models\Staff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffRelationalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $schoolB;
    protected User $adminUser;
    protected Department $deptScience;
    protected Department $deptAccounts;
    protected Designation $desigScienceTeacher;
    protected Designation $desigAccountant;
    protected Designation $desigSchoolB;

    protected function setUp(): void
    {
        parent::setUp();

        $pView = Permission::firstOrCreate(['name' => 'staff.view', 'guard_name' => 'web']);
        $pCreate = Permission::firstOrCreate(['name' => 'staff.create', 'guard_name' => 'web']);
        $pEdit = Permission::firstOrCreate(['name' => 'staff.edit', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([$pView, $pCreate, $pEdit]);

        $this->school = School::create([
            'name'     => 'School A',
            'slug'     => 'school-a-' . uniqid(),
            'email'    => 'admin-' . uniqid() . '@a.test',
            'status'   => 'active',
            'timezone' => 'Asia/Karachi',
        ]);

        $this->schoolB = School::create([
            'name'     => 'School B',
            'slug'     => 'school-b-' . uniqid(),
            'email'    => 'admin-' . uniqid() . '@b.test',
            'status'   => 'active',
            'timezone' => 'Asia/Karachi',
        ]);

        $package = Package::create([
            'name'          => 'Pro Plan',
            'slug'          => 'pro-' . uniqid(),
            'price_monthly' => 50,
            'price_yearly'  => 500,
            'max_students'  => 500,
            'max_staff'     => 50,
            'storage_gb'    => 20,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'staff']);

        SchoolSubscription::create([
            'school_id'   => $this->school->id,
            'package_id'  => $package->id,
            'status'      => 'active',
            'start_date'  => Carbon::now()->subDays(10),
            'end_date'    => Carbon::now()->addDays(30),
        ]);

        $this->adminUser = User::create([
            'name'              => 'Admin User',
            'email'             => 'admin-' . uniqid() . '@a.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->deptScience = Department::create([
            'school_id' => $this->school->id,
            'name'      => 'Science Department',
        ]);

        $this->deptAccounts = Department::create([
            'school_id' => $this->school->id,
            'name'      => 'Accounts Department',
        ]);

        $this->desigScienceTeacher = Designation::create([
            'school_id'     => $this->school->id,
            'department_id' => $this->deptScience->id,
            'name'          => 'Science Teacher',
        ]);

        $this->desigAccountant = Designation::create([
            'school_id'     => $this->school->id,
            'department_id' => $this->deptAccounts->id,
            'name'          => 'Accountant',
        ]);

        $deptB = Department::create([
            'school_id' => $this->schoolB->id,
            'name'      => 'Other Dept',
        ]);

        $this->desigSchoolB = Designation::create([
            'school_id'     => $this->schoolB->id,
            'department_id' => $deptB->id,
            'name'          => 'Other Designation',
        ]);
    }

    public function test_staff_creation_succeeds_with_matching_department_and_designation(): void
    {
        $payload = [
            'first_name'     => 'Rashid',
            'gender'         => 'male',
            'department_id'  => $this->deptScience->id,
            'designation_id' => $this->desigScienceTeacher->id,
            'salary_type'    => 'fixed',
            'salary'         => 35000,
            'status'         => 'active',
            'date_of_birth'  => '1990-05-15',
            'joining_date'   => '2025-01-01',
        ];

        $response = $this->actingAs($this->adminUser)->post('/school/staff', $payload);
        $response->assertSessionHasNoErrors();

        $staff = Staff::where('first_name', 'Rashid')->first();
        $this->assertNotNull($staff);
        $this->assertEquals($this->deptScience->id, $staff->department_id);
        $this->assertEquals($this->desigScienceTeacher->id, $staff->designation_id);
    }

    public function test_staff_creation_rejected_when_designation_belongs_to_different_department(): void
    {
        $staffCountBefore = Staff::count();

        // Science Department with Accountant Designation (Accounts Dept)
        $payload = [
            'first_name'     => 'MismatchedStaff',
            'gender'         => 'male',
            'department_id'  => $this->deptScience->id,
            'designation_id' => $this->desigAccountant->id,
            'salary_type'    => 'fixed',
            'status'         => 'active',
            'date_of_birth'  => '1990-05-15',
        ];

        $response = $this->actingAs($this->adminUser)->post('/school/staff', $payload);
        $response->assertSessionHasErrors(['designation_id']);
        $this->assertEquals($staffCountBefore, Staff::count(), 'Zero staff records must be created.');
    }

    public function test_staff_creation_rejected_when_designation_belongs_to_another_school(): void
    {
        $staffCountBefore = Staff::count();

        $payload = [
            'first_name'     => 'CrossSchoolStaff',
            'gender'         => 'male',
            'department_id'  => $this->deptScience->id,
            'designation_id' => $this->desigSchoolB->id,
            'salary_type'    => 'fixed',
            'status'         => 'active',
        ];

        $response = $this->actingAs($this->adminUser)->post('/school/staff', $payload);
        $response->assertSessionHasErrors(['designation_id']);
        $this->assertEquals($staffCountBefore, Staff::count());
    }

    public function test_staff_creation_rejected_for_future_date_of_birth(): void
    {
        $futureDate = Carbon::now()->addYear()->format('Y-m-d');

        $payload = [
            'first_name'     => 'FutureDOBStaff',
            'gender'         => 'female',
            'salary_type'    => 'fixed',
            'status'         => 'active',
            'date_of_birth'  => $futureDate,
        ];

        $response = $this->actingAs($this->adminUser)->post('/school/staff', $payload);
        $response->assertSessionHasErrors(['date_of_birth']);
    }

    public function test_staff_update_enforces_department_designation_integrity(): void
    {
        $staff = Staff::create([
            'school_id'      => $this->school->id,
            'first_name'     => 'Existing',
            'gender'         => 'male',
            'department_id'  => $this->deptScience->id,
            'designation_id' => $this->desigScienceTeacher->id,
            'salary_type'    => 'fixed',
            'status'         => 'active',
        ]);

        // Attempt invalid update: Accounts department with Science Teacher designation
        $invalidPayload = [
            'first_name'     => 'ExistingUpdated',
            'gender'         => 'male',
            'department_id'  => $this->deptAccounts->id,
            'designation_id' => $this->desigScienceTeacher->id,
            'salary_type'    => 'fixed',
            'status'         => 'active',
        ];

        $response = $this->actingAs($this->adminUser)->put("/school/staff/{$staff->id}", $invalidPayload);
        $response->assertSessionHasErrors(['designation_id']);
        $this->assertEquals($this->deptScience->id, $staff->fresh()->department_id);

        // Valid update: Accounts department with Accountant designation
        $validPayload = [
            'first_name'     => 'ExistingUpdated',
            'gender'         => 'male',
            'department_id'  => $this->deptAccounts->id,
            'designation_id' => $this->desigAccountant->id,
            'salary_type'    => 'fixed',
            'status'         => 'active',
        ];

        $validRes = $this->actingAs($this->adminUser)->put("/school/staff/{$staff->id}", $validPayload);
        $validRes->assertSessionHasNoErrors();
        $this->assertEquals($this->deptAccounts->id, $staff->fresh()->department_id);
        $this->assertEquals($this->desigAccountant->id, $staff->fresh()->designation_id);
    }
}
