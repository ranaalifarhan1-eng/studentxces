<?php

namespace App\Console\Commands;

use App\Services\TenantOnboardingService;
use Illuminate\Console\Command;

class OnboardTenantCommand extends Command
{
    protected $signature = 'tenant:onboard
        {--name= : School institutional name}
        {--slug= : Unique school slug (auto-generated from name if omitted)}
        {--email= : School contact email}
        {--phone= : School contact phone}
        {--address= : School physical address}
        {--city= : School city (default: Lahore)}
        {--state= : School state/province (default: Punjab)}
        {--country= : 2-letter country code (default: PK)}
        {--timezone= : Timezone identifier (default: Asia/Karachi)}
        {--currency= : 3-letter currency code (default: PKR)}
        {--language= : Language code (default: en)}
        {--admin-name= : Initial School Admin full name}
        {--admin-email= : Initial School Admin login email}
        {--admin-password= : Initial School Admin password (min 8 chars)}
        {--academic-year-name= : Initial academic year name (e.g. 2026-2027)}
        {--academic-start= : Academic year start date (YYYY-MM-DD)}
        {--academic-end= : Academic year end date (YYYY-MM-DD)}
        {--execute : Commit onboarding mutations to the database}
        {--dry-run : Perform validation and simulation without database mutations}';

    protected $description = 'Safely and transactionally onboard a new tenant foundation (School, School Admin, Academic Year)';

    public function handle(TenantOnboardingService $service): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $execute = false;
        }

        $input = [
            'name'               => $this->option('name'),
            'slug'               => $this->option('slug'),
            'email'              => $this->option('email'),
            'phone'              => $this->option('phone'),
            'address'            => $this->option('address'),
            'city'               => $this->option('city'),
            'state'              => $this->option('state'),
            'country'            => $this->option('country'),
            'timezone'           => $this->option('timezone'),
            'currency'           => $this->option('currency'),
            'language'           => $this->option('language'),
            'admin_name'         => $this->option('admin-name'),
            'admin_email'        => $this->option('admin-email'),
            'admin_password'     => $this->option('admin-password'),
            'academic_year_name' => $this->option('academic-year-name'),
            'academic_start'     => $this->option('academic-start'),
            'academic_end'       => $this->option('academic-end'),
        ];

        $this->info('====================================================');
        $this->info('   STUDENTXCES TENANT ONBOARDING ENGINE');
        $this->info('====================================================');
        $this->line('Mode: ' . ($execute ? '<fg=yellow;options=bold>EXECUTE (MUTATION)</>' : '<fg=cyan;options=bold>DRY-RUN / SIMULATION</>'));

        $prepared = $service->prepareData($input);

        // Render configuration summary with masked password
        $this->table(['Parameter', 'Configured Value'], [
            ['School Name', $prepared['name'] ?: '<fg=red>[MISSING]</>'],
            ['School Slug', $prepared['slug'] ?: '<fg=red>[MISSING]</>'],
            ['School Email', $prepared['email'] ?? '—'],
            ['School Phone', $prepared['phone'] ?? '—'],
            ['City / State', "{$prepared['city']}, {$prepared['state']}"],
            ['Country / Timezone', "{$prepared['country']} / {$prepared['timezone']}"],
            ['Currency / Language', "{$prepared['currency']} / {$prepared['language']}"],
            ['Admin Name', $prepared['admin_name'] ?: '<fg=red>[MISSING]</>'],
            ['Admin Email', $prepared['admin_email'] ?: '<fg=red>[MISSING]</>'],
            ['Admin Password Supplied', ! empty($prepared['admin_password']) ? '<fg=green>YES</>' : '<fg=red>NO</>'],
            ['Academic Year', $prepared['academic_year_name'] ?: '<fg=red>[MISSING]</>'],
            ['Academic Period', ($prepared['academic_start'] && $prepared['academic_end']) ? "{$prepared['academic_start']} to {$prepared['academic_end']}" : '<fg=red>[MISSING]</>'],
        ]);

        try {
            $result = $service->onboard($input, $execute);
        } catch (\Throwable $e) {
            $this->error("Onboarding transaction failed and rolled back: {$e->getMessage()}");
            return self::FAILURE;
        }

        if ($result['status'] === 'VALIDATION_FAILED') {
            $this->error('Onboarding validation failed:');
            foreach ($result['errors'] as $error) {
                $this->line("  - <fg=red>{$error}</>");
            }
            return self::FAILURE;
        }

        if ($result['status'] === 'DRY_RUN') {
            $this->newLine();
            $this->info('✓ Pre-validation PASSED.');
            $this->comment('Simulation complete. Zero database mutations were performed.');
            $this->line('To create this tenant foundation, re-run with: <fg=yellow>--execute</>');
            return self::SUCCESS;
        }

        if ($result['status'] === 'FOUNDATION_CREATED') {
            $this->newLine();
            $this->info('====================================================');
            $this->info('   TENANT FOUNDATION COMMITTED SUCCESSFULLY');
            $this->info('====================================================');
            $this->line("SCHOOL_ID={$result['school_id']}");
            $this->line("ADMIN_USER_ID={$result['admin_user_id']}");
            $this->line("ACADEMIC_YEAR_ID={$result['academic_year_id']}");
            $this->line("SCHOOL_NAME={$result['school_name']}");
            $this->line("SCHOOL_SLUG={$result['school_slug']}");
            $this->line("ADMIN_EMAIL={$result['admin_email']}");
            $this->newLine();
            $this->comment('Next Step: Review commercial entitlement via dry-run:');
            $this->line("<fg=cyan>{$result['next_step']}</>");
            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
