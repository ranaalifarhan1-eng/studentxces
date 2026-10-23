<?php

namespace Tests\Feature\SchoolAdmin;

use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeChallan;
use App\Models\FeeChallanItem;
use App\Models\FeeStructure;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\Student;
use App\Models\StudentFeeAssignment;
use App\Models\User;
use App\Services\FeeBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FeeStructureDeletionSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $otherSchool;
    protected User $superAdmin;
    protected User $schoolAdmin;
    protected User $accountant;
    protected User $teacher;
    protected User $customRoleUser;
    protected Role $customRole;
    protected SchoolClass $class;
    protected FeeCategory $category;
    protected AcademicYear $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Roles & Permissions setup
        $permissions = [
            'fees.view',
            'fees.structure',
            'fees.structures.delete',
            'fees.collect',
            'fees.assign',
            'school.module:fees',
            'school.module:students',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $superAdminRole = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $schoolAdminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $accountantRole = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $teacherRole = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $this->customRole = Role::firstOrCreate(['name' => 'bursar', 'guard_name' => 'web']);

        $schoolAdminRole->syncPermissions([
            'fees.view',
            'fees.structure',
            'fees.structures.delete',
            'fees.collect',
            'fees.assign',
            'school.module:fees',
            'school.module:students',
        ]);

        // Accountant gets general fees.structure, but NOT fees.structures.delete
        $accountantRole->syncPermissions([
            'fees.view',
            'fees.structure',
            'fees.collect',
            'school.module:fees',
        ]);

        $teacherRole->syncPermissions([
            'school.module:students',
        ]);

        // 2. Schools
        $this->school = School::create([
            'name'   => 'Test Academy',
            'slug'   => 'test-acad-' . uniqid(),
            'status' => 'active',
        ]);

        $this->otherSchool = School::create([
            'name'   => 'Other School',
            'slug'   => 'other-sch-' . uniqid(),
            'status' => 'active',
        ]);

        // 3. Subscriptions
        $package = Package::create([
            'name'          => 'Enterprise Plan',
            'slug'          => 'ent-' . uniqid(),
            'price_monthly' => 50,
            'price_yearly'  => 500,
            'max_students'  => 500,
            'max_staff'     => 50,
            'storage_gb'    => 50,
            'is_active'     => true,
        ]);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'fees']);
        PackageModule::create(['package_id' => $package->id, 'module_slug' => 'students']);

        foreach ([$this->school, $this->otherSchool] as $s) {
            SchoolSubscription::create([
                'school_id'  => $s->id,
                'package_id' => $package->id,
                'status'     => 'active',
                'start_date' => now()->subDay(),
                'end_date'   => now()->addYear(),
            ]);
        }

        // 4. Users
        $this->superAdmin = User::create([
            'name'      => 'Platform SuperAdmin',
            'email'     => 'super-' . uniqid() . '@test.local',
            'password'  => bcrypt('pass'),
            'school_id' => null,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super-admin');

        $this->schoolAdmin = User::create([
            'name'      => 'School Admin',
            'email'     => 'admin-' . uniqid() . '@test.local',
            'password'  => bcrypt('pass'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->schoolAdmin->assignRole('school-admin');

        $this->accountant = User::create([
            'name'      => 'Staff Accountant',
            'email'     => 'acct-' . uniqid() . '@test.local',
            'password'  => bcrypt('pass'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->accountant->assignRole('accountant');

        $this->teacher = User::create([
            'name'      => 'Staff Teacher',
            'email'     => 'teacher-' . uniqid() . '@test.local',
            'password'  => bcrypt('pass'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->teacher->assignRole('teacher');

        $this->customRoleUser = User::create([
            'name'      => 'Bursar User',
            'email'     => 'bursar-' . uniqid() . '@test.local',
            'password'  => bcrypt('pass'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->customRoleUser->assignRole('bursar');

        // 5. Structure & Categories
        $this->class = SchoolClass::create([
            'school_id' => $this->school->id,
            'name'      => 'Class 10',
        ]);

        $this->category = FeeCategory::create([
            'school_id' => $this->school->id,
            'name'      => 'Tuition Fee',
            'type'      => 'tuition',
            'is_active' => true,
        ]);

        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => '2026-2027',
            'start_date' => now()->startOfYear(),
            'end_date'   => now()->endOfYear(),
            'is_current' => true,
        ]);
    }

    protected function createFeeStructure(array $overrides = []): FeeStructure
    {
        return FeeStructure::create(array_merge([
            'school_id'        => $this->school->id,
            'class_id'         => $this->class->id,
            'fee_category_id'  => $this->category->id,
            'academic_year'    => '2026-2027',
            'amount'           => 1500,
            'due_date'         => now()->addDays(5),
            'frequency'        => 'monthly',
            'is_active'        => true,
            'is_optional'      => false,
        ], $overrides));
    }

    protected function createStudent(): Student
    {
        return Student::create([
            'school_id'      => $this->school->id,
            'class_id'       => $this->class->id,
            'admission_no'   => 'ADM-' . uniqid(),
            'first_name'     => 'TestStudent',
            'admission_date' => now(),
            'status'         => 'active',
        ]);
    }

    public function test_authorized_delete_of_unused_structure_succeeds_and_is_soft_deleted(): void
    {
        $structure = $this->createFeeStructure();

        $response = $this->actingAs($this->schoolAdmin)
            ->delete(route('school.fees.structures.destroy', $structure->id));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Fee structure deleted.');

        $this->assertSoftDeleted('fee_structures', [
            'id' => $structure->id,
        ]);
    }

    public function test_delete_blocked_when_student_fee_assignment_exists(): void
    {
        $structure = $this->createFeeStructure();
        $student = $this->createStudent();

        $assignment = StudentFeeAssignment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $student->id,
            'fee_structure_id' => $structure->id,
            'academic_year_id' => $this->academicYear->id,
            'starts_on'        => now(),
            'is_active'        => true,
        ]);

        $response = $this->actingAs($this->schoolAdmin)
            ->delete(route('school.fees.structures.destroy', $structure->id));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'This fee structure cannot be deleted because it is already assigned or used in fee challans. Deactivate it instead to preserve financial history.');

        // Verify zero mutations: structure is NOT soft-deleted
        $this->assertDatabaseHas('fee_structures', [
            'id'         => $structure->id,
            'deleted_at' => null,
        ]);

        // Verify child assignment survived
        $this->assertDatabaseHas('student_fee_assignments', [
            'id' => $assignment->id,
        ]);
    }

    public function test_delete_blocked_when_unpaid_fee_challan_item_exists(): void
    {
        $structure = $this->createFeeStructure();
        $student = $this->createStudent();

        $challan = FeeChallan::create([
            'school_id'          => $this->school->id,
            'challan_no'         => 'CHL-' . uniqid(),
            'billing_period_key' => 'AY1-2026-10',
            'student_id'         => $student->id,
            'class_id'           => $this->class->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_name'       => 'TestStudent',
            'admission_no'       => $student->admission_no,
            'class_name'         => 'Class 10',
            'academic_year_name' => '2026-2027',
            'issue_date'         => now(),
            'due_date'           => now()->addDays(5),
            'gross_amount'       => 1500,
            'total_payable'      => 1500,
            'paid_amount'        => 0,
            'status'             => 'unpaid',
        ]);

        $item = FeeChallanItem::create([
            'fee_challan_id'    => $challan->id,
            'school_id'         => $this->school->id,
            'student_id'        => $student->id,
            'fee_structure_id'  => $structure->id,
            'charge_period_key' => 'AY1-M-2026-10',
            'fee_head_name'     => 'Tuition Fee',
            'gross_amount'      => 1500,
            'net_amount'        => 1500,
        ]);

        $response = $this->actingAs($this->schoolAdmin)
            ->delete(route('school.fees.structures.destroy', $structure->id));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('fee_structures', [
            'id'         => $structure->id,
            'deleted_at' => null,
        ]);

        $this->assertDatabaseHas('fee_challan_items', [
            'id' => $item->id,
        ]);
    }

    public function test_delete_blocked_when_partial_or_paid_fee_challan_item_exists(): void
    {
        $structure = $this->createFeeStructure();
        $student = $this->createStudent();

        $challan = FeeChallan::create([
            'school_id'          => $this->school->id,
            'challan_no'         => 'CHL-PAID-' . uniqid(),
            'billing_period_key' => 'AY1-2026-10',
            'student_id'         => $student->id,
            'class_id'           => $this->class->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_name'       => 'TestStudent',
            'admission_no'       => $student->admission_no,
            'class_name'         => 'Class 10',
            'academic_year_name' => '2026-2027',
            'issue_date'         => now(),
            'due_date'           => now()->addDays(5),
            'gross_amount'       => 1500,
            'total_payable'      => 1500,
            'paid_amount'        => 1500,
            'status'             => 'paid',
        ]);

        FeeChallanItem::create([
            'fee_challan_id'    => $challan->id,
            'school_id'         => $this->school->id,
            'student_id'        => $student->id,
            'fee_structure_id'  => $structure->id,
            'charge_period_key' => 'AY1-M-2026-10',
            'fee_head_name'     => 'Tuition Fee',
            'gross_amount'      => 1500,
            'net_amount'        => 1500,
        ]);

        $response = $this->actingAs($this->schoolAdmin)
            ->delete(route('school.fees.structures.destroy', $structure->id));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('fee_structures', [
            'id'         => $structure->id,
            'deleted_at' => null,
        ]);
    }

    public function test_unauthorized_teacher_receives_403(): void
    {
        $structure = $this->createFeeStructure();

        $response = $this->actingAs($this->teacher)
            ->delete(route('school.fees.structures.destroy', $structure->id));

        $response->assertStatus(403);
        $this->assertDatabaseHas('fee_structures', [
            'id'         => $structure->id,
            'deleted_at' => null,
        ]);
    }

    public function test_unauthorized_accountant_receives_403_unless_explicitly_granted(): void
    {
        $structure = $this->createFeeStructure();

        // 1. Accountant has fees.structure but NOT fees.structures.delete -> 403
        $response = $this->actingAs($this->accountant)
            ->delete(route('school.fees.structures.destroy', $structure->id));

        $response->assertStatus(403);
        $this->assertDatabaseHas('fee_structures', [
            'id'         => $structure->id,
            'deleted_at' => null,
        ]);

        // 2. Grant fees.structures.delete explicitly to accountant -> allowed
        $this->accountant->givePermissionTo('fees.structures.delete');
        $this->accountant->refresh();

        $allowedResponse = $this->actingAs($this->accountant)
            ->delete(route('school.fees.structures.destroy', $structure->id));

        $allowedResponse->assertRedirect();
        $this->assertSoftDeleted('fee_structures', [
            'id' => $structure->id,
        ]);
    }

    public function test_school_admin_permission_revocation_enforces_403(): void
    {
        $structure = $this->createFeeStructure();

        // Revoke fees.structures.delete from role and user
        Role::findByName('school-admin', 'web')->revokePermissionTo('fees.structures.delete');
        $this->schoolAdmin->revokePermissionTo('fees.structures.delete');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->schoolAdmin->refresh();

        $response = $this->actingAs($this->schoolAdmin)
            ->delete(route('school.fees.structures.destroy', $structure->id));

        $response->assertStatus(403);
        $this->assertDatabaseHas('fee_structures', [
            'id'         => $structure->id,
            'deleted_at' => null,
        ]);
    }

    public function test_custom_role_authorization_with_and_without_permission(): void
    {
        $structure = $this->createFeeStructure();

        // Custom staff role 'auditor' with teacher base role for route access
        $auditorUser = User::create([
            'name'      => 'Auditor User',
            'email'     => 'auditor-' . uniqid() . '@test.local',
            'password'  => bcrypt('pass'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $auditorUser->assignRole('accountant');
        $auditorUser->givePermissionTo('school.module:fees');
        $auditorUser->givePermissionTo('fees.structure');
        $auditorUser->revokePermissionTo('fees.structures.delete');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $auditorUser->refresh();

        // 1. Without fees.structures.delete -> 403
        $deniedResp = $this->actingAs($auditorUser)
            ->delete(route('school.fees.structures.destroy', $structure->id));

        $deniedResp->assertStatus(403);
        $this->assertDatabaseHas('fee_structures', ['id' => $structure->id, 'deleted_at' => null]);

        // 2. Grant fees.structures.delete explicitly -> allowed 302
        $auditorUser->givePermissionTo('fees.structures.delete');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $auditorUser->refresh();

        $allowedResp = $this->actingAs($auditorUser)
            ->delete(route('school.fees.structures.destroy', $structure->id));

        $allowedResp->assertRedirect();
        $this->assertSoftDeleted('fee_structures', ['id' => $structure->id]);
    }

    public function test_cross_school_structure_delete_is_denied(): void
    {
        $otherClass = SchoolClass::create(['school_id' => $this->otherSchool->id, 'name' => 'Other Class']);
        $otherCat = FeeCategory::create(['school_id' => $this->otherSchool->id, 'name' => 'Other Cat', 'type' => 'tuition']);
        $otherStructure = FeeStructure::create([
            'school_id'        => $this->otherSchool->id,
            'class_id'         => $otherClass->id,
            'fee_category_id'  => $otherCat->id,
            'academic_year'    => '2026-2027',
            'amount'           => 1000,
            'frequency'        => 'monthly',
        ]);

        $response = $this->actingAs($this->schoolAdmin)
            ->delete(route('school.fees.structures.destroy', $otherStructure->id));

        $this->assertTrue(in_array($response->status(), [403, 404]));

        $this->assertDatabaseHas('fee_structures', [
            'id'         => $otherStructure->id,
            'deleted_at' => null,
        ]);
    }

    public function test_historical_challan_item_resolves_soft_deleted_structure(): void
    {
        $structure = $this->createFeeStructure(['amount' => 3200]);
        $student = $this->createStudent();

        $challan = FeeChallan::create([
            'school_id'          => $this->school->id,
            'challan_no'         => 'CHL-HIST-' . uniqid(),
            'billing_period_key' => 'AY1-2026-10',
            'student_id'         => $student->id,
            'class_id'           => $this->class->id,
            'academic_year_id'   => $this->academicYear->id,
            'student_name'       => 'TestStudent',
            'admission_no'       => $student->admission_no,
            'class_name'         => 'Class 10',
            'academic_year_name' => '2026-2027',
            'issue_date'         => now(),
            'due_date'           => now()->addDays(5),
            'gross_amount'       => 3200,
            'total_payable'      => 3200,
            'paid_amount'        => 0,
            'status'             => 'unpaid',
        ]);

        $item = FeeChallanItem::create([
            'fee_challan_id'    => $challan->id,
            'school_id'         => $this->school->id,
            'student_id'        => $student->id,
            'fee_structure_id'  => $structure->id,
            'charge_period_key' => 'AY1-M-2026-10',
            'fee_head_name'     => 'Tuition Fee',
            'gross_amount'      => 3200,
            'net_amount'        => 3200,
        ]);

        $payment = \App\Models\FeePayment::create([
            'school_id'        => $this->school->id,
            'student_id'       => $student->id,
            'fee_challan_id'   => $challan->id,
            'fee_structure_id' => $structure->id,
            'receipt_no'       => 'REC-TEST-HIST-' . uniqid(),
            'amount_due'       => 3200,
            'amount_paid'      => 3200,
            'payment_date'     => now(),
            'status'           => 'paid',
        ]);

        // Simulate soft-deletion of structure
        $structure->delete();
        $this->assertSoftDeleted('fee_structures', ['id' => $structure->id]);

        // Historical challan item MUST resolve the structure through withTrashed()
        $resolvedStructure = $item->fresh()->feeStructure;
        $this->assertNotNull($resolvedStructure, 'FeeChallanItem::feeStructure must resolve soft-deleted structures.');
        $this->assertEquals(3200, (int)$resolvedStructure->amount);
        $this->assertEquals($this->category->id, $resolvedStructure->feeCategory->id);

        // Historical payment MUST resolve the structure through withTrashed()
        $resolvedPaymentStructure = $payment->fresh()->feeStructure;
        $this->assertNotNull($resolvedPaymentStructure, 'FeePayment::feeStructure must resolve soft-deleted structures.');
        $this->assertEquals(3200, (int)$resolvedPaymentStructure->amount);
        $this->assertEquals($this->category->id, $resolvedPaymentStructure->feeCategory->id);
    }

    public function test_soft_deleted_structure_is_excluded_from_new_billing(): void
    {
        $structure = $this->createFeeStructure();
        $student = $this->createStudent();

        // Soft-delete structure
        $structure->delete();
        $this->assertTrue($structure->trashed());

        // Active structure queries used during challan generation exclude soft-deleted structure
        $activeStructures = FeeStructure::where('school_id', $this->school->id)
            ->where('class_id', $student->class_id)
            ->where('academic_year', $this->academicYear->name)
            ->where('is_active', true)
            ->get();

        $this->assertFalse($activeStructures->contains('id', $structure->id));
        $this->assertCount(0, $activeStructures);
    }

    public function test_index_passes_can_delete_prop_based_on_permission(): void
    {
        // 1. School Admin (has permission)
        $adminResp = $this->actingAs($this->schoolAdmin)
            ->get(route('school.fees.structures.index'));

        $adminResp->assertOk();
        $adminResp->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Structures')
            ->where('canDelete', true)
        );

        // 2. Accountant (lacks delete permission)
        $acctResp = $this->actingAs($this->accountant)
            ->get(route('school.fees.structures.index'));

        $acctResp->assertOk();
        $acctResp->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Fees/Structures')
            ->where('canDelete', false)
        );
    }
}
