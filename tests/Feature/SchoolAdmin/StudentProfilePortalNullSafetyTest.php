<?php

namespace Tests\Feature\SchoolAdmin;

use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\Package;
use App\Models\PackageModule;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSubscription;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Services\PortalCredentialService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentProfilePortalNullSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected Package $package;
    protected User $superAdmin;
    protected User $schoolAdmin;
    protected User $teacher;
    protected User $customRoleUser;
    protected Role $customRole;
    protected SchoolClass $class;
    protected Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Roles & Permissions setup
        $permissions = [
            'students.view',
            'students.create',
            'students.edit',
            'students.delete',
            'students.portal_credentials.view',
            'students.portal_credentials.reset',
            'school.module:students',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $superAdminRole = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $schoolAdminRole = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $teacherRole = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $this->customRole = Role::firstOrCreate(['name' => 'auditor', 'guard_name' => 'web']);

        $schoolAdminRole->syncPermissions([
            'students.view',
            'students.create',
            'students.edit',
            'students.delete',
            'students.portal_credentials.view',
            'students.portal_credentials.reset',
            'school.module:students',
        ]);

        $teacherRole->syncPermissions([
            'students.view',
            'school.module:students',
        ]);

        // 2. School setup
        $this->school = School::create([
            'name'   => 'Lahore Cambridge Test Campus',
            'slug'   => 'lcs-test-' . uniqid(),
            'email'  => 'info@lcs-' . uniqid() . '.test',
            'status' => 'active',
        ]);

        // 3. Package & Subscription
        $this->package = Package::create([
            'name'          => 'Pro School Plan',
            'slug'          => 'pro-' . uniqid(),
            'price_monthly' => 50,
            'price_yearly'  => 500,
            'max_students'  => 500,
            'max_staff'     => 50,
            'storage_gb'    => 50,
            'is_active'     => true,
            'is_internal'   => false,
        ]);
        PackageModule::create(['package_id' => $this->package->id, 'module_slug' => 'students']);

        SchoolSubscription::create([
            'school_id'  => $this->school->id,
            'package_id' => $this->package->id,
            'status'     => 'active',
            'start_date' => Carbon::now()->subDays(10),
            'end_date'   => Carbon::now()->addDays(50),
        ]);

        // 4. Academic Structure
        $this->class = SchoolClass::create([
            'school_id' => $this->school->id,
            'name'      => 'Class 9',
        ]);

        $this->section = Section::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->class->id,
            'name'      => 'A',
        ]);

        // 5. Users
        $this->superAdmin = User::create([
            'name'              => 'Super Administrator',
            'email'             => 'superadmin-' . uniqid() . '@test.local',
            'password'          => bcrypt('password'),
            'school_id'         => null,
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superAdmin->assignRole('super-admin');

        $this->schoolAdmin = User::create([
            'name'              => 'School Principal / Admin',
            'email'             => 'admin-' . uniqid() . '@lcs.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->schoolAdmin->assignRole('school-admin');

        $this->teacher = User::create([
            'name'              => 'Teacher User',
            'email'             => 'teacher-' . uniqid() . '@lcs.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->teacher->assignRole('teacher');

        $this->customRoleUser = User::create([
            'name'              => 'Auditor User',
            'email'             => 'auditor-' . uniqid() . '@lcs.test',
            'password'          => bcrypt('password'),
            'school_id'         => $this->school->id,
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->customRoleUser->assignRole('auditor');
    }

    protected function createSyntheticStudent(array $attributes = []): Student
    {
        return Student::create(array_merge([
            'school_id'       => $this->school->id,
            'first_name'      => 'Test',
            'last_name'       => 'Student',
            'admission_no'    => 'ADM-' . mt_rand(10000, 99999),
            'class_id'        => $this->class->id,
            'section_id'      => $this->section->id,
            'gender'          => 'male',
            'category'        => 'general',
            'status'          => 'active',
            'user_id'         => null,
            'guardian_id'     => null,
        ], $attributes));
    }

    /**
     * Scenario A: Legacy student with user_id = NULL loads safely with HTTP 200 and null portalUser.
     */
    public function test_legacy_student_with_null_user_id_loads_profile_safely(): void
    {
        $student = $this->createSyntheticStudent(['user_id' => null, 'guardian_id' => null]);

        $response = $this->actingAs($this->schoolAdmin)
            ->get(route('school.students.show', $student->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Show')
            ->where('student.id', $student->id)
            ->where('portalUser', null)
            ->where('guardianPortalUser', null)
            ->where('canViewCredentials', true)
            ->where('canResetCredentials', true)
        );
    }

    /**
     * Scenario B: Student with User but no active temp password (permanent password set).
     */
    public function test_student_with_user_but_no_temp_password(): void
    {
        $user = User::create([
            'name'                          => 'Student User',
            'username'                      => 'STD-PERM-01',
            'email'                         => 'student-perm@test.local',
            'password'                      => bcrypt('PermanentPassword123!'),
            'school_id'                     => $this->school->id,
            'must_change_password'          => false,
            'temporary_password_encrypted'  => null,
            'temporary_password_expires_at' => null,
            'is_active'                     => true,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']));

        $student = $this->createSyntheticStudent(['user_id' => $user->id]);

        $response = $this->actingAs($this->schoolAdmin)
            ->get(route('school.students.show', $student->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Show')
            ->where('portalUser.id', $user->id)
            ->where('portalUser.username', 'STD-PERM-01')
            ->where('portalUser.has_active_temp_pass', false)
            ->where('portalUser.must_change_password', false)
        );

        // Attempting to reveal returns 422 error
        $revealResponse = $this->actingAs($this->schoolAdmin)
            ->postJson("/school/students/{$student->id}/portal-access/reveal-password");

        $revealResponse->assertStatus(422);
        $revealResponse->assertJsonFragment([
            'error' => 'No active temporary password found or it has already expired or been changed by the user.',
        ]);
    }

    /**
     * Scenario C: Student with active temporary credential can be revealed by authorized user.
     */
    public function test_student_with_active_temp_password_is_safely_represented_and_revealable(): void
    {
        $plainPass = 'TempPass123!';
        $user = User::create([
            'name'                          => 'Student User',
            'username'                      => 'STD-ACTIVE-01',
            'email'                         => 'student-active@test.local',
            'password'                      => bcrypt($plainPass),
            'school_id'                     => $this->school->id,
            'must_change_password'          => true,
            'temporary_password_encrypted'  => \Illuminate\Support\Facades\Crypt::encryptString($plainPass),
            'temporary_password_expires_at' => Carbon::now()->addDays(14),
            'is_active'                     => true,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']));

        $student = $this->createSyntheticStudent(['user_id' => $user->id]);

        $response = $this->actingAs($this->schoolAdmin)
            ->get(route('school.students.show', $student->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Show')
            ->where('portalUser.has_active_temp_pass', true)
            ->where('portalUser.must_change_password', true)
            // Crucial: GET request MUST NOT expose plain temp password in Inertia props
            ->missing('portalUser.temp_password')
        );

        // Reveal endpoint returns temporary password
        $revealResponse = $this->actingAs($this->schoolAdmin)
            ->postJson("/school/students/{$student->id}/portal-access/reveal-password");

        $revealResponse->assertOk();
        $revealResponse->assertJson([
            'username'      => 'STD-ACTIVE-01',
            'temp_password' => $plainPass,
        ]);
    }

    /**
     * Scenario D: Student whose temporary credential has expired.
     */
    public function test_student_with_expired_temporary_credential(): void
    {
        $user = User::create([
            'name'                          => 'Student User Expired',
            'username'                      => 'STD-EXPIRED-01',
            'email'                         => 'student-expired@test.local',
            'password'                      => bcrypt('OldPassword123!'),
            'school_id'                     => $this->school->id,
            'must_change_password'          => true,
            'temporary_password_encrypted'  => \Illuminate\Support\Facades\Crypt::encryptString('OldPassword123!'),
            'temporary_password_expires_at' => Carbon::now()->subDay(), // Expired yesterday
            'is_active'                     => true,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']));

        $student = $this->createSyntheticStudent(['user_id' => $user->id]);

        $response = $this->actingAs($this->schoolAdmin)
            ->get(route('school.students.show', $student->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Show')
            ->where('portalUser.has_active_temp_pass', false)
        );

        // Reveal endpoint rejects expired credentials
        $revealResponse = $this->actingAs($this->schoolAdmin)
            ->postJson("/school/students/{$student->id}/portal-access/reveal-password");

        $revealResponse->assertStatus(422);
    }

    /**
     * Scenario E: Guardian exists but guardian.user_id = NULL (legacy parent without portal account).
     */
    public function test_guardian_exists_without_portal_user_loads_profile_safely(): void
    {
        $guardian = Guardian::create([
            'school_id'     => $this->school->id,
            'guardian_code' => 'G-LEGACY-01',
            'name'          => 'Mr. Legacy Guardian',
            'relation'      => 'Father',
            'phone'         => '03001234567',
            'user_id'       => null,
        ]);

        $student = $this->createSyntheticStudent([
            'user_id'     => null,
            'guardian_id' => $guardian->id,
        ]);

        $response = $this->actingAs($this->schoolAdmin)
            ->get(route('school.students.show', $student->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Show')
            ->where('student.guardian_id', $guardian->id)
            ->where('portalUser', null)
            ->where('guardianPortalUser', null)
        );
    }

    /**
     * Scenario F: Both student and guardian have active portal accounts.
     */
    public function test_both_student_and_guardian_have_portal_accounts(): void
    {
        $studentUser = User::create([
            'name'      => 'Child Student',
            'username'  => 'CHILD-001',
            'email'     => 'child@test.local',
            'password'  => bcrypt('pass'),
            'school_id' => $this->school->id,
        ]);
        $studentUser->assignRole(Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']));

        $guardianUser = User::create([
            'name'      => 'Parent User',
            'username'  => 'PARENT-001',
            'email'     => 'parent@test.local',
            'password'  => bcrypt('pass'),
            'school_id' => $this->school->id,
        ]);
        $guardianUser->assignRole(Role::firstOrCreate(['name' => 'parent', 'guard_name' => 'web']));

        $guardian = Guardian::create([
            'school_id'     => $this->school->id,
            'guardian_code' => 'G-001',
            'name'          => 'Parent User',
            'relation'      => 'Mother',
            'user_id'       => $guardianUser->id,
        ]);

        $student = $this->createSyntheticStudent([
            'user_id'     => $studentUser->id,
            'guardian_id' => $guardian->id,
        ]);

        $response = $this->actingAs($this->schoolAdmin)
            ->get(route('school.students.show', $student->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SchoolAdmin/Students/Show')
            ->where('portalUser.id', $studentUser->id)
            ->where('portalUser.username', 'CHILD-001')
            ->where('guardianPortalUser.id', $guardianUser->id)
            ->where('guardianPortalUser.username', 'PARENT-001')
        );
    }

    /**
     * Scenario G: Authorization Tests across Super Admin, School Admin, Unauthorized Teacher, and Custom Roles.
     */
    public function test_authorization_matrix_for_portal_credentials(): void
    {
        $plainPass = 'TopSecret123!';
        $studentUser = User::create([
            'name'                          => 'Auth Student',
            'username'                      => 'AUTH-STD-01',
            'password'                      => bcrypt($plainPass),
            'school_id'                     => $this->school->id,
            'must_change_password'          => true,
            'temporary_password_encrypted'  => \Illuminate\Support\Facades\Crypt::encryptString($plainPass),
            'temporary_password_expires_at' => Carbon::now()->addDays(7),
        ]);
        $studentUser->assignRole(Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']));

        $student = $this->createSyntheticStudent(['user_id' => $studentUser->id]);

        // 1. Super Admin (allowed via Gate::before)
        session(['active_school_id' => $this->school->id]);
        $superAdminResp = $this->actingAs($this->superAdmin)
            ->postJson("/school/students/{$student->id}/portal-access/reveal-password");
        $superAdminResp->assertOk();
        $superAdminResp->assertJsonFragment(['temp_password' => $plainPass]);

        // 2. School Admin (allowed with permission)
        $schoolAdminResp = $this->actingAs($this->schoolAdmin)
            ->postJson("/school/students/{$student->id}/portal-access/reveal-password");
        $schoolAdminResp->assertOk();

        // 3. Unauthorized Teacher without permission (forbidden HTTP 403)
        $teacherResp = $this->actingAs($this->teacher)
            ->postJson("/school/students/{$student->id}/portal-access/reveal-password");
        $teacherResp->assertStatus(403);

        // Teacher viewing profile receives canViewCredentials = false and NO password data
        $teacherShowResp = $this->actingAs($this->teacher)
            ->get(route('school.students.show', $student->id));
        $teacherShowResp->assertOk();
        $teacherShowResp->assertInertia(fn (Assert $page) => $page
            ->where('canViewCredentials', false)
            ->where('canResetCredentials', false)
            ->missing('portalUser.temp_password')
        );

        // 4. Staff user granted explicit students.portal_credentials.view permission (allowed HTTP 200)
        $this->teacher->givePermissionTo('students.portal_credentials.view');
        $this->teacher->refresh();

        $teacherPassResp = $this->actingAs($this->teacher)
            ->postJson("/school/students/{$student->id}/portal-access/reveal-password");
        $teacherPassResp->assertOk();
        $teacherPassResp->assertJsonFragment(['temp_password' => $plainPass]);
    }

    /**
     * Scenario H: Viewing profile does NOT auto-create a user or auto-reset credentials.
     */
    public function test_viewing_legacy_student_profile_does_not_mutate_or_create_user(): void
    {
        $student = $this->createSyntheticStudent(['user_id' => null]);
        $initialUserCount = User::count();

        $response = $this->actingAs($this->schoolAdmin)
            ->get(route('school.students.show', $student->id));

        $response->assertOk();
        $this->assertEquals($initialUserCount, User::count(), 'Zero users must be created on GET student profile.');
        $this->assertNull($student->fresh()->user_id, 'Student user_id must remain NULL.');
    }
}
