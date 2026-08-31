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
                            {--reconcile : Reconcile pending/incomplete journal manifests against database state}
                            {--execute : Required positive confirmation flag to perform real database mutations}
                            {--dry-run : Explicitly simulate execution with zero database writes}
                            {--start-date= : Subscription start date (YYYY-MM-DD)}
                            {--end-date= : Subscription end date (YYYY-MM-DD)}
                            {--duration-days=365 : Subscription duration in days}';

    protected $description = 'Provision internal legacy all-access entitlement subscriptions for legacy tenant schools.';

    public function handle(LegacyEntitlementProvisioner $provisioner): int
    {
        $schoolId    = $this->option('school');
        $allExisting = $this->option('all-existing');
        $reconcile   = (bool) $this->option('reconcile');
        $execute     = (bool) $this->option('execute');
        $dryRunOpt   = (bool) $this->option('dry-run');

        // Handle Reconcile Mode
        if ($reconcile) {
            $this->info('=== RECONCILING PENDING PROVISIONING MANIFESTS ===');
            $reconciled = $provisioner->reconcileAllManifests($schoolId ? (int) $schoolId : null);

            if (empty($reconciled)) {
                $this->info('No pending manifests found to reconcile.');
                return self::SUCCESS;
            }

            $rows = [];
            foreach ($reconciled as $item) {
                $res = $item['result'];
                $rows[] = [
                    'File'    => $item['file'],
                    'School'  => $res['school_id'] ?? 'N/A',
                    'Sub ID'  => $res['subscription_id'] ?? 'N/A',
                    'Status'  => $res['status'],
                    'Message' => $res['message'],
                ];
            }

            $this->table(['File', 'School', 'Sub ID', 'Status', 'Message'], $rows);
            $this->info('Reconciliation completed.');
            return self::SUCCESS;
        }

        // Target validation
        if (! $schoolId && ! $allExisting) {
            $this->error('No target specified. Use --school=<id>, --all-existing, or --reconcile.');
            $this->line('Safety Gate: Default invocation without explicit target performs zero actions.');
            return self::FAILURE;
        }

        // Safety Gate: Actual DB mutations require explicit --execute flag
        $isDryRun = $dryRunOpt || ! $execute;

        if ($isDryRun) {
            $this->warn('----------------------------------------------------------------------');
            if (! $execute && ! $dryRunOpt) {
                $this->warn(' [SIMULATION MODE] --execute flag was not provided. ZERO DATABASE WRITES.');
                $this->line(' To commit changes, re-run with the explicit --execute flag.');
            } else {
                $this->warn(' [DRY-RUN MODE ACTIVATED] ZERO DATABASE WRITES.');
            }
            $this->warn('----------------------------------------------------------------------');
        }

        // Validate date parameters early
        $options = [
            'dry_run'       => $isDryRun,
            'start_date'    => $this->option('start-date'),
            'end_date'      => $this->option('end-date'),
            'duration_days' => (int) $this->option('duration-days'),
        ];

        try {
            $provisioner->validateDates($options);
        } catch (\InvalidArgumentException $e) {
            $this->error("Validation Error: " . $e->getMessage());
            return self::FAILURE;
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

        $results = [];

        foreach ($schools as $school) {
            try {
                $res = $provisioner->provisionSchool($school, $options);
                $results[] = [
                    'School ID'   => $res['school_id'],
                    'School Name' => $res['school_name'],
                    'Status'      => $res['status'],
                    'Package'     => $res['package_name'] ?? $res['package_slug'] ?? 'N/A',
                    'Sub ID'      => $res['subscription_id'] ?? 'N/A',
                    'Message'     => $res['message'],
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'School ID'   => $school->id,
                    'School Name' => $school->name,
                    'Status'      => 'ERROR',
                    'Package'     => 'N/A',
                    'Sub ID'      => 'N/A',
                    'Message'     => $e->getMessage(),
                ];
            }
        }

        $this->table(['School ID', 'School Name', 'Status', 'Package', 'Sub ID', 'Message'], $results);

        if ($isDryRun) {
            $this->info('Simulation complete. No database changes were made.');
        } else {
            $this->info('Provisioning completed successfully.');
        }

        return self::SUCCESS;
    }
}
