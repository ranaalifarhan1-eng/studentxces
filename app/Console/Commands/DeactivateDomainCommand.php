<?php

namespace App\Console\Commands;

use App\Models\SchoolDomain;
use App\Services\DomainService;
use App\Services\HostnameNormalizer;
use Illuminate\Console\Command;

class DeactivateDomainCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenancy:deactivate-domain
                            {--hostname= : The hostname to deactivate (required)}
                            {--dry-run : Simulate without writing changes (default)}
                            {--execute : Write deactivation changes to database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely disable a tenant domain and remove it from production traffic resolution';

    /**
     * Execute the console command.
     */
    public function handle(DomainService $domainService): int
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

        $this->info("=== DOMAIN DEACTIVATION PRE-FLIGHT ===");
        $this->line("Hostname:         {$domain->hostname}");
        $this->line("Domain Type:      {$domain->type}");
        $this->line("Current Status:   {$domain->status}");
        $this->line("Is Primary:       " . ($domain->is_primary ? 'YES' : 'NO'));
        $this->line("School ID:        {$domain->school_id}");
        $this->line("School Name:      " . ($domain->school?->name ?? 'None'));
        $this->line("Mode:             " . ($isExecute ? '<fg=yellow>EXECUTE (WRITE)</>' : '<fg=green>DRY-RUN (SIMULATION)</>'));
        $this->newLine();

        if (! $isExecute) {
            $this->info("DRY-RUN PASSED. Use --execute to deactivate the domain and disable traffic routing.");
            return 0;
        }

        $result = $domainService->deactivateDomain($domain);
        if (! $result['success']) {
            $this->error("Deactivation Failed: {$result['message']}");
            return 1;
        }

        $this->newLine();
        $this->info("SUCCESS: Domain [{$domain->hostname}] is now DISABLED. Traffic routing is immediately disabled.");
        return 0;
    }
}
