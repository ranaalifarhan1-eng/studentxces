<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class CreateSuperAdminCommand extends Command
{
    protected $signature = 'admin:create-super-admin
        {--name= : Super Admin full name}
        {--email= : Super Admin email address}
        {--dry-run : Perform validation without writing changes}
        {--execute : Create the Super Admin account}';

    protected $description = 'Safely and transactionally provision a production Super Admin account';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $execute = false;
        }

        $name = trim((string) $this->option('name'));
        $email = strtolower(trim((string) $this->option('email')));

        $this->info('====================================================');
        $this->info('   STUDENTXCES SUPER ADMIN PROVISIONING ENGINE');
        $this->info('====================================================');
        $this->line('Mode: ' . ($execute ? '<fg=yellow;options=bold>EXECUTE (MUTATION)</>' : '<fg=cyan;options=bold>DRY-RUN / SIMULATION</>'));

        // 1. Check if super-admin role exists
        $role = Role::where('name', 'super-admin')->where('guard_name', 'web')->first();
        if (! $role) {
            $this->error("Error: Required role 'super-admin' does not exist in database.");
            $this->comment('Please ensure global roles are seeded via RolePermissionSeeder before provisioning.');
            return self::FAILURE;
        }

        // 2. Validate name and email
        $validator = Validator::make([
            'name'  => $name,
            'email' => $email,
        ], [
            'name'  => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email:rfc,filter', 'max:255', 'unique:users,email'],
        ], [
            'name.required'  => 'Super Admin name is required (--name="Full Name").',
            'email.required' => 'Super Admin email is required (--email="user@domain.com").',
            'email.unique'   => 'A user with this email address already exists. Re-running for an existing account is rejected.',
        ]);

        if ($validator->fails()) {
            $this->error('Validation failed:');
            foreach ($validator->errors()->all() as $error) {
                $this->line("  - <fg=red>{$error}</>");
            }
            return self::FAILURE;
        }

        $this->table(['Parameter', 'Configured Value'], [
            ['Name', $name],
            ['Email', $email],
            ['Role', 'super-admin'],
            ['School ID', 'null (Platform / Global Context)'],
            ['Status', 'active'],
            ['Password Prompt', $execute ? '<fg=yellow>PROMPTING SECURELY (INTERACTIVE)</>' : '<fg=cyan>WILL BE REQUESTED SECURELY DURING EXECUTION</>'],
        ]);

        // 3. Dry-run early exit
        if (! $execute) {
            $this->newLine();
            $this->info('✓ Pre-validation PASSED.');
            $this->comment('Simulation complete. Zero database mutations were performed.');
            $this->line('To commit this Super Admin account, re-run with: <fg=yellow>--execute</>');
            return self::SUCCESS;
        }

        // 4. Secure interactive password collection
        $password = $this->secret('Enter Super Admin password (min 12 chars, mixed case, number, symbol):');
        if (empty($password)) {
            $this->error('Password cannot be empty.');
            return self::FAILURE;
        }

        $confirmPassword = $this->secret('Confirm Super Admin password:');
        if ($password !== $confirmPassword) {
            $this->error('Password confirmation does not match.');
            return self::FAILURE;
        }

        // Validate password strength
        $passwordValidator = Validator::make(['password' => $password], [
            'password' => [
                'required',
                'string',
                Password::min(12)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ], [
            'password.min'     => 'Password must be at least 12 characters long.',
            'password.letters' => 'Password must contain at least one letter.',
            'password.mixed'   => 'Password must contain both uppercase and lowercase letters.',
            'password.numbers' => 'Password must contain at least one number.',
            'password.symbols' => 'Password must contain at least one special character / symbol.',
        ]);

        if ($passwordValidator->fails()) {
            $this->error('Password strength validation failed:');
            foreach ($passwordValidator->errors()->all() as $error) {
                $this->line("  - <fg=red>{$error}</>");
            }
            return self::FAILURE;
        }

        // 5. Database transaction to commit user and role
        try {
            $user = DB::transaction(function () use ($name, $email, $password, $role) {
                $user = User::create([
                    'name'      => $name,
                    'email'     => $email,
                    'password'  => Hash::make($password),
                    'school_id' => null,
                    'status'    => 'active',
                ]);

                $user->assignRole($role);

                if (function_exists('activity')) {
                    activity('platform')
                        ->performedOn($user)
                        ->causedBy($user)
                        ->withProperties([
                            'user_id'   => $user->id,
                            'name'      => $user->name,
                            'email'     => $user->email,
                            'role'      => 'super-admin',
                            'school_id' => null,
                            'ip'        => 'cli',
                        ])
                        ->log('Super Admin account provisioned via CLI');
                }

                return $user;
            });
        } catch (\Throwable $e) {
            $this->error('Transaction failed during Super Admin creation. All mutations were rolled back.');
            $this->line("Error: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('====================================================');
        $this->info('   SUPER ADMIN ACCOUNT PROVISIONED SUCCESSFULLY');
        $this->info('====================================================');
        $this->line("USER_ID={$user->id}");
        $this->line("NAME={$user->name}");
        $this->line("EMAIL={$user->email}");
        $this->line("ROLE=super-admin");
        $this->line("SCHOOL_ID=null");
        $this->line("STATUS=active");
        $this->newLine();

        return self::SUCCESS;
    }
}
