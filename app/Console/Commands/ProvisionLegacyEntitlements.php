<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\LegacyEntitlementProvisioner;
use Illuminate\Console\Command;

class ProvisionLegacyEntitlements extends Command
{
    protected $signature = 'entitlement:provision-legacy
                            {--school= : Target school ID}
                            {--all-existing : Target all existing active schools}
                            {--dry-run : Simulate execution with zero database writes}
                            {--start-date= : Subscription start date (YYYY-MM-DD)}
                            {--end-date= : Subscription end date (YYYY-MM-DD)}
                            {--duration-days=365 : Subscription duration in days}';

    protected $description = 'Provision internal legacy all-access entitlement subscriptions for legacy tenant schools.';

    public function handle(LegacyEntitlementProvisioner $provisioner): int
    {
        $schoolId    = $this->option('school');
        $allExisting = $this->option('all-existing');
        $dryRun      = (bool) $this->option('dry-run');

        if (! $schoolId && ! $allExisting) {
            $this->error('No target specified. Use --school=<id> or --all-existing.');
            $this->line('Safety Gate: Default invocation without explicit target performs zero actions.');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('----------------------------------------------------');
            $this->warn(' [DRY-RUN MODE ACTIVATED] ZERO DATABASE WRITES ');
            $this->warn('----------------------------------------------------');
        }

        $schools = collect();

        if ($schoolId) {
            $school = School::find((int) $schoolId);
            if (! $school) {
                $this->error("School with ID {$schoolId} not found.");
                return self::FAILURE;
            }
            $schools->push($school);
        } elseif ($allExisting) {
            $schools = School::orderBy('id')->get();
        }

        if ($schools->isEmpty()) {
            $this->info('No schools found matching criteria.');
            return self::SUCCESS;
        }

        $this->info("Processing legacy provisioning for {$schools->count()} school(s)...");

        $options = [
            'dry_run'       => $dryRun,
            'start_date'    => $this->option('start-date'),
            'end_date'      => $this->option('end-date'),
            'duration_days' => (int) $this->option('duration-days'),
        ];

        $results = [];

        foreach ($schools as $school) {
            $res = $provisioner->provisionSchool($school, $options);
            $results[] = [
                'School ID'   => $res['school_id'],
                'School Name' => $res['school_name'],
                'Status'      => $res['status'],
                'Package'     => $res['package_name'] ?? $res['package_slug'] ?? 'N/A',
                'Sub ID'      => $res['subscription_id'] ?? 'N/A',
                'Message'     => $res['message'],
            ];
        }

        $this->table(['School ID', 'School Name', 'Status', 'Package', 'Sub ID', 'Message'], $results);

        if ($dryRun) {
            $this->info('Dry-run complete. No changes were committed.');
        } else {
            $this->info('Provisioning completed.');
        }

        return self::SUCCESS;
    }
}
