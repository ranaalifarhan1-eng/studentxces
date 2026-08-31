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

        $disk = Storage::disk(config()->has('filesystems.disks.private') ? 'private' : 'local');
        $disk->delete($disk->files('onboarding_manifests'));
    }

    protected function tearDown(): void
    {
        $disk = Storage::disk(config()->has('filesystems.disks.private') ? 'private' : 'local');
        $disk->delete($disk->files('onboarding_manifests'));

        parent::tearDown();
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

    public function test_invalid_short_password_fails_and_creates_zero_manifests_and_zero_db_rows(): void
    {
        $disk = Storage::disk(config()->has('filesystems.disks.private') ? 'private' : 'local');
        $initialManifestCount = count($disk->files('onboarding_manifests'));

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
            ->expectsQuestion('Enter initial School Admin temporary password (min 8 chars):', 'short')
            ->expectsOutputToContain('School Admin password is required and must be at least 8 characters')
            ->assertExitCode(1);

        $this->assertEquals(0, School::count());
        $this->assertEquals(0, User::count());
        $this->assertEquals(0, AcademicYear::count());

        // Zero manifests created for rejected password attempt
        $this->assertCount($initialManifestCount, $disk->files('onboarding_manifests'));
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
            ->expectsOutputToContain('not a valid IANA timezone identifier')
            ->assertExitCode(1);

        $this->assertEquals(0, School::count());
        $this->assertEquals(0, User::count());
    }

    public function test_missing_school_admin_role_fails_validation_with_zero_records(): void
    {
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

        $manifestFilename = 'onboard_test_reconcile.json';
        $manifestPath = "onboarding_manifests/{$manifestFilename}";
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

        // Run reconciliation via command
        $this->artisan('tenant:onboard', ['--reconcile' => $manifestFilename])
            ->expectsOutputToContain('RECONCILED')
            ->assertExitCode(0);

        // Assert file updated to COMMITTED with exact IDs
        $saved = json_decode($disk->get($manifestPath), true);
        $this->assertEquals('COMMITTED', $saved['status']);
        $this->assertEquals($school->id, $saved['school_id']);
        $this->assertEquals($admin->id, $saved['admin_user_id']);
        $this->assertEquals($year->id, $saved['academic_year_id']);

        // Idempotent check: Re-reconciling returns ALREADY_COMMITTED
        $idempotentResult = $service->reconcileManifest($manifestFilename);
        $this->assertEquals('ALREADY_COMMITTED', $idempotentResult['status']);
        $this->assertEquals($school->id, $idempotentResult['school_id']);
    }

    public function test_reconciliation_rejects_path_traversal_and_outside_paths(): void
    {
        $service = app(TenantOnboardingService::class);

        // 1. Path traversal
        $resTraversal = $service->reconcileManifest('../../../outside.json');
        $this->assertEquals('PATH_TRAVERSAL_BLOCKED', $resTraversal['status']);

        // 2. Absolute Linux path
        $resAbsLinux = $service->reconcileManifest('/etc/passwd');
        $this->assertEquals('PATH_TRAVERSAL_BLOCKED', $resAbsLinux['status']);

        // 3. Absolute Windows path
        $resAbsWin = $service->reconcileManifest('C:/test/file.json');
        $this->assertEquals('PATH_TRAVERSAL_BLOCKED', $resAbsWin['status']);

        // 4. Non-JSON extension
        $resNonJson = $service->reconcileManifest('test_file.txt');
        $this->assertEquals('INVALID_MANIFEST_PATH', $resNonJson['status']);
    }

    public function test_reconciliation_requires_school_admin_role_and_exact_school_linkage(): void
    {
        $service = app(TenantOnboardingService::class);
        $disk = Storage::disk(config()->has('filesystems.disks.private') ? 'private' : 'local');

        $school = School::create([
            'name'     => 'Mismatch Academy',
            'slug'     => 'mismatch-academy',
            'status'   => 'active',
        ]);

        // User belongs to another school (foreign school ID 999)
        $admin = User::create([
            'name'      => 'Mismatch Admin',
            'email'     => 'mismatch@example.com',
            'password'  => Hash::make('Secret123!'),
            'school_id' => 999, // foreign!
            'status'    => 'active',
        ]);
        $admin->assignRole('school-admin');

        $manifestFilename = 'onboard_test_mismatch.json';
        $manifestPath = "onboarding_manifests/{$manifestFilename}";
        $pendingManifest = [
            'execution_id'       => 'onboard_test_789',
            'status'             => 'PENDING',
            'school_name'        => 'Mismatch Academy',
            'school_slug'        => 'mismatch-academy',
            'admin_email'        => 'mismatch@example.com',
            'academic_year_name' => '2026-2027',
        ];
        $disk->put($manifestPath, json_encode($pendingManifest));

        $result = $service->reconcileManifest($manifestFilename);
        $this->assertEquals('AMBIGUOUS_MANUAL_REVIEW_REQUIRED', $result['status']);
    }

    public function test_ambiguous_manifest_refuses_automatic_reconciliation(): void
    {
        $service = app(TenantOnboardingService::class);
        $disk = Storage::disk(config()->has('filesystems.disks.private') ? 'private' : 'local');

        School::create([
            'name'     => 'Partial Academy',
            'slug'     => 'partial-academy',
            'status'   => 'active',
        ]);

        $manifestFilename = 'onboard_test_ambiguous.json';
        $manifestPath = "onboarding_manifests/{$manifestFilename}";
        $pendingManifest = [
            'execution_id'       => 'onboard_test_456',
            'status'             => 'PENDING',
            'school_name'        => 'Partial Academy',
            'school_slug'        => 'partial-academy',
            'admin_email'        => 'missingadmin@example.com',
            'academic_year_name' => '2026-2027',
        ];
        $disk->put($manifestPath, json_encode($pendingManifest));

        $result = $service->reconcileManifest($manifestFilename);
        $this->assertEquals('AMBIGUOUS_MANUAL_REVIEW_REQUIRED', $result['status']);
    }

    public function test_service_transaction_rolls_back_school_if_admin_creation_fails(): void
    {
        $service = app(TenantOnboardingService::class);
        $disk = Storage::disk(config()->has('filesystems.disks.private') ? 'private' : 'local');

        // Force a failure during user creation by setting up an invalid role state inside transaction
        // or hooking into User creation
        User::creating(function () {
            throw new \RuntimeException('Simulated User Creation Failure');
        });

        $caught = false;
        try {
            $service->onboard([
                'name'                => 'Rollback School',
                'slug'                => 'rollback-school',
                'admin_name'          => 'Admin Name',
                'admin_email'         => 'admin@rollback.test',
                'admin_password'      => 'Password123!',
                'academic_year_name'  => '2026-2027',
                'academic_start'      => '2026-08-01',
                'academic_end'        => '2027-06-30',
            ], true);
        } catch (\Throwable $e) {
            $caught = true;
            $this->assertEquals('Simulated User Creation Failure', $e->getMessage());
        }

        $this->assertTrue($caught);

        // School must be rolled back (zero partial foundation)
        $this->assertEquals(0, School::count());
        $this->assertEquals(0, User::count());
        $this->assertEquals(0, AcademicYear::count());

        // Manifest must be FAILED_ROLLED_BACK
        $manifests = $disk->files('onboarding_manifests');
        $this->assertNotEmpty($manifests);
        $manifestContent = json_decode($disk->get($manifests[0]), true);
        $this->assertEquals('FAILED_ROLLED_BACK', $manifestContent['status']);
    }

    public function test_db_commit_succeeds_but_journal_finalization_failure_preserves_db_foundation(): void
    {
        // Mock service writeManifest on second call (post-commit) to return false
        $service = new class extends TenantOnboardingService {
            public int $writeCount = 0;
            public function writeManifest(string $filename, array $content): bool
            {
                $this->writeCount++;
                if ($this->writeCount === 1) {
                    // First call: PENDING manifest
                    return parent::writeManifest($filename, $content);
                }
                // Second call: simulate filesystem finalization failure
                return false;
            }
        };

        $result = $service->onboard([
            'name'                => 'Preserved School',
            'slug'                => 'preserved-school',
            'admin_name'          => 'Preserved Admin',
            'admin_email'         => 'preserved@example.com',
            'admin_password'      => 'Password123!',
            'academic_year_name'  => '2026-2027',
            'academic_start'      => '2026-08-01',
            'academic_end'        => '2027-06-30',
        ], true);

        $this->assertEquals('DB_COMMITTED_JOURNAL_INCOMPLETE', $result['status']);

        // DB foundation is preserved!
        $this->assertEquals(1, School::count());
        $this->assertEquals(1, User::count());
        $this->assertEquals(1, AcademicYear::count());
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
