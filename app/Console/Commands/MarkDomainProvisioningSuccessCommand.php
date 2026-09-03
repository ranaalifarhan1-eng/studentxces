<?php

namespace App\Console\Commands;

use App\Services\DomainProvisioningService;
use App\Services\HttpsProbeInterface;
use Illuminate\Console\Command;

class MarkDomainProvisioningSuccessCommand extends Command
{
    protected $signature = 'tenancy:provisioning:mark-success
                            {--request-id= : The provisioning request ID (required)}
                            {--domain-id= : The school domain ID (required)}';

    protected $description = 'Revalidate TLS and mark a domain provisioning request as succeeded';

    public function handle(DomainProvisioningService $provisioningService, HttpsProbeInterface $httpsProbe): int
    {
        $requestId = (int) $this->option('request-id');
        $domainId  = (int) $this->option('domain-id');

        if ($requestId <= 0 || $domainId <= 0) {
            $this->error('Both --request-id=<int> and --domain-id=<int> are required positive integers.');
            return 1;
        }

        $result = $provisioningService->markSuccess($requestId, $domainId, $httpsProbe);

        if (! $result['success']) {
            $this->error("Failed to mark success: {$result['message']}");
            return 1;
        }

        $this->info("SUCCESS: {$result['message']}");
        return 0;
    }
}
