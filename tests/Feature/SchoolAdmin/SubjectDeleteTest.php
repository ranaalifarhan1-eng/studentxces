<?php

namespace Tests\Feature\SchoolAdmin;

use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\Homework;
use App\Models\LessonPlan;
use App\Models\Mark;
use App\Models\OnlineClass;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Syllabus;
use App\Models\Timetable;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubjectDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected School $otherSchool;
    protected User $adminUser;
    protected User $teacherUser;
    protected SchoolClass $class;
    protected Subject $unusedSubject;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Roles & Permissions
        $deletePerm = Permission::firstOrCreate(['name' => 'subjects.delete', 'guard_name' => 'web']);
        $adminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $adminRole->givePermissionTo($deletePerm);
        Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        // 2. Schools
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

        // 3. Package & Subscriptions (students module for subjects)
        $package = Package::create([
            'name'          => 'Academic Enterprise Plan',
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

        // 4. Users
        $this->adminUser = User::create([
            'name'      => 'Admin Tester',
            'email'     => 'admin@testschool.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('school-admin');

        $this->teacherUser = User::create([
            'name'      => 'Teacher Tester',
            'email'     => 'teacher@testschool.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $this->teacherUser->assignRole('teacher');

        // 5. Class & Subject
        $this->class = SchoolClass::create([
            'school_id' => $this->school->id,
            'name'      => 'Grade 10',
        ]);

        $this->unusedSubject = Subject::create([
            'school_id'  => $this->school->id,
            'class_id'   => $this->class->id,
            'name'       => 'Ancient History',
            'code'       => 'HIST101',
            'type'       => 'theory',
            'full_marks' => 100,
            'pass_marks' => 33,
        ]);
    }

    public function test_authorized_delete_of_unused_subject_succeeds_and_is_soft_deleted(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->delete(route('school.subjects.destroy', $this->unusedSubject));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Subject deleted.');

        $this->assertSoftDeleted('subjects', [
            'id' => $this->unusedSubject->id,
        ]);
    }

    public function test_subject_from_another_school_cannot_be_deleted(): void
    {
        $otherClass = SchoolClass::create([
            'school_id' => $this->otherSchool->id,
            'name'      => 'Other Grade',
        ]);

        $otherSubject = Subject::create([
            'school_id'  => $this->otherSchool->id,
            'class_id'   => $otherClass->id,
            'name'       => 'Other School Subject',
            'type'       => 'theory',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->delete(route('school.subjects.destroy', $otherSubject));

        // BelongsToSchool global scope makes other-tenant model resolve as 404, or abort(403)
        $this->assertTrue(in_array($response->status(), [403, 404]));

        $this->assertDatabaseHas('subjects', [
            'id'         => $otherSubject->id,
            'deleted_at' => null,
        ]);
    }

    public function test_unauthorized_role_is_denied(): void
    {
        $response = $this->actingAs($this->teacherUser)
            ->delete(route('school.subjects.destroy', $this->unusedSubject));

        $response->assertStatus(403);

        $this->assertDatabaseHas('subjects', [
            'id'         => $this->unusedSubject->id,
            'deleted_at' => null,
        ]);
    }

    public function test_subject_with_timetable_dependency_cannot_be_deleted(): void
    {
        Timetable::create([
            'school_id'   => $this->school->id,
            'class_id'    => $this->class->id,
            'subject_id'  => $this->unusedSubject->id,
            'day_of_week' => 'monday',
            'start_time'  => '09:00:00',
            'end_time'    => '10:00:00',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->delete(route('school.subjects.destroy', $this->unusedSubject));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionHasErrors(['error']);

        // Subject must remain untouched (NOT soft deleted)
        $this->assertDatabaseHas('subjects', [
            'id'         => $this->unusedSubject->id,
            'deleted_at' => null,
        ]);

        // Timetable record must remain unchanged
        $this->assertDatabaseHas('timetables', [
            'school_id'  => $this->school->id,
            'subject_id' => $this->unusedSubject->id,
        ]);
    }

    public function test_subject_with_marks_dependency_cannot_be_deleted(): void
    {
        $student = Student::create([
            'school_id'  => $this->school->id,
            'class_id'   => $this->class->id,
            'first_name' => 'Alice',
            'status'     => 'active',
        ]);

        $exam = Exam::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->class->id,
            'name'      => 'Midterm 2026',
            'start_date'=> Carbon::now(),
            'end_date'  => Carbon::now()->addDays(5),
        ]);

        Mark::create([
            'school_id'      => $this->school->id,
            'exam_id'        => $exam->id,
            'student_id'     => $student->id,
            'subject_id'     => $this->unusedSubject->id,
            'marks_obtained' => 88.5,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->delete(route('school.subjects.destroy', $this->unusedSubject));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionHasErrors(['error']);

        $this->assertDatabaseHas('subjects', [
            'id'         => $this->unusedSubject->id,
            'deleted_at' => null,
        ]);

        $this->assertDatabaseHas('marks', [
            'school_id'  => $this->school->id,
            'subject_id' => $this->unusedSubject->id,
        ]);
    }

    public function test_subject_with_homework_dependency_cannot_be_deleted(): void
    {
        Homework::create([
            'school_id'   => $this->school->id,
            'class_id'    => $this->class->id,
            'subject_id'  => $this->unusedSubject->id,
            'title'       => 'Chapter 1 Assignment',
            'due_date'    => Carbon::now()->addDays(3),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->delete(route('school.subjects.destroy', $this->unusedSubject));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionHasErrors(['error']);

        $this->assertDatabaseHas('subjects', [
            'id'         => $this->unusedSubject->id,
            'deleted_at' => null,
        ]);

        $this->assertDatabaseHas('homework', [
            'school_id'  => $this->school->id,
            'subject_id' => $this->unusedSubject->id,
        ]);
    }

    public function test_custom_role_with_subjects_delete_is_allowed(): void
    {
        $customRole = Role::firstOrCreate(['name' => 'academic-coordinator', 'guard_name' => 'web']);
        $customRole->givePermissionTo('subjects.delete');

        $coordUser = User::create([
            'name'      => 'Coordinator User',
            'email'     => 'coordinator@testschool.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        // Has staff route access via teacher role plus custom coordinator role with subjects.delete
        $coordUser->assignRole(['teacher', 'academic-coordinator']);

        $response = $this->actingAs($coordUser)
            ->delete(route('school.subjects.destroy', $this->unusedSubject));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Subject deleted.');
        $this->assertSoftDeleted('subjects', ['id' => $this->unusedSubject->id]);
    }

    public function test_staff_user_without_subjects_delete_is_forbidden_but_allowed_once_granted(): void
    {
        // Teacher user without subjects.delete receives 403
        $response = $this->actingAs($this->teacherUser)
            ->delete(route('school.subjects.destroy', $this->unusedSubject));
        $response->assertStatus(403);

        // Grant permission to teacher user
        $this->teacherUser->givePermissionTo('subjects.delete');

        $response = $this->actingAs($this->teacherUser)
            ->delete(route('school.subjects.destroy', $this->unusedSubject));
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Subject deleted.');
        $this->assertSoftDeleted('subjects', ['id' => $this->unusedSubject->id]);
    }

    public function test_cross_tenant_user_with_permission_cannot_delete_subject_belonging_to_another_school(): void
    {
        $otherAdmin = User::create([
            'name'      => 'Other School Admin',
            'email'     => 'otheradmin@otherschool.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->otherSchool->id,
            'is_active' => true,
        ]);
        $otherAdmin->assignRole('school-admin');

        $response = $this->actingAs($otherAdmin)
            ->delete(route('school.subjects.destroy', $this->unusedSubject));

        // BelongsToSchool global scope / fail-closed tenant scoping ensures 403 or 404
        $this->assertTrue(in_array($response->status(), [403, 404]));

        $this->assertDatabaseHas('subjects', [
            'id'         => $this->unusedSubject->id,
            'deleted_at' => null,
        ]);
    }

    public function test_subject_with_lesson_plan_dependency_cannot_be_deleted(): void
    {
        LessonPlan::create([
            'school_id'  => $this->school->id,
            'class_id'   => $this->class->id,
            'subject_id' => $this->unusedSubject->id,
            'title'      => 'Introduction to Ancient Rome',
            'week_start' => Carbon::now()->startOfWeek()->toDateString(),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->delete(route('school.subjects.destroy', $this->unusedSubject));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionHasErrors(['error']);

        $this->assertDatabaseHas('subjects', [
            'id'         => $this->unusedSubject->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('lesson_plans', [
            'school_id'  => $this->school->id,
            'subject_id' => $this->unusedSubject->id,
        ]);
    }

    public function test_subject_with_syllabus_dependency_cannot_be_deleted(): void
    {
        Syllabus::create([
            'school_id'     => $this->school->id,
            'class_id'      => $this->class->id,
            'subject_id'    => $this->unusedSubject->id,
            'title'         => 'Term 1 History Syllabus',
            'academic_year' => '2026-2027',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->delete(route('school.subjects.destroy', $this->unusedSubject));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionHasErrors(['error']);

        $this->assertDatabaseHas('subjects', [
            'id'         => $this->unusedSubject->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('syllabi', [
            'school_id'  => $this->school->id,
            'subject_id' => $this->unusedSubject->id,
        ]);
    }

    public function test_subject_with_online_class_dependency_cannot_be_deleted(): void
    {
        OnlineClass::create([
            'school_id'    => $this->school->id,
            'class_id'     => $this->class->id,
            'subject_id'   => $this->unusedSubject->id,
            'title'        => 'Online Lecture',
            'platform'     => 'zoom',
            'meeting_url'  => 'https://zoom.us/j/123456789',
            'scheduled_at' => Carbon::now()->addDays(1),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->delete(route('school.subjects.destroy', $this->unusedSubject));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionHasErrors(['error']);

        $this->assertDatabaseHas('subjects', [
            'id'         => $this->unusedSubject->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('online_classes', [
            'school_id'  => $this->school->id,
            'subject_id' => $this->unusedSubject->id,
        ]);
    }

    public function test_super_admin_receives_can_delete_true_on_subjects_index(): void
    {
        $superAdminRole = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $superUser = User::create([
            'name'      => 'Super User',
            'email'     => 'super@testschool.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $superUser->assignRole('super-admin');

        $response = $this->actingAs($superUser)
            ->withSession(['active_school_id' => $this->school->id])
            ->get(route('school.subjects.index'));
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Subjects/Index')
            ->where('canDelete', true)
        );
    }

    public function test_school_admin_receives_can_delete_true_on_subjects_index(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('school.subjects.index'));
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Subjects/Index')
            ->where('canDelete', true)
        );
    }

    public function test_custom_role_with_permission_receives_can_delete_true_on_subjects_index(): void
    {
        $customRole = Role::firstOrCreate(['name' => 'academic-coordinator', 'guard_name' => 'web']);
        $customRole->givePermissionTo('subjects.delete');

        $coordUser = User::create([
            'name'      => 'Coord User',
            'email'     => 'coord@testschool.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $coordUser->assignRole(['teacher', 'academic-coordinator']);

        $response = $this->actingAs($coordUser)->get(route('school.subjects.index'));
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Subjects/Index')
            ->where('canDelete', true)
        );
    }

    public function test_teacher_without_permission_receives_can_delete_false_on_subjects_index(): void
    {
        $response = $this->actingAs($this->teacherUser)->get(route('school.subjects.index'));
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Subjects/Index')
            ->where('canDelete', false)
        );
    }

    public function test_principal_without_permission_receives_can_delete_false_on_subjects_index(): void
    {
        Role::firstOrCreate(['name' => 'principal', 'guard_name' => 'web']);
        $principalUser = User::create([
            'name'      => 'Principal User',
            'email'     => 'principal@testschool.test',
            'password'  => bcrypt('secret123'),
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);
        $principalUser->assignRole('principal');

        $response = $this->actingAs($principalUser)->get(route('school.subjects.index'));
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Subjects/Index')
            ->where('canDelete', false)
        );
    }
}
