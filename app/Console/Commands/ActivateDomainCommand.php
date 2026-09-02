<?php

namespace App\Console\Commands;

use App\Models\SchoolDomain;
use App\Services\DomainService;
use App\Services\HostnameNormalizer;
use App\Services\HttpsProbeInterface;
use Illuminate\Console\Command;

class ActivateDomainCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenancy:activate-domain
                            {--hostname= : The hostname to activate (required)}
                            {--dry-run : Simulate without writing changes (default)}
                            {--execute : Write activation changes to database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely verify DNS and HTTPS/TLS to activate a production tenant domain';

    /**
     * Execute the console command.
     */
    public function handle(DomainService $domainService, HttpsProbeInterface $httpsProbe): int
    {
        $rawHostname = $this->option('hostname');
        $isExecute   = (bool) $this->option('execute');

        if (! $rawHostname) {
            $this->error('The --hostname=<FQDN> option is required.');
            return 1;
        }

        try {
            $hostname = HostnameNormalizer::normalize($rawHostname);
        } catch (\InvalidArgumentException $e) {
            $this->error("Invalid hostname: {$e->getMessage()}");
            return 1;
        }

        $domain = SchoolDomain::with('school')->where('hostname', $hostname)->first();
        if (! $domain) {
            $this->error("Domain '{$hostname}' was not found in the platform database.");
            return 1;
        }

        $this->info("=== PRODUCTION DOMAIN ACTIVATION PRE-FLIGHT ===");
        $this->line("Hostname:         {$domain->hostname}");
        $this->line("Domain Type:      {$domain->type}");
        $this->line("Current Status:   {$domain->status}");
        $this->line("Current SSL:      {$domain->ssl_status}");
        $this->line("School ID:        {$domain->school_id}");
        $this->line("School Name:      " . ($domain->school?->name ?? 'None'));
        $this->line("School Status:    " . ($domain->school?->status ?? 'None'));
        $this->line("Mode:             " . ($isExecute ? '<fg=yellow>EXECUTE (WRITE)</>' : '<fg=green>DRY-RUN (SIMULATION)</>'));
        $this->newLine();

        // 1. Status Check
        if (! in_array($domain->status, [SchoolDomain::STATUS_VERIFIED, SchoolDomain::STATUS_ACTIVE], true)) {
            $this->error("Precondition Failed: Domain must be in 'verified' or 'active' state. Current status: '{$domain->status}'.");
            return 1;
        }

        // 2. School Status Check
        if (! $domain->school || $domain->school->status !== 'active') {
            $this->error("Precondition Failed: Owning school is not active or does not exist.");
            return 1;
        }

        // 3. DNS Re-Check
        $this->line("Checking DNS configuration for '{$domain->hostname}'...");
        if ($domain->isCustom()) {
            $dnsOk = $domainService->verifyDomain($domain);
            if (! $dnsOk) {
                $this->error("DNS Check Failed: Hostname does not resolve to configured CNAME target or match TXT challenge.");
                return 1;
            }
            $this->info("✓ DNS verification confirmed.");
        } else {
            $this->info("✓ Default platform domain (internal platform DNS).");
        }

        // 4. HTTPS / TLS Probe Check
        $this->line("Probing HTTPS / TLS handshake on 'https://{$domain->hostname}'...");
        $tlsResult = $httpsProbe->probe($domain->hostname);
        if (! $tlsResult['success']) {
            $this->error("TLS Probe Failed: {$tlsResult['message']}");
            return 1;
        }
        $this->info("✓ TLS handshake successful ({$tlsResult['message']}).");

        if (! $isExecute) {
            $this->newLine();
            $this->info("DRY-RUN PASSED. All DNS and HTTPS prerequisites met. Use --execute to activate production traffic.");
            return 0;
        }

        // 5. Execute Activation
        $result = $domainService->activateDomain($domain, $httpsProbe);
        if (! $result['success']) {
            $this->error("Activation Failed: {$result['message']}");
            return 1;
        }

        $this->newLine();
        $this->info("SUCCESS: Domain [{$domain->hostname}] is now ACTIVE (ssl_status: active) and serving production traffic.");
        return 0;
    }
}
