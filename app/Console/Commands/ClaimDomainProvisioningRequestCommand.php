<?php

namespace App\Console\Commands;

use App\Services\DomainProvisioningService;
use Illuminate\Console\Command;

class ClaimDomainProvisioningRequestCommand extends Command
{
    protected $signature = 'tenancy:provisioning:claim-next
                            {--json : Output machine-readable JSON}';

    protected $description = 'Atomically claim the next queued domain provisioning request for host runner';

    public function handle(DomainProvisioningService $provisioningService): int
    {
        $claimed = $provisioningService->claimNextRequest();

        if (! $claimed || ! $claimed->schoolDomain) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'no_work']));
            } else {
                $this->line('NO_WORK');
            }
            return 0;
        }

        $domain = $claimed->schoolDomain;
        $payload = [
            'request_id' => $claimed->id,
            'domain_id'  => $domain->id,
            'school_id'  => $domain->school_id,
            'hostname'   => $domain->hostname,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("CLAIMED request #{$claimed->id} for domain [{$domain->hostname}] (ID: {$domain->id}, School: {$domain->school_id})");
        }

        return 0;
    }
}
