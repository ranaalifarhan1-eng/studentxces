<?php

namespace App\Console\Commands;

use App\Models\DomainProvisioningRequest;
use App\Services\DomainProvisioningService;
use Illuminate\Console\Command;

class MarkDomainProvisioningFailedCommand extends Command
{
    protected $signature = 'tenancy:provisioning:mark-failed
                            {--request-id= : The provisioning request ID (required)}
                            {--domain-id= : The school domain ID (required)}
                            {--error-code=activation_failed : The safe error code}';

    protected $description = 'Mark a domain provisioning request as failed with a sanitized error code';

    public function handle(DomainProvisioningService $provisioningService): int
    {
        $requestId = (int) $this->option('request-id');
        $domainId  = (int) $this->option('domain-id');
        $errorCode = (string) $this->option('error-code');

        if ($requestId <= 0 || $domainId <= 0) {
            $this->error('Both --request-id=<int> and --domain-id=<int> are required positive integers.');
            return 1;
        }

        $result = $provisioningService->markFailed($requestId, $domainId, $errorCode);

        if (! $result['success']) {
            $this->error("Failed: {$result['message']}");
            return 1;
        }

        $this->info("SUCCESS: {$result['message']}");
        return 0;
    }
}
