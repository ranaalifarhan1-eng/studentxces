<?php

namespace App\Console\Commands;

use App\Services\CommercialPackageProvisioner;
use Illuminate\Console\Command;

class DeprovisionCommercialPackagesCommand extends Command
{
    protected $signature = 'packages:deprovision-commercial
                            {--dry-run : Simulate deprovisioning without deleting records}
                            {--execute : Execute transactional deletion of unused commercial packages}';

    protected $description = 'Safely deprovision unused StudentXces commercial packages (Starter, Standard, Pro)';

    public function handle(CommercialPackageProvisioner $provisioner): int
    {
        $isExecute = (bool) $this->option('execute');

        if (! $isExecute) {
            $this->warn('Running in DRY-RUN simulation mode (zero database deletions). Pass --execute to delete packages.');
        }

        try {
            $results = $provisioner->deprovisionAll($isExecute);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $rows = [];
        foreach ($results as $slug => $status) {
            $rows[] = [$slug, $status];
        }

        $this->table(['Package Slug', 'Deprovision Status'], $rows);

        if ($isExecute) {
            $this->info('Commercial packages deprovisioned successfully.');
        } else {
            $this->info('Dry-run complete. Zero records were deleted.');
        }

        return self::SUCCESS;
    }
}