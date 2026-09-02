<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    }

    public function test_dry_run_performs_zero_database_writes(): void
    {
        $this->artisan('admin:create-super-admin', [
            '--name'    => 'Root Super Admin',
            '--email'   => 'super@studentxces.test',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Pre-validation PASSED')
            ->expectsOutputToContain('Simulation complete')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'super@studentxces.test']);
        $this->assertEquals(0, User::count());
    }

    public function test_default_mode_without_execute_is_dry_run(): void
    {
        $this->artisan('admin:create-super-admin', [
            '--name'  => 'Root Super Admin',
            '--email' => 'super@studentxces.test',
        ])
            ->expectsOutputToContain('Simulation complete')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'super@studentxces.test']);
    }

    public function test_execute_creates_super_admin_with_valid_interactive_password(): void
    {
        $this->artisan('admin:create-super-admin', [
            '--name'    => 'Alex Mercer',
            '--email'   => 'alex@studentxces.test',
            '--execute' => true,
        ])
            ->expectsQuestion('Enter Super Admin password (min 12 chars, mixed case, number, symbol):', 'StrongP@ssw0rd123!')
            ->expectsQuestion('Confirm Super Admin password:', 'StrongP@ssw0rd123!')
            ->expectsOutputToContain('SUPER ADMIN ACCOUNT PROVISIONED SUCCESSFULLY')
            ->assertSuccessful();

        $user = User::where('email', 'alex@studentxces.test')->first();
        $this->assertNotNull($user);
        $this->assertEquals('Alex Mercer', $user->name);
        $this->assertNull($user->school_id);
        $this->assertEquals('active', $user->status);
        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertTrue(Hash::check('StrongP@ssw0rd123!', $user->password));

        // Activity log audit test
        $activity = Activity::where('log_name', 'platform')->latest()->first();
        $this->assertNotNull($activity);
        $this->assertEquals('Super Admin account provisioned via CLI', $activity->description);
        $this->assertStringNotContainsString('StrongP@ssw0rd123!', json_encode($activity->properties));
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::create([
            'name'      => 'Existing User',
            'email'     => 'alex@studentxces.test',
            'password'  => Hash::make('SecretPass123!'),
            'status'    => 'active',
            'school_id' => null,
        ]);

        $this->artisan('admin:create-super-admin', [
            '--name'    => 'Alex Clone',
            '--email'   => 'alex@studentxces.test',
            '--execute' => true,
        ])
            ->expectsOutputToContain('A user with this email address already exists')
            ->assertFailed();

        $this->assertEquals(1, User::where('email', 'alex@studentxces.test')->count());
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->artisan('admin:create-super-admin', [
            '--name'    => 'Weak User',
            '--email'   => 'weak@studentxces.test',
            '--execute' => true,
        ])
            ->expectsQuestion('Enter Super Admin password (min 12 chars, mixed case, number, symbol):', 'simple')
            ->expectsQuestion('Confirm Super Admin password:', 'simple')
            ->expectsOutputToContain('Password must be at least 12 characters long')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'weak@studentxces.test']);
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $this->artisan('admin:create-super-admin', [
            '--name'    => 'Mismatch User',
            '--email'   => 'mismatch@studentxces.test',
            '--execute' => true,
        ])
            ->expectsQuestion('Enter Super Admin password (min 12 chars, mixed case, number, symbol):', 'StrongP@ssw0rd123!')
            ->expectsQuestion('Confirm Super Admin password:', 'DifferentPassword456!')
            ->expectsOutputToContain('Password confirmation does not match')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'mismatch@studentxces.test']);
    }

    public function test_missing_super_admin_role_fails_closed(): void
    {
        Role::where('name', 'super-admin')->delete();

        $this->artisan('admin:create-super-admin', [
            '--name'    => 'No Role User',
            '--email'   => 'norole@studentxces.test',
            '--execute' => true,
        ])
            ->expectsOutputToContain("Required role 'super-admin' does not exist")
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'norole@studentxces.test']);
    }
}
