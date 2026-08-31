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

    public function test_dry_run_performs_validation_and_creates_zero_records(): void
    {
        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge Test',
            '--slug'                => 'lahore-cambridge-test',
            '--admin-name'          => 'Dr. Farhan Ali',
            '--admin-email'         => 'admin@lahorecambridge.test',
            '--admin-password'      => 'SuperSecret123!',
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
            '--admin-password'      => 'SuperSecret123!',
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

    public function test_execute_flag_transactionally_creates_school_admin_and_academic_year(): void
    {
        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge School',
            '--slug'                => 'lahore-cambridge-school',
            '--email'               => 'info@lahorecambridgeschool.com',
            '--phone'               => '+92 42 35789000',
            '--address'             => '12-C Gulberg III',
            '--admin-name'          => 'Principal Tariq Mehmood',
            '--admin-email'         => 'admin@lahorecambridgeschool.com',
            '--admin-password'      => 'AdminPass2026!',
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2026-08-01',
            '--academic-end'        => '2027-06-30',
            '--execute'             => true,
        ])
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
        $this->assertEquals($school->id, $admin->school_id, 'Admin must be bound to dynamic school ID.');
        $this->assertTrue($admin->hasRole('school-admin'));
        $this->assertTrue(Hash::check('AdminPass2026!', $admin->password));

        // Verify Academic Year
        $this->assertEquals(1, AcademicYear::count());
        $academicYear = AcademicYear::first();
        $this->assertEquals('2026-2027', $academicYear->name);
        $this->assertEquals($school->id, $academicYear->school_id, 'Academic year must belong to dynamic school ID.');
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
            '--name'                => 'Lahore Cambridge School',
            '--slug'                => 'lahore-cambridge-school',
            '--admin-name'          => 'Admin Name',
            '--admin-email'         => 'newadmin@example.com',
            '--admin-password'      => 'Password123!',
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

    public function test_duplicate_admin_email_fails_validation_with_zero_records(): void
    {
        User::factory()->create(['email' => 'admin@lahorecambridge.com']);

        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge School',
            '--slug'                => 'lahore-cambridge-school',
            '--admin-name'          => 'Admin Name',
            '--admin-email'         => 'admin@lahorecambridge.com',
            '--admin-password'      => 'Password123!',
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

    public function test_invalid_academic_dates_fails_validation_with_zero_records(): void
    {
        $this->artisan('tenant:onboard', [
            '--name'                => 'Lahore Cambridge School',
            '--slug'                => 'lahore-cambridge-school',
            '--admin-name'          => 'Admin Name',
            '--admin-email'         => 'admin@lahorecambridge.com',
            '--admin-password'      => 'Password123!',
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
            '--admin-password'      => $password,
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2026-08-01',
            '--academic-end'        => '2027-06-30',
            '--execute'             => true,
        ])
            ->doesntExpectOutputToContain($password)
            ->expectsOutputToContain('Admin Password Supplied')
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
            '--admin-password'      => 'Password123!',
            '--academic-year-name'  => '2026-2027',
            '--academic-start'      => '2026-08-01',
            '--academic-end'        => '2027-06-30',
            '--execute'             => true,
        ])->assertExitCode(0);

        $this->assertEquals(2, School::count());
        $this->assertDatabaseHas('schools', ['id' => $demo->id, 'name' => 'Greenfield Academy']);
    }
}
