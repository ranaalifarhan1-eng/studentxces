<?php

namespace Tests\Feature;

use App\Jobs\SendPortalCredentialsEmailJob;
use App\Mail\ParentPortalCredentialsMail;
use App\Mail\StudentPortalCredentialsMail;
use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PortalAuthenticationAndCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $superAdmin;
    protected User $schoolAdmin;
    protected User $operatorWithoutPerm;
    protected SchoolClass $class;
    protected Section $section;
    protected AcademicYear $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Roles & Permissions setup
        $pStudentsView  = Permission::firstOrCreate(['name' => 'students.view', 'guard_name' => 'web']);
        $pStudentsCreate = Permission::firstOrCreate(['name' => 'students.create', 'guard_name' => 'web']);
        $pStudentsEdit   = Permission::firstOrCreate(['name' => 'students.edit', 'guard_name' => 'web']);
        $pCredsView     = Permission::firstOrCreate(['name' => 'students.portal_credentials.view', 'guard_name' => 'web']);
        $pCredsReset    = Permission::firstOrCreate(['name' => 'students.portal_credentials.reset', 'guard_name' => 'web']);

        $rSuperAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $rSchoolAdmin = Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
        $rSchoolAdmin->syncPermissions([$pStudentsView, $pStudentsCreate, $pStudentsEdit, $pCredsView, $pCredsReset]);

        $rStaff = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'web']);
        $rStaff->syncPermissions([$pStudentsView]);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'parent', 'guard_name' => 'web']);

        // 2. Setup School
        $this->school = School::create([
            'name'      => 'Lahore Cambridge School',
            'slug'      => 'lcs-' . uniqid(),
            'email'     => 'admin@lcs.edu.pk',
            'status'    => 'active',
            'is_active' => true,
            'timezone'  => 'Asia/Karachi',
            'settings'  => ['school_code' => 'LCS'],
        ]);

        // Subscription & Module Entitlement
        $pkg = Package::create([
            'name'          => 'Enterprise',
            'slug'          => 'enterprise-' . uniqid(),
            'price_monthly' => 10000,
            'price_yearly'  => 100000,
            'status'        => 'active',
        ]);
        PackageModule::create([
            'package_id'  => $pkg->id,
            'module_slug' => 'students',
        ]);
        SchoolSubscription::create([
            'school_id'  => $this->school->id,
            'package_id' => $pkg->id,
            'status'     => 'active',
            'start_date' => Carbon::now()->subMonth()->toDateString(),
            'end_date'   => Carbon::now()->addYear()->toDateString(),
        ]);

        // 3. Setup Academic Year & Class
        $this->academicYear = AcademicYear::create([
            'school_id'  => $this->school->id,
            'name'       => 'Academic Year 2026-27',
            'start_date' => '2026-08-01',
            'end_date'   => '2027-06-30',
            'is_active'  => true,
        ]);

        $this->class = SchoolClass::create([
            'school_id'    => $this->school->id,
            'name'         => 'Grade 6',
            'numeric_name' => 6,
        ]);

        $this->section = Section::create([
            'school_id' => $this->school->id,
            'class_id'  => $this->class->id,
            'name'      => 'Section A',
        ]);

        // 4. Setup Users
        $this->superAdmin = User::create([
            'name'     => 'Super Admin',
            'email'    => 'superadmin@system.local',
            'username' => 'SYS-SUPER',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        $this->superAdmin->assignRole('super-admin');

        $this->schoolAdmin = User::create([
            'school_id' => $this->school->id,
            'name'      => 'LCS Principal',
            'email'     => 'principal@lcs.edu.pk',
            'username'  => 'LCS-PRINCIPAL',
            'password'  => Hash::make('password'),
            'status'    => 'active',
        ]);
        $this->schoolAdmin->assignRole('school-admin');

        $this->operatorWithoutPerm = User::create([
            'school_id' => $this->school->id,
            'name'      => 'LCS Teacher',
            'email'     => 'teacher@lcs.edu.pk',
            'username'  => 'LCS-TEACHER',
            'password'  => Hash::make('password'),
            'status'    => 'active',
        ]);
        $this->operatorWithoutPerm->assignRole('teacher');
    }

    public function test_login_supports_both_email_and_username_interchangeably(): void
    {
        $user = User::create([
            'school_id' => $this->school->id,
            'name'      => 'Ahmed Ali',
            'email'     => 'ahmed@student.local',
            'username'  => 'LCS-ADM-2026-0001',
            'password'  => Hash::make('SecretPass123'),
            'status'    => 'active',
        ]);
        $user->assignRole('student');

        // 1. Login with email
        $resEmail = $this->post('/login', [
            'email'    => 'ahmed@student.local',
            'password' => 'SecretPass123',
        ]);
        $this->assertAuthenticatedAs($user);
        $resEmail->assertRedirect(route('dashboard'));

        $this->post('/logout');
        $this->assertGuest();

        // 2. Login with username (exact)
        $resUser = $this->post('/login', [
            'email'    => 'LCS-ADM-2026-0001',
            'password' => 'SecretPass123',
        ]);
        $this->assertAuthenticatedAs($user);
        $resUser->assertRedirect(route('dashboard'));

        $this->post('/logout');
        $this->assertGuest();

        // 3. Login with username (case-insensitive lowercase)
        $resUserLower = $this->post('/login', [
            'email'    => 'lcs-adm-2026-0001',
            'password' => 'SecretPass123',
        ]);
        $this->assertAuthenticatedAs($user);
        $resUserLower->assertRedirect(route('dashboard'));
    }

    public function test_user_without_email_can_authenticate_using_username(): void
    {
        $parentUser = User::create([
            'school_id' => $this->school->id,
            'name'      => 'Muhammad Tariq',
            'email'     => null,
            'username'  => 'LCS-PAR-00001',
            'password'  => Hash::make('ParentPass123'),
            'status'    => 'active',
        ]);
        $parentUser->assignRole('parent');

        $response = $this->post('/login', [
            'email'    => 'LCS-PAR-00001',
            'password' => 'ParentPass123',
        ]);

        $this->assertAuthenticatedAs($parentUser);
        $response->assertRedirect(route('dashboard'));
    }

    public function test_student_admission_automatically_provisions_student_and_guardian_portal_accounts(): void
    {
        Mail::fake();

        $this->actingAs($this->schoolAdmin);

        $payload = [
            'first_name'     => 'Zain',
            'last_name'      => 'Khan',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'admission_date' => '2026-09-01',
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'email'          => 'zain@student.local',
            'guardian'       => [
                'name'       => 'Tariq Khan',
                'relation'   => 'Father',
                'phone'      => '+923001234567',
                'email'      => 'tariq.khan@email.local',
                'occupation' => 'Business',
            ],
        ];

        $response = $this->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Zain')->first();
        $this->assertNotNull($student);

        // Verify Student Portal Account
        $studentUser = $student->user;
        $this->assertNotNull($studentUser);
        $this->assertEquals($student->school_id, $studentUser->school_id);
        $this->assertEquals('zain@student.local', $studentUser->email);
        $this->assertStringStartsWith('LCS-', $studentUser->username);
        $this->assertTrue((bool) $studentUser->must_change_password);
        $this->assertTrue($studentUser->hasRole('student'));
        $this->assertNotNull($studentUser->temporary_password_encrypted);
        $this->assertNotNull($studentUser->temporary_password_expires_at);

        // Verify Student Temp Password matches decrypted
        $plainPass = Crypt::decryptString($studentUser->temporary_password_encrypted);
        $this->assertTrue(Hash::check($plainPass, $studentUser->password));

        // Verify Guardian Portal Account
        $guardian = $student->guardian;
        $this->assertNotNull($guardian);
        $this->assertNotNull($guardian->guardian_code);
        $this->assertStringStartsWith('PAR-', $guardian->guardian_code);

        $guardianUser = $guardian->user;
        $this->assertNotNull($guardianUser);
        $this->assertEquals($student->school_id, $guardianUser->school_id);
        $this->assertEquals('tariq.khan@email.local', $guardianUser->email);
        $this->assertStringStartsWith('LCS-PAR-', $guardianUser->username);
        $this->assertTrue((bool) $guardianUser->must_change_password);
        $this->assertTrue($guardianUser->hasRole('parent'));

        $guardianPlainPass = Crypt::decryptString($guardianUser->temporary_password_encrypted);
        $this->assertTrue(Hash::check($guardianPlainPass, $guardianUser->password));

        // Verify Credential Mails Sent via Worker/Job
        Mail::assertSent(StudentPortalCredentialsMail::class, function ($mail) {
            return $mail->hasTo('zain@student.local');
        });
        Mail::assertSent(ParentPortalCredentialsMail::class, function ($mail) {
            return $mail->hasTo('tariq.khan@email.local');
        });

        // Flash message contains portal_account_created without secrets
        $response->assertSessionHas('portal_account_created');
        $response->assertSessionMissing('admission_credentials');
    }

    public function test_admission_without_emails_generates_accounts_without_dispatching_mail(): void
    {
        Mail::fake();

        $this->actingAs($this->schoolAdmin);

        $payload = [
            'first_name'     => 'Bilal',
            'last_name'      => 'Aslam',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'admission_date' => '2026-09-01',
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'email'          => null,
            'guardian'       => [
                'name'       => 'Aslam Javed',
                'relation'   => 'Father',
                'phone'      => '+923009876543',
                'email'      => null,
                'occupation' => 'Farmer',
            ],
        ];

        $response = $this->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Bilal')->first();
        $this->assertNotNull($student);

        // Accounts created with null email
        $this->assertNull($student->user->email);
        $this->assertNotNull($student->user->username);
        $this->assertNull($student->guardian->user->email);
        $this->assertNotNull($student->guardian->user->username);

        // No emails sent because neither has an email address
        Mail::assertNothingSent();
    }

    public function test_existing_guardian_linking_reuses_parent_account_without_resetting_credentials(): void
    {
        // 1. Create initial guardian with permanent password already set
        $guardian = Guardian::create([
            'school_id'     => $this->school->id,
            'name'          => 'Rashid Mehmood',
            'relation'      => 'Father',
            'phone'         => '+923111222333',
            'guardian_code' => 'PAR-00042',
        ]);
        $parentUser = User::create([
            'school_id'                    => $this->school->id,
            'name'                         => 'Rashid Mehmood',
            'username'                     => 'LCS-PAR-00042',
            'email'                        => 'rashid@family.local',
            'password'                     => Hash::make('PermanentParentPass123'),
            'must_change_password'         => false,
            'temporary_password_encrypted' => null,
            'temporary_password_expires_at'=> null,
            'status'                       => 'active',
        ]);
        $parentUser->assignRole('parent');
        $guardian->user_id = $parentUser->id;
        $guardian->save();

        $this->actingAs($this->schoolAdmin);

        // 2. Admit second child linking to existing guardian
        $payload = [
            'first_name'     => 'Sara',
            'last_name'      => 'Rashid',
            'gender'         => 'female',
            'category'       => 'general',
            'status'         => 'active',
            'admission_date' => '2026-09-01',
            'class_id'       => $this->class->id,
            'guardian_id'    => $guardian->id,
            'guardian'       => [
                'name'       => 'Rashid Mehmood',
                'relation'   => 'Father',
                'phone'      => '+923111222333',
            ],
        ];

        $response = $this->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $sara = Student::where('first_name', 'Sara')->first();
        $this->assertEquals($guardian->id, $sara->guardian_id);

        // Parent user credentials must be untouched!
        $parentUser->refresh();
        $this->assertFalse((bool) $parentUser->must_change_password);
        $this->assertNull($parentUser->temporary_password_encrypted);
        $this->assertTrue(Hash::check('PermanentParentPass123', $parentUser->password));

        // Flash message marks guardian as existing without plaintext secrets
        $portalCreated = session('portal_account_created');
        $this->assertTrue($portalCreated['guardian']['is_existing']);
        $this->assertNull(session('admission_credentials'));
    }

    public function test_reveal_temporary_password_authorization_and_audit(): void
    {
        $studentUser = User::create([
            'school_id'                    => $this->school->id,
            'name'                         => 'Usman Ghani',
            'username'                     => 'LCS-ADM-2026-0088',
            'password'                     => Hash::make('TempPassXYZ99'),
            'temporary_password_encrypted' => Crypt::encryptString('TempPassXYZ99'),
            'temporary_password_expires_at'=> Carbon::now()->addDays(14),
            'must_change_password'         => true,
            'status'                       => 'active',
        ]);
        $studentUser->assignRole('student');

        $student = Student::create([
            'school_id'    => $this->school->id,
            'user_id'      => $studentUser->id,
            'admission_no' => 'ADM-2026-0088',
            'first_name'   => 'Usman',
            'last_name'    => 'Ghani',
            'gender'       => 'male',
            'class_id'     => $this->class->id,
            'status'       => 'active',
        ]);

        // 1. Unauthorized operator gets 403
        $this->actingAs($this->operatorWithoutPerm);
        $forbiddenRes = $this->postJson("/school/students/{$student->id}/portal-access/reveal-password");
        $forbiddenRes->assertStatus(403);

        // 2. Authorized admin gets plain password
        $this->actingAs($this->schoolAdmin);
        $successRes = $this->postJson("/school/students/{$student->id}/portal-access/reveal-password");
        $successRes->assertStatus(200);
        $successRes->assertJson([
            'username'      => 'LCS-ADM-2026-0088',
            'temp_password' => 'TempPassXYZ99',
        ]);

        // Verify audit log exists without containing plaintext password
        $latestLog = Activity::where('description', 'Portal credentials revealed')->latest('id')->first();
        $this->assertNotNull($latestLog);
        $this->assertEquals($this->schoolAdmin->id, $latestLog->causer_id);
        $this->assertStringNotContainsString('TempPassXYZ99', json_encode($latestLog->properties));
    }

    public function test_first_login_enforces_mandatory_password_change_flow(): void
    {
        $studentUser = User::create([
            'school_id'                    => $this->school->id,
            'name'                         => 'Fatima Noor',
            'username'                     => 'LCS-ADM-2026-0050',
            'password'                     => Hash::make('InitialTempKey12'),
            'temporary_password_encrypted' => Crypt::encryptString('InitialTempKey12'),
            'temporary_password_expires_at'=> Carbon::now()->addDays(14),
            'must_change_password'         => true,
            'status'                       => 'active',
        ]);
        $studentUser->assignRole('student');

        // 1. Authenticate user
        $this->actingAs($studentUser);

        // Attempting to visit student dashboard must redirect to /password/first-change
        $dashboardRes = $this->get(route('student.dashboard'));
        $dashboardRes->assertRedirect('/password/first-change');

        // Can access the first-change page
        $pageRes = $this->get('/password/first-change');
        $pageRes->assertStatus(200);

        // 2. Submit new password
        $updateRes = $this->post('/password/first-change', [
            'password'              => 'MyNewSecurePassword999!',
            'password_confirmation' => 'MyNewSecurePassword999!',
        ]);
        $updateRes->assertRedirect(route('student.dashboard'));

        // 3. User records are updated and temporary encrypted password is wiped
        $studentUser->refresh();
        $this->assertFalse((bool) $studentUser->must_change_password);
        $this->assertNull($studentUser->temporary_password_encrypted);
        $this->assertNull($studentUser->temporary_password_expires_at);
        $this->assertTrue(Hash::check('MyNewSecurePassword999!', $studentUser->password));

        // Now dashboard is accessible without redirection
        $secondDashboardRes = $this->get(route('student.dashboard'));
        $secondDashboardRes->assertStatus(200);
    }

    public function test_reset_portal_password_generates_new_temporary_password_and_sets_flag(): void
    {
        $studentUser = User::create([
            'school_id'                    => $this->school->id,
            'name'                         => 'Hamza Ali',
            'username'                     => 'LCS-ADM-2026-0077',
            'password'                     => Hash::make('OldPermanentPassword'),
            'temporary_password_encrypted' => null,
            'must_change_password'         => false,
            'status'                       => 'active',
        ]);
        $studentUser->assignRole('student');

        $student = Student::create([
            'school_id'    => $this->school->id,
            'user_id'      => $studentUser->id,
            'admission_no' => 'ADM-2026-0077',
            'first_name'   => 'Hamza',
            'last_name'    => 'Ali',
            'gender'       => 'male',
            'class_id'     => $this->class->id,
            'status'       => 'active',
        ]);

        $this->actingAs($this->schoolAdmin);

        $res = $this->post("/school/students/{$student->id}/portal-access/reset-password");
        $res->assertSessionHasNoErrors();
        $res->assertSessionHas('success');
        $res->assertSessionMissing('portal_credentials');

        $studentUser->refresh();
        $this->assertTrue((bool) $studentUser->must_change_password);
        $this->assertNotNull($studentUser->temporary_password_encrypted);
        $this->assertNotNull($studentUser->temporary_password_expires_at);

        $newPlain = Crypt::decryptString($studentUser->temporary_password_encrypted);
        $this->assertTrue(Hash::check($newPlain, $studentUser->password));
    }

    public function test_guardian_search_endpoint_returns_matching_results_with_counts(): void
    {
        $guardian = Guardian::create([
            'school_id'     => $this->school->id,
            'name'          => 'Dr. Salman Farooq',
            'relation'      => 'Father',
            'phone'         => '+923214445555',
            'guardian_code' => 'PAR-00077',
        ]);

        Student::create([
            'school_id'    => $this->school->id,
            'guardian_id'  => $guardian->id,
            'admission_no' => 'ADM-2026-0010',
            'first_name'   => 'Ayla',
            'last_name'    => 'Salman',
            'gender'       => 'female',
            'class_id'     => $this->class->id,
            'status'       => 'active',
        ]);

        $this->actingAs($this->schoolAdmin);

        // Search by name
        $res = $this->getJson('/school/students/guardians/search?q=Salman');
        $res->assertStatus(200);
        $res->assertJsonFragment([
            'name'          => 'Dr. Salman Farooq',
            'guardian_code' => 'PAR-00077',
            'students_count'=> 1,
        ]);

        // Search by code
        $resCode = $this->getJson('/school/students/guardians/search?q=PAR-00077');
        $resCode->assertStatus(200);
        $resCode->assertJsonFragment([
            'guardian_code' => 'PAR-00077',
        ]);
    }

    public function test_guardian_code_is_tenant_scoped_and_allows_same_code_across_different_schools(): void
    {
        $schoolB = School::create([
            'name'      => 'Beaconhouse Model',
            'slug'      => 'bm-' . uniqid(),
            'email'     => 'admin@bm.edu.pk',
            'status'    => 'active',
            'is_active' => true,
            'timezone'  => 'Asia/Karachi',
            'settings'  => ['school_code' => 'BM'],
        ]);

        // Guardian in School A with PAR-00001
        $guardianA = Guardian::create([
            'school_id'     => $this->school->id,
            'name'          => 'Ali Raza (School A)',
            'relation'      => 'Father',
            'guardian_code' => 'PAR-00001',
        ]);
        $this->assertEquals('PAR-00001', $guardianA->guardian_code);

        // Guardian in School B with same code PAR-00001 must be ALLOWED
        $guardianB = Guardian::create([
            'school_id'     => $schoolB->id,
            'name'          => 'Kamran Akmal (School B)',
            'relation'      => 'Father',
            'guardian_code' => 'PAR-00001',
        ]);
        $this->assertEquals('PAR-00001', $guardianB->guardian_code);

        // Same school duplicate PAR-00001 must be REJECTED by composite unique index
        $this->expectException(\Illuminate\Database\QueryException::class);
        Guardian::create([
            'school_id'     => $this->school->id,
            'name'          => 'Duplicate In School A',
            'relation'      => 'Father',
            'guardian_code' => 'PAR-00001',
        ]);
    }

    public function test_generated_usernames_remain_globally_unique_even_if_admission_numbers_match_across_schools(): void
    {
        $schoolB = School::create([
            'name'      => 'Lahore Grammar School',
            'slug'      => 'lgs-' . uniqid(),
            'email'     => 'admin@lgs.edu.pk',
            'status'    => 'active',
            'is_active' => true,
            'timezone'  => 'Asia/Karachi',
            'settings'  => ['school_code' => 'LGS'],
        ]);

        $studentA = Student::create([
            'school_id'    => $this->school->id,
            'admission_no' => 'ADM-2026-9999',
            'first_name'   => 'Haris',
            'last_name'    => 'Rauf',
            'gender'       => 'male',
            'class_id'     => $this->class->id,
            'status'       => 'active',
        ]);

        $classB = SchoolClass::create([
            'school_id'    => $schoolB->id,
            'name'         => 'Grade 6',
            'numeric_name' => 6,
        ]);

        $studentB = Student::create([
            'school_id'    => $schoolB->id,
            'class_id'     => $classB->id,
            'admission_no' => 'ADM-2026-9999', // Identical admission number
            'first_name'   => 'Haris',
            'last_name'    => 'Sohail',
            'gender'       => 'male',
            'status'       => 'active',
        ]);

        $userA = PortalCredentialService::provisionStudentPortalAccount($studentA, $this->school);
        $userB = PortalCredentialService::provisionStudentPortalAccount($studentB, $schoolB);

        $this->assertEquals('LCS-ADM-2026-9999', $userA['username']);
        $this->assertEquals('LGS-ADM-2026-9999', $userB['username']);
        $this->assertNotEquals($userA['username'], $userB['username']);

        // Both authenticate to distinct accounts without collision
        $this->post('/login', [
            'email'    => $userA['username'],
            'password' => $userA['temp_password'],
        ]);
        $this->assertEquals($studentA->user_id, \Illuminate\Support\Facades\Auth::id());
        $this->post('/logout');

        $this->post('/login', [
            'email'    => $userB['username'],
            'password' => $userB['temp_password'],
        ]);
        $this->assertEquals($studentB->user_id, \Illuminate\Support\Facades\Auth::id());
    }

    public function test_permission_enforcement_across_roles_and_cross_tenant_fail_closed(): void
    {
        $rAccountant = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $rReceptionist = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

        $accountant = User::create([
            'school_id' => $this->school->id,
            'name'      => 'Accountant User',
            'email'     => 'acc@lcs.edu.pk',
            'username'  => 'LCS-ACC',
            'password'  => Hash::make('password'),
            'status'    => 'active',
        ]);
        $accountant->assignRole($rAccountant);

        $receptionist = User::create([
            'school_id' => $this->school->id,
            'name'      => 'Receptionist User',
            'email'     => 'rec@lcs.edu.pk',
            'username'  => 'LCS-REC',
            'password'  => Hash::make('password'),
            'status'    => 'active',
        ]);
        $receptionist->assignRole($rReceptionist);

        $studentUser = User::create([
            'school_id'                    => $this->school->id,
            'name'                         => 'Danish Aziz',
            'username'                     => 'LCS-ADM-2026-0333',
            'password'                     => Hash::make('TempPass123!'),
            'temporary_password_encrypted' => Crypt::encryptString('TempPass123!'),
            'temporary_password_expires_at'=> Carbon::now()->addDays(14),
            'must_change_password'         => true,
            'status'                       => 'active',
        ]);
        $student = Student::create([
            'school_id'    => $this->school->id,
            'user_id'      => $studentUser->id,
            'admission_no' => 'ADM-2026-0333',
            'first_name'   => 'Danish',
            'last_name'    => 'Aziz',
            'gender'       => 'male',
            'class_id'     => $this->class->id,
            'status'       => 'active',
        ]);

        // 1. Teacher is denied (403)
        $this->actingAs($this->operatorWithoutPerm);
        $this->postJson("/school/students/{$student->id}/portal-access/reveal-password")->assertStatus(403);
        $this->post("/school/students/{$student->id}/portal-access/reset-password")->assertStatus(403);

        // 2. Accountant is denied (403)
        $this->actingAs($accountant);
        $this->postJson("/school/students/{$student->id}/portal-access/reveal-password")->assertStatus(403);
        $this->post("/school/students/{$student->id}/portal-access/reset-password")->assertStatus(403);

        // 3. Receptionist is denied (403)
        $this->actingAs($receptionist);
        $this->postJson("/school/students/{$student->id}/portal-access/reveal-password")->assertStatus(403);
        $this->post("/school/students/{$student->id}/portal-access/reset-password")->assertStatus(403);

        // 4. Super Admin is allowed
        $this->actingAs($this->superAdmin);
        session(['active_school_id' => $this->school->id]);
        $this->postJson("/school/students/{$student->id}/portal-access/reveal-password")->assertStatus(200);

        // 5. School Admin of another school is denied (Cross-tenant fail-closed 403)
        $schoolB = School::create([
            'name'      => 'Other School',
            'slug'      => 'other-' . uniqid(),
            'email'     => 'admin@other.edu.pk',
            'status'    => 'active',
            'is_active' => true,
            'timezone'  => 'Asia/Karachi',
        ]);
        $otherAdmin = User::create([
            'school_id' => $schoolB->id,
            'name'      => 'Other Admin',
            'email'     => 'admin@other.local',
            'username'  => 'OTH-ADMIN',
            'password'  => Hash::make('password'),
            'status'    => 'active',
        ]);
        $otherAdmin->assignRole('school-admin');

        $this->actingAs($otherAdmin);
        $crossReveal = $this->postJson("/school/students/{$student->id}/portal-access/reveal-password");
        $this->assertTrue(in_array($crossReveal->status(), [403, 404], true));

        $crossReset = $this->post("/school/students/{$student->id}/portal-access/reset-password");
        $this->assertTrue(in_array($crossReset->status(), [403, 404], true));
    }

    public function test_expired_temporary_password_cannot_login_or_be_revealed_or_be_resent(): void
    {
        $studentUser = User::create([
            'school_id'                    => $this->school->id,
            'name'                         => 'Naveed Ashraf',
            'username'                     => 'LCS-ADM-2026-0555',
            'email'                        => 'naveed@test.local',
            'password'                     => Hash::make('ExpiredPass999'),
            'temporary_password_encrypted' => Crypt::encryptString('ExpiredPass999'),
            'temporary_password_expires_at'=> Carbon::now()->subMinute(), // Expired
            'must_change_password'         => true,
            'status'                       => 'active',
        ]);
        $studentUser->assignRole('student');

        $student = Student::create([
            'school_id'    => $this->school->id,
            'user_id'      => $studentUser->id,
            'admission_no' => 'ADM-2026-0555',
            'first_name'   => 'Naveed',
            'last_name'    => 'Ashraf',
            'gender'       => 'male',
            'class_id'     => $this->class->id,
            'status'       => 'active',
        ]);

        // 1. Attempt login with expired temporary password -> rejected with validation error
        $loginRes = $this->post('/login', [
            'email'    => 'LCS-ADM-2026-0555',
            'password' => 'ExpiredPass999',
        ]);
        $this->assertGuest();
        $loginRes->assertSessionHasErrors('email');

        // 2. Admin cannot reveal expired temporary password
        $this->actingAs($this->schoolAdmin);
        $revealRes = $this->postJson("/school/students/{$student->id}/portal-access/reveal-password");
        $revealRes->assertStatus(422);
        $revealRes->assertJsonFragment([
            'error' => 'No active temporary password found or it has already expired or been changed by the user.',
        ]);

        // 3. Admin cannot resend expired temporary credentials
        $resendRes = $this->post("/school/students/{$student->id}/portal-access/resend-credentials", ['type' => 'student']);
        $resendRes->assertSessionHas('error');
    }

    public function test_mail_failure_during_admission_does_not_rollback_admission_or_portal_accounts(): void
    {
        Mail::shouldReceive('to')
            ->andThrow(new \RuntimeException('SMTP host connection timeout'));

        $this->actingAs($this->schoolAdmin);

        $payload = [
            'first_name'     => 'Shahid',
            'last_name'      => 'Afridi',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'admission_date' => '2026-09-01',
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'email'          => 'shahid.afridi@cricket.local',
            'guardian'       => [
                'name'       => 'Fazal Afridi',
                'relation'   => 'Father',
                'phone'      => '+923331112233',
                'email'      => 'fazal.afridi@cricket.local',
                'occupation' => 'Business',
            ],
        ];

        $response = $this->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Shahid')->first();
        $this->assertNotNull($student);
        $this->assertNotNull($student->user);
        $this->assertEquals('shahid.afridi@cricket.local', $student->user->email);
        $this->assertNotNull($student->guardian);
        $this->assertNotNull($student->guardian->user);

        $revealRes = $this->postJson("/school/students/{$student->id}/portal-access/reveal-password");
        $revealRes->assertStatus(200);
        $this->assertNotEmpty($revealRes->json('temp_password'));
    }

    public function test_existing_guardian_second_child_admission_leaves_parent_password_untouched_and_queues_no_parent_email_and_parent_sees_both_children(): void
    {
        Mail::fake();

        $guardian = Guardian::create([
            'school_id'     => $this->school->id,
            'name'          => 'Younis Khan',
            'relation'      => 'Father',
            'phone'         => '+923225556677',
            'email'         => 'younis.khan@pcb.local',
            'guardian_code' => 'PAR-00099',
        ]);

        $parentUser = User::create([
            'school_id'                    => $this->school->id,
            'name'                         => 'Younis Khan',
            'username'                     => 'LCS-PAR-00099',
            'email'                        => 'younis.khan@pcb.local',
            'password'                     => Hash::make('PermanentPassCustom2026!'),
            'must_change_password'         => false,
            'temporary_password_encrypted' => null,
            'status'                       => 'active',
        ]);
        $parentUser->assignRole('parent');
        $guardian->update(['user_id' => $parentUser->id]);

        $child1 = Student::create([
            'school_id'    => $this->school->id,
            'guardian_id'  => $guardian->id,
            'admission_no' => 'ADM-2026-1001',
            'first_name'   => 'Ali',
            'last_name'    => 'Khan',
            'gender'       => 'male',
            'class_id'     => $this->class->id,
            'status'       => 'active',
        ]);

        $this->actingAs($this->schoolAdmin);

        $payload = [
            'first_name'     => 'Babar',
            'last_name'      => 'Khan',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'admission_date' => '2026-09-01',
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'email'          => 'babar@student.local',
            'guardian_id'    => $guardian->id,
            'guardian'       => [
                'name'       => 'Younis Khan',
                'relation'   => 'Father',
                'phone'      => '+923225556677',
            ],
        ];

        $response = $this->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();

        Mail::assertSent(StudentPortalCredentialsMail::class, function ($mail) {
            return $mail->hasTo('babar@student.local');
        });

        Mail::assertNotSent(ParentPortalCredentialsMail::class);

        $parentUser->refresh();
        $this->assertTrue(Hash::check('PermanentPassCustom2026!', $parentUser->password));
        $this->assertFalse((bool) $parentUser->must_change_password);
        $this->assertNull($parentUser->temporary_password_encrypted);

        $guardian->refresh();
        $this->assertEquals(2, $guardian->students()->count());
        $siblingNames = $guardian->students->pluck('first_name')->all();
        $this->assertContains('Ali', $siblingNames);
        $this->assertContains('Babar', $siblingNames);
    }

    public function test_after_first_login_password_change_old_temporary_password_is_invalidated_and_audit_has_no_password(): void
    {
        $studentUser = User::create([
            'school_id'                    => $this->school->id,
            'name'                         => 'Imran Nazir',
            'username'                     => 'LCS-ADM-2026-0777',
            'password'                     => Hash::make('OldTempSecret123'),
            'temporary_password_encrypted' => Crypt::encryptString('OldTempSecret123'),
            'temporary_password_expires_at'=> Carbon::now()->addDays(14),
            'must_change_password'         => true,
            'status'                       => 'active',
        ]);
        $studentUser->assignRole('student');

        $this->actingAs($studentUser);
        $this->post('/password/first-change', [
            'password'              => 'BrandNewStrongPass888!',
            'password_confirmation' => 'BrandNewStrongPass888!',
        ]);

        $this->post('/logout');

        $oldLogin = $this->post('/login', [
            'email'    => 'LCS-ADM-2026-0777',
            'password' => 'OldTempSecret123',
        ]);
        $this->assertGuest();
        $oldLogin->assertSessionHasErrors('email');

        $newLogin = $this->post('/login', [
            'email'    => 'LCS-ADM-2026-0777',
            'password' => 'BrandNewStrongPass888!',
        ]);
        $this->assertAuthenticatedAs($studentUser);

        $auditLog = Activity::where('description', 'Password updated on first login')->latest('id')->first();
        if ($auditLog) {
            $propJson = json_encode($auditLog->properties);
            $this->assertStringNotContainsString('OldTempSecret123', $propJson);
            $this->assertStringNotContainsString('BrandNewStrongPass888!', $propJson);
        }
    }

    public function test_portal_password_reset_invalidates_old_password_and_generates_clean_audit_log(): void
    {
        $studentUser = User::create([
            'school_id'                    => $this->school->id,
            'name'                         => 'Shoaib Malik',
            'username'                     => 'LCS-ADM-2026-0888',
            'password'                     => Hash::make('ActiveWorkingPass123'),
            'temporary_password_encrypted' => null,
            'must_change_password'         => false,
            'status'                       => 'active',
        ]);
        $studentUser->assignRole('student');

        $student = Student::create([
            'school_id'    => $this->school->id,
            'user_id'      => $studentUser->id,
            'admission_no' => 'ADM-2026-0888',
            'first_name'   => 'Shoaib',
            'last_name'    => 'Malik',
            'gender'       => 'male',
            'class_id'     => $this->class->id,
            'status'       => 'active',
        ]);

        $this->actingAs($this->schoolAdmin);

        $this->post("/school/students/{$student->id}/portal-access/reset-password");

        $this->post('/logout');

        $failedLogin = $this->post('/login', [
            'email'    => 'LCS-ADM-2026-0888',
            'password' => 'ActiveWorkingPass123',
        ]);
        $this->assertGuest();
        $failedLogin->assertSessionHasErrors('email');

        $resetLog = Activity::where('description', 'Portal password reset')->latest('id')->first();
        $this->assertNotNull($resetLog);
        $this->assertEquals($this->schoolAdmin->id, $resetLog->causer_id);
        $this->assertStringNotContainsString('ActiveWorkingPass123', json_encode($resetLog->properties));
    }

    public function test_queued_payload_and_logs_contain_no_plaintext_temporary_password_sentinel(): void
    {
        Mail::fake();

        $sentinel = 'TEST-TEMP-CREDENTIAL-SENTINEL';

        $studentUser = User::create([
            'school_id'                     => $this->school->id,
            'name'                          => 'Sentinel Student',
            'username'                      => 'LCS-ADM-2026-9999',
            'email'                         => 'sentinel@school.test',
            'password'                      => Hash::make($sentinel),
            'temporary_password_encrypted'  => Crypt::encryptString($sentinel),
            'temporary_password_expires_at' => now()->addDays(14),
            'must_change_password'          => true,
            'status'                        => 'active',
        ]);
        $studentUser->assignRole('student');

        $student = Student::create([
            'school_id'    => $this->school->id,
            'user_id'      => $studentUser->id,
            'admission_no' => 'ADM-2026-9999',
            'first_name'   => 'Sentinel',
            'last_name'    => 'Student',
            'gender'       => 'male',
            'class_id'     => $this->class->id,
            'status'       => 'active',
            'email'        => 'sentinel@school.test',
        ]);

        $job = new SendPortalCredentialsEmailJob(
            userId: $studentUser->id,
            targetType: 'student',
        );

        // Assert 1: Serialized Job payload contains NO plaintext sentinel and NO recipient email PII
        $serializedJob = serialize($job);
        $this->assertStringNotContainsString($sentinel, $serializedJob);
        $this->assertStringNotContainsString('sentinel@school.test', $serializedJob);

        // Assert 2: JSON encoded Job contains NO plaintext sentinel and NO recipient email PII
        $jsonJob = json_encode($job);
        $this->assertStringNotContainsString($sentinel, $jsonJob);
        $this->assertStringNotContainsString('sentinel@school.test', $jsonJob);

        // Assert 3: User model array/json representation hides encrypted password and contains no sentinel
        $userArray = $studentUser->toArray();
        $this->assertArrayNotHasKey('temporary_password_encrypted', $userArray);
        $this->assertStringNotContainsString($sentinel, json_encode($userArray));

        // Assert 4: Activity logs contain NO plaintext sentinel
        activity()
            ->causedBy($this->schoolAdmin)
            ->performedOn($student)
            ->withProperties([
                'school_id' => $this->school->id,
                'target_id' => $studentUser->id,
                'username'  => $studentUser->username,
            ])
            ->log('Portal credentials created for student');

        foreach (Activity::all() as $act) {
            $this->assertStringNotContainsString($sentinel, json_encode($act->properties));
            $this->assertStringNotContainsString($sentinel, $act->description);
        }

        // Assert 5: Worker execution decrypts in memory and sends email correctly
        $job->handle();

        Mail::assertSent(StudentPortalCredentialsMail::class, function ($mail) use ($sentinel) {
            return $mail->hasTo('sentinel@school.test')
                && $mail->tempPassword === $sentinel
                && $mail->username === 'LCS-ADM-2026-9999';
        });
    }

    public function test_admission_succeeds_when_student_and_guardian_share_identical_contact_email(): void
    {
        Mail::fake();

        $this->actingAs($this->schoolAdmin);

        $sharedEmail = 'family.shared@pakalfa.test';

        $payload = [
            'first_name'     => 'Hamza',
            'last_name'      => 'Akram',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'admission_date' => '2026-09-01',
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'email'          => $sharedEmail,
            'guardian'       => [
                'name'       => 'Akram Khan',
                'relation'   => 'Father',
                'phone'      => '+923009988776',
                'email'      => $sharedEmail,
                'occupation' => 'Lawyer',
            ],
        ];

        $response = $this->post('/school/students', $payload);
        $response->assertSessionHasNoErrors();
        $response->assertStatus(302);

        $student = Student::where('first_name', 'Hamza')->firstOrFail();
        $guardian = $student->guardian;
        $this->assertNotNull($guardian);

        // Business contact emails are preserved on domain models
        $this->assertEquals($sharedEmail, $student->email);
        $this->assertEquals($sharedEmail, $guardian->email);

        // Guardian user claimed the email, student user has email null
        $guardianUser = $guardian->user;
        $this->assertNotNull($guardianUser);
        $this->assertEquals($sharedEmail, $guardianUser->email);

        $studentUser = $student->user;
        $this->assertNotNull($studentUser);
        $this->assertNull($studentUser->email);
        $this->assertNotNull($studentUser->username);

        // Global users.email uniqueness is preserved (exactly 1 user with that email)
        $this->assertEquals(1, User::where('email', $sharedEmail)->count());

        // Authenticate Guardian via email and via username
        $guardianTempPass = Crypt::decryptString($guardianUser->temporary_password_encrypted);
        $this->actingAsGuest();
        $loginWithEmail = $this->post('/login', [
            'email'    => $sharedEmail,
            'password' => $guardianTempPass,
        ]);
        $this->assertAuthenticatedAs($guardianUser);

        $this->post('/logout');

        $loginGuardianUser = $this->post('/login', [
            'login'    => $guardianUser->username,
            'password' => $guardianTempPass,
        ]);
        $this->assertAuthenticatedAs($guardianUser);

        $this->post('/logout');

        // Authenticate Student via username (since user.email is null)
        $studentTempPass = Crypt::decryptString($studentUser->temporary_password_encrypted);
        $loginStudentUser = $this->post('/login', [
            'login'    => $studentUser->username,
            'password' => $studentTempPass,
        ]);
        $this->assertAuthenticatedAs($studentUser);

        // Both credential emails addressed to shared contact email
        Mail::assertSent(StudentPortalCredentialsMail::class, function ($mail) use ($sharedEmail) {
            return $mail->hasTo($sharedEmail);
        });
        Mail::assertSent(ParentPortalCredentialsMail::class, function ($mail) use ($sharedEmail) {
            return $mail->hasTo($sharedEmail);
        });
    }

    public function test_sibling_admission_with_shared_family_email_and_existing_guardian_reuse(): void
    {
        Mail::fake();

        $this->actingAs($this->schoolAdmin);

        $familyEmail = 'family.legacy@school.test';

        // 1. Admit Child 1
        $payload1 = [
            'first_name'     => 'Haris',
            'last_name'      => 'Rauf',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'admission_date' => '2026-09-01',
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'email'          => $familyEmail,
            'guardian'       => [
                'name'       => 'Rauf Senior',
                'relation'   => 'Father',
                'phone'      => '+923114445566',
                'email'      => $familyEmail,
            ],
        ];

        $res1 = $this->post('/school/students', $payload1);
        $res1->assertSessionHasNoErrors();

        $child1 = Student::where('first_name', 'Haris')->firstOrFail();
        $guardian = $child1->guardian;
        $parentUser = $guardian->user;

        // 2. Admit Child 2 linking existing guardian
        $payload2 = [
            'first_name'     => 'Naseem',
            'last_name'      => 'Rauf',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'admission_date' => '2026-09-01',
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'email'          => $familyEmail,
            'guardian_id'    => $guardian->id,
            'guardian'       => [
                'name'       => 'Rauf Senior',
                'relation'   => 'Father',
                'phone'      => '+923114445566',
            ],
        ];

        $res2 = $this->post('/school/students', $payload2);
        $res2->assertSessionHasNoErrors();

        $child2 = Student::where('first_name', 'Naseem')->firstOrFail();

        // Linked to same guardian without duplicating user
        $this->assertEquals($guardian->id, $child2->guardian_id);
        $this->assertEquals(2, $guardian->students()->count());
        $this->assertEquals(1, User::where('email', $familyEmail)->count());
        $this->assertNull($child2->user->email);
        $this->assertNotNull($child2->user->username);

        // Credential mail for Child 2 sent to familyEmail
        Mail::assertSent(StudentPortalCredentialsMail::class, function ($mail) use ($familyEmail) {
            return $mail->hasTo($familyEmail);
        });
    }

    public function test_resend_email_rules_active_expired_and_password_changed(): void
    {
        Mail::fake();

        $studentUser = User::create([
            'school_id'                     => $this->school->id,
            'name'                          => 'Resend Candidate',
            'username'                      => 'LCS-ADM-2026-7777',
            'email'                         => 'resend@school.test',
            'password'                      => Hash::make('TempPass1234'),
            'temporary_password_encrypted'  => Crypt::encryptString('TempPass1234'),
            'temporary_password_expires_at' => now()->addDays(7),
            'must_change_password'          => true,
            'status'                        => 'active',
        ]);
        $studentUser->assignRole('student');

        $student = Student::create([
            'school_id'    => $this->school->id,
            'user_id'      => $studentUser->id,
            'admission_no' => 'ADM-2026-7777',
            'first_name'   => 'Resend',
            'last_name'    => 'Candidate',
            'gender'       => 'male',
            'class_id'     => $this->class->id,
            'status'       => 'active',
            'email'        => 'resend@school.test',
        ]);

        $this->actingAs($this->schoolAdmin);

        // 1. Active temp credential -> Resend succeeds
        $res1 = $this->post("/school/students/{$student->id}/portal-access/resend-credentials", ['type' => 'student']);
        $res1->assertSessionHas('success');
        Mail::assertSent(StudentPortalCredentialsMail::class);

        Mail::fake();

        // 2. Expired temp credential -> Resend blocked
        $studentUser->update(['temporary_password_expires_at' => now()->subDay()]);
        $res2 = $this->post("/school/students/{$student->id}/portal-access/resend-credentials", ['type' => 'student']);
        $res2->assertSessionHas('error');
        Mail::assertNotSent(StudentPortalCredentialsMail::class);

        // 3. User changed password -> Resend blocked, password not reset
        PortalCredentialService::completePasswordChange($studentUser, 'PermanentPass999!');
        $res3 = $this->post("/school/students/{$student->id}/portal-access/resend-credentials", ['type' => 'student']);
        $res3->assertSessionHas('error');
        Mail::assertNotSent(StudentPortalCredentialsMail::class);

        $studentUser->refresh();
        $this->assertNull($studentUser->temporary_password_encrypted);
        $this->assertTrue(Hash::check('PermanentPass999!', $studentUser->password));
    }

    public function test_admission_session_carries_no_plaintext_passwords_and_flash_is_pruned(): void
    {
        Mail::fake();

        $this->actingAs($this->schoolAdmin);

        $payload = [
            'first_name'     => 'Flash',
            'last_name'      => 'Student',
            'gender'         => 'male',
            'category'       => 'general',
            'status'         => 'active',
            'admission_date' => '2026-09-01',
            'class_id'       => $this->class->id,
            'section_id'     => $this->section->id,
            'email'          => 'flash@school.test',
            'guardian'       => [
                'name'       => 'Flash Father',
                'relation'   => 'Father',
                'phone'      => '+923000000000',
            ],
        ];

        // 1. Initial admission POST redirect carries safe portal_account_created and NO plaintext passwords
        $response = $this->post('/school/students', $payload);
        $response->assertSessionHas('portal_account_created');
        $response->assertSessionMissing('admission_credentials');

        $sessionAll = json_encode(session()->all());
        $this->assertStringNotContainsString('temp_password', $sessionAll);

        // 2. Follow redirect to student show
        $student = Student::where('first_name', 'Flash')->firstOrFail();
        $showResponse = $this->get("/school/students/{$student->id}");
        $showResponse->assertOk();

        // 3. On subsequent request, flash data is aged and pruned
        $nextResponse = $this->get('/school/students');
        $nextResponse->assertOk();
        $this->assertNull(session('portal_account_created'));
        $this->assertNull(session('admission_credentials'));
    }

    public function test_backward_compatibility_existing_staff_email_and_synthetic_student_email_login(): void
    {
        // 1. Staff email login works
        $staff = User::create([
            'school_id' => $this->school->id,
            'name'      => 'Staff Member',
            'email'     => 'staff@school.test',
            'password'  => Hash::make('StaffSecret123'),
            'status'    => 'active',
        ]);
        $staff->assignRole('teacher');

        $this->post('/login', [
            'email'    => 'staff@school.test',
            'password' => 'StaffSecret123',
        ]);
        $this->assertAuthenticatedAs($staff);

        $this->post('/logout');

        // 2. Synthetic student email login works
        $syntheticStudent = User::create([
            'school_id'                    => $this->school->id,
            'name'                         => 'Legacy Synthetic',
            'username'                     => 'LCS-ADM-2025-0001',
            'email'                        => 'LCS-2025-0001@student.school.local',
            'password'                     => Hash::make('SyntheticSecret123'),
            'status'                       => 'active',
            'must_change_password'         => false,
            'temporary_password_encrypted' => null,
        ]);
        $syntheticStudent->assignRole('student');

        $this->post('/login', [
            'email'    => 'LCS-2025-0001@student.school.local',
            'password' => 'SyntheticSecret123',
        ]);
        $this->assertAuthenticatedAs($syntheticStudent);

        $this->post('/logout');

        // 3. User with email NULL logs in with username
        $noEmailStudent = User::create([
            'school_id'                    => $this->school->id,
            'name'                         => 'No Email Student',
            'username'                     => 'LCS-ADM-2026-5555',
            'email'                        => null,
            'password'                     => Hash::make('NoEmailSecret123'),
            'status'                       => 'active',
            'must_change_password'         => false,
            'temporary_password_encrypted' => null,
        ]);
        $noEmailStudent->assignRole('student');

        $this->post('/login', [
            'login'    => 'LCS-ADM-2026-5555',
            'password' => 'NoEmailSecret123',
        ]);
        $this->assertAuthenticatedAs($noEmailStudent);
    }
}
