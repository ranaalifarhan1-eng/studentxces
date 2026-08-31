<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\User;
use App\Services\TenantOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'school-admin', 'guard_name' => 'web']);
    }

    public function test_dry_run_performs_validation_and_creates_zero_records_without_password_prompt(): void
    {
        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge Test',
            '--slug'                => 'lahore-cambridge-test',
            '--admin-name'          => 'Dr. Farhan Ali',
            '--admin-email'         => 'admin@lahorecambridge.test',
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2026-08-01',
            '--academic-end'        => '2027-06-30',
            '--dry-run'             => true,
        ])
            ->expectsOutputToContain('DRY-RUN / SIMULATION')
            ->expectsOutputToContain('Pre-validation PASSED')
            ->assertExitCode(0);

        $this->assertEquals(0, School::count());
        $this->assertEquals(0, User::count());
        $this->assertEquals(0, AcademicYear::count());
    }

    public function test_default_invocation_without_execute_performs_simulation_with_zero_records(): void
    {
        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge Test',
            '--slug'                => 'lahore-cambridge-test',
            '--admin-name'          => 'Dr. Farhan Ali',
            '--admin-email'         => 'admin@lahorecambridge.test',
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2026-08-01',
            '--academic-end'        => '2027-06-30',
        ])
            ->expectsOutputToContain('DRY-RUN / SIMULATION')
            ->expectsOutputToContain('Simulation complete')
            ->assertExitCode(0);

        $this->assertEquals(0, School::count());
        $this->assertEquals(0, User::count());
        $this->assertEquals(0, AcademicYear::count());
    }

    public function test_execute_flag_securely_prompts_password_and_transactionally_creates_tenant(): void
    {
        $password = 'SecretPass2026!';

        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge School',
            '--slug'                => 'lahore-cambridge-school',
            '--email'               => 'info@lahorecambridgeschool.com',
            '--phone'               => '+92 42 35789000',
            '--address'             => '12-C Gulberg III',
            '--admin-name'          => 'Principal Tariq Mehmood',
            '--admin-email'         => 'admin@lahorecambridgeschool.com',
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2026-08-01',
            '--academic-end'        => '2027-06-30',
            '--execute'             => true,
        ])
            ->expectsQuestion('Enter initial School Admin temporary password (min 8 chars):', $password)
            ->expectsOutputToContain('TENANT FOUNDATION COMMITTED SUCCESSFULLY')
            ->expectsOutputToContain('SCHOOL_ID=')
            ->assertExitCode(0);

        // Verify School
        $this->assertEquals(1, School::count());
        $school = School::first();
        $this->assertEquals('Lahore Cambridge School', $school->name);
        $this->assertEquals('lahore-cambridge-school', $school->slug);
        $this->assertEquals('PK', $school->country);
        $this->assertEquals('Asia/Karachi', $school->timezone);
        $this->assertEquals('PKR', $school->currency);
        $this->assertEquals('en', $school->language);
        $this->assertEquals('active', $school->status);
        $this->assertEquals('Lahore', $school->city);
        $this->assertEquals('Punjab', $school->state);

        // Verify School Admin User
        $this->assertEquals(1, User::count());
        $admin = User::first();
        $this->assertEquals('Principal Tariq Mehmood', $admin->name);
        $this->assertEquals('admin@lahorecambridgeschool.com', $admin->email);
        $this->assertEquals($school->id, $admin->school_id);
        $this->assertTrue($admin->hasRole('school-admin'));
        $this->assertTrue(Hash::check($password, $admin->password));

        // Verify Academic Year
        $this->assertEquals(1, AcademicYear::count());
        $academicYear = AcademicYear::first();
        $this->assertEquals('2026-2027', $academicYear->name);
        $this->assertEquals($school->id, $academicYear->school_id);
        $this->assertTrue($academicYear->is_current);
        $this->assertEquals('2026-08-01', $academicYear->start_date->format('Y-m-d'));
        $this->assertEquals('2027-06-30', $academicYear->end_date->format('Y-m-d'));
    }

    public function test_duplicate_slug_fails_validation_with_zero_records(): void
    {
        School::create([
            'name'   => 'Existing School',
            'slug'   => 'lahore-cambridge-school',
            'status' => 'active',
        ]);

        $this->artisan('tenant:onboard', [
            '--name'                => 'Different School Name',
            '--slug'                => 'lahore-cambridge-school',
            '--admin-name'          => 'Admin Name',
            '--admin-email'         => 'newadmin@example.com',
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2026-08-01',
            '--academic-end'        => '2027-06-30',
            '--execute'             => true,
        ])
            ->expectsQuestion('Enter initial School Admin temporary password (min 8 chars):', 'Password123!')
            ->expectsOutputToContain('Onboarding validation failed')
            ->assertExitCode(1);

        $this->assertEquals(1, School::count());
        $this->assertEquals(0, User::count());
        $this->assertEquals(0, AcademicYear::count());
    }

    public function test_duplicate_school_name_identity_fails_validation_with_zero_records(): void
    {
        School::create([
            'name'   => 'Lahore Cambridge School',
            'slug'   => 'lcs-old-slug',
            'status' => 'active',
        ]);

        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge School',
            '--slug'                => 'lahore-cambridge-school-new',
            '--admin-name'          => 'Admin Name',
            '--admin-email'         => 'newadmin@example.com',
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2026-08-01',
            '--academic-end'        => '2027-06-30',
            '--execute'             => true,
        ])
            ->expectsQuestion('Enter initial School Admin temporary password (min 8 chars):', 'Password123!')
            ->expectsOutputToContain("A school with the name 'Lahore Cambridge School' already exists.")
            ->assertExitCode(1);

        $this->assertEquals(1, School::count());
        $this->assertEquals(0, User::count());
        $this->assertEquals(0, AcademicYear::count());
    }

    public function test_duplicate_admin_email_fails_validation_with_zero_records(): void
    {
        User::factory()->create(['email' => 'admin@lahorecambridge.com']);

        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge School',
            '--slug'                => 'lahore-cambridge-school',
            '--admin-name'          => 'Admin Name',
            '--admin-email'         => 'admin@lahorecambridge.com',
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2026-08-01',
            '--academic-end'        => '2027-06-30',
            '--execute'             => true,
        ])
            ->expectsQuestion('Enter initial School Admin temporary password (min 8 chars):', 'Password123!')
            ->expectsOutputToContain('Onboarding validation failed')
            ->assertExitCode(1);

        $this->assertEquals(0, School::count());
        $this->assertEquals(1, User::count());
        $this->assertEquals(0, AcademicYear::count());
    }

    public function test_invalid_timezone_fails_validation_with_zero_records(): void
    {
        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge School',
            '--slug'                => 'lahore-cambridge-school',
            '--timezone'            => 'Invalid/NonExistentZone',
            '--admin-name'          => 'Admin Name',
            '--admin-email'         => 'admin@lahorecambridge.com',
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2026-08-01',
            '--academic-end'        => '2027-06-30',
            '--execute'             => true,
        ])
            ->expectsQuestion('Enter initial School Admin temporary password (min 8 chars):', 'Password123!')
            ->expectsOutputToContain('not a valid IANA timezone identifier')
            ->assertExitCode(1);

        $this->assertEquals(0, School::count());
        $this->assertEquals(0, User::count());
    }

    public function test_missing_school_admin_role_fails_validation_with_zero_records(): void
    {
        // Delete school-admin role
        Role::where('name', 'school-admin')->delete();

        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge School',
            '--slug'                => 'lahore-cambridge-school',
            '--admin-name'          => 'Admin Name',
            '--admin-email'         => 'admin@lahorecambridge.com',
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2026-08-01',
            '--academic-end'        => '2027-06-30',
            '--execute'             => true,
        ])
            ->expectsQuestion('Enter initial School Admin temporary password (min 8 chars):', 'Password123!')
            ->expectsOutputToContain("Required platform role 'school-admin' does not exist.")
            ->assertExitCode(1);

        $this->assertEquals(0, School::count());
    }

    public function test_invalid_academic_dates_fails_validation_with_zero_records(): void
    {
        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge School',
            '--slug'                => 'lahore-cambridge-school',
            '--admin-name'          => 'Admin Name',
            '--admin-email'         => 'admin@lahorecambridge.com',
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2027-06-30',
            '--academic-end'        => '2026-08-01', // end before start!
            '--execute'             => true,
        ])
            ->expectsQuestion('Enter initial School Admin temporary password (min 8 chars):', 'Password123!')
            ->expectsOutputToContain('The academic year end date must be after the start date')
            ->assertExitCode(1);

        $this->assertEquals(0, School::count());
        $this->assertEquals(0, User::count());
        $this->assertEquals(0, AcademicYear::count());
    }

    public function test_password_never_appears_in_manifest_or_output(): void
    {
        $password = 'SecretUnexposed123!';

        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge School',
            '--slug'                => 'lahore-cambridge-school',
            '--admin-name'          => 'Admin Name',
            '--admin-email'         => 'admin@lahorecambridge.com',
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2026-08-01',
            '--academic-end'        => '2027-06-30',
            '--execute'             => true,
        ])
            ->expectsQuestion('Enter initial School Admin temporary password (min 8 chars):', $password)
            ->doesntExpectOutputToContain($password)
            ->assertExitCode(0);

        // Inspect manifest files
        $disk = Storage::disk(config()->has('filesystems.disks.private') ? 'private' : 'local');
        $manifests = $disk->files('onboarding_manifests');
        $this->assertNotEmpty($manifests);

        foreach ($manifests as $manifestPath) {
            $content = $disk->get($manifestPath);
            $this->assertStringNotContainsString($password, $content);
            $this->assertStringContainsString('COMMITTED', $content);
        }
    }

    public function test_pending_manifest_reconciles_cleanly_to_committed_foundation(): void
    {
        $service = app(TenantOnboardingService::class);
        $disk = Storage::disk(config()->has('filesystems.disks.private') ? 'private' : 'local');

        // Pre-create matching School, User, AcademicYear in DB
        $school = School::create([
            'name'     => 'Reconcile Academy',
            'slug'     => 'reconcile-academy',
            'country'  => 'PK',
            'timezone' => 'Asia/Karachi',
            'currency' => 'PKR',
            'language' => 'en',
            'status'   => 'active',
        ]);

        $admin = User::create([
            'name'      => 'Reconcile Admin',
            'email'     => 'recadmin@example.com',
            'password'  => Hash::make('Secret123!'),
            'school_id' => $school->id,
            'status'    => 'active',
        ]);
        $admin->assignRole('school-admin');

        $year = AcademicYear::create([
            'school_id'  => $school->id,
            'name'       => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date'   => '2027-06-30',
            'is_current' => true,
        ]);

        // Write a PENDING manifest file
        $manifestPath = 'onboarding_manifests/onboard_test_reconcile.json';
        $pendingManifest = [
            'execution_id'       => 'onboard_test_123',
            'timestamp'          => now()->toIso8601String(),
            'status'             => 'PENDING',
            'school_name'        => 'Reconcile Academy',
            'school_slug'        => 'reconcile-academy',
            'admin_name'         => 'Reconcile Admin',
            'admin_email'        => 'recadmin@example.com',
            'academic_year_name' => '2026-2027',
            'academic_start'     => '2026-08-01',
            'academic_end'       => '2027-06-30',
            'school_id'          => null,
            'admin_user_id'      => null,
            'academic_year_id'   => null,
        ];
        $disk->put($manifestPath, json_encode($pendingManifest));

        // Run reconciliation
        $result = $service->reconcileManifest($manifestPath);
        $this->assertEquals('RECONCILED', $result['status']);
        $this->assertEquals($school->id, $result['school_id']);
        $this->assertEquals($admin->id, $result['admin_user_id']);
        $this->assertEquals($year->id, $result['academic_year_id']);

        // Assert file updated to COMMITTED
        $saved = json_decode($disk->get($manifestPath), true);
        $this->assertEquals('COMMITTED', $saved['status']);

        // Idempotent check: Re-reconciling returns ALREADY_COMMITTED
        $idempotentResult = $service->reconcileManifest($manifestPath);
        $this->assertEquals('ALREADY_COMMITTED', $idempotentResult['status']);
    }

    public function test_ambiguous_manifest_refuses_automatic_reconciliation(): void
    {
        $service = app(TenantOnboardingService::class);
        $disk = Storage::disk(config()->has('filesystems.disks.private') ? 'private' : 'local');

        // Pre-create only School without matching user
        School::create([
            'name'     => 'Partial Academy',
            'slug'     => 'partial-academy',
            'status'   => 'active',
        ]);

        $manifestPath = 'onboarding_manifests/onboard_test_ambiguous.json';
        $pendingManifest = [
            'execution_id'       => 'onboard_test_456',
            'status'             => 'PENDING',
            'school_name'        => 'Partial Academy',
            'school_slug'        => 'partial-academy',
            'admin_email'        => 'missingadmin@example.com',
            'academic_year_name' => '2026-2027',
        ];
        $disk->put($manifestPath, json_encode($pendingManifest));

        $result = $service->reconcileManifest($manifestPath);
        $this->assertEquals('AMBIGUOUS_MANUAL_REVIEW_REQUIRED', $result['status']);
    }

    public function test_onboarding_does_not_affect_demo_tenants(): void
    {
        $demo = School::create([
            'name'   => 'Greenfield Academy',
            'slug'   => 'greenfield-academy',
            'status' => 'active',
        ]);

        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge School',
            '--slug'                => 'lahore-cambridge-school',
            '--admin-name'          => 'Admin Name',
            '--admin-email'         => 'admin@lahorecambridge.com',
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2026-08-01',
            '--academic-end'        => '2027-06-30',
            '--execute'             => true,
        ])
            ->expectsQuestion('Enter initial School Admin temporary password (min 8 chars):', 'Password123!')
            ->assertExitCode(0);

        $this->assertEquals(2, School::count());
        $this->assertDatabaseHas('schools', ['id' => $demo->id, 'name' => 'Greenfield Academy']);
    }
}
