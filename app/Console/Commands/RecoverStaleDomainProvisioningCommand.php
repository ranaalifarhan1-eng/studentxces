<?php

namespace App\Console\Commands;

use App\Services\DomainProvisioningService;
use Illuminate\Console\Command;

class RecoverStaleDomainProvisioningCommand extends Command
{
    protected $signature = 'tenancy:provisioning:recover-stale
                            {--timeout= : Custom timeout in minutes}';

    protected $description = 'Recover any stale running domain provisioning requests and transition them to failed';

    public function handle(DomainProvisioningService $provisioningService): int
    {
        $timeoutOption = $this->option('timeout');
        $timeout = $timeoutOption !== null ? (int) $timeoutOption : null;

        $recoveredCount = $provisioningService->recoverStaleRequests($timeout);

        if ($recoveredCount > 0) {
            $this->info("Recovered {$recoveredCount} stale domain provisioning request(s).");
        } else {
            $this->line("Zero stale requests found.");
        }

        return 0;
    }
}
