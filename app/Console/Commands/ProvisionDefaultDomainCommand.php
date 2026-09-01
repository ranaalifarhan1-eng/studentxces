<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\SchoolDomain;
use App\Services\DomainService;
use App\Services\HostnameNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProvisionDefaultDomainCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenancy:provision-default-domain
                            {--school= : Target specific school ID (required)}
                            {--dry-run : Simulate without making database modifications (default)}
                            {--execute : Write default domain record to database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely provision or preview the default platform domain for a tenant school';

    /**
     * Execute the console command.
     */
    public function handle(DomainService $domainService): int
    {
        $schoolId = $this->option('school');
        $isExecute = (bool) $this->option('execute');

        if (! $schoolId) {
            $this->error('The --school=<ID> option is required to prevent accidental bulk mutations.');
            return 1;
        }

        $school = School::find($schoolId);
        if (! $school) {
            $this->error("School with ID {$schoolId} was not found.");
            return 1;
        }

        $baseDomain = config('tenancy.tenant_base_domain', 'edusystem.store');
        $slug = $school->slug ?: Str::slug($school->name);
        $hostname = HostnameNormalizer::normalize("{$slug}.{$baseDomain}");

        $existing = SchoolDomain::where('school_id', $school->id)
            ->where('type', SchoolDomain::TYPE_DEFAULT)
            ->first();

        $this->info("=== DEFAULT DOMAIN PROVISIONING ===");
        $this->line("School ID:      {$school->id}");
        $this->line("School Name:    {$school->name}");
        $this->line("Target Host:    {$hostname}");
        $this->line("Existing Host:  " . ($existing ? $existing->hostname : 'None'));
        $this->line("Status Target:  verified (ssl_status: pending)");
        $this->line("Mode:           " . ($isExecute ? '<fg=yellow>EXECUTE (WRITE)</>' : '<fg=green>DRY-RUN (SIMULATION)</>'));

        if (! $isExecute) {
            $this->info("DRY-RUN completed. No database changes were made. Use --execute to apply.");
            return 0;
        }

        $domain = $domainService->generateDefaultDomain($school);

        $this->info("Default domain provisioned successfully: ID #{$domain->id} [{$domain->hostname}] (status: {$domain->status}, ssl: {$domain->ssl_status})");
        return 0;
    }
}
