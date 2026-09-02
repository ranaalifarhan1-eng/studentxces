<?php

namespace App\Console\Commands;

use App\Services\CommercialPackageProvisioner;
use Illuminate\Console\Command;

class ProvisionCommercialPackagesCommand extends Command
{
    protected $signature = 'packages:provision-commercial 
                            {--dry-run : Simulate package provisioning without writing changes}
                            {--execute : Execute transactional provisioning of commercial packages}
                            {--force : Permit updating packages that already have subscriptions}';

    protected $description = 'Provision canonical StudentXces commercial packages (Starter, Standard, Pro) with multi-term pricing';

    public function handle(CommercialPackageProvisioner $provisioner): int
    {
        $isExecute = (bool) $this->option('execute');
        $isForce   = (bool) $this->option('force');

        if (! $isExecute) {
            $this->warn('Running in DRY-RUN simulation mode (zero database writes). Pass --execute to write changes.');

            $preview = $provisioner->previewProvision();

            $rows = [];
            foreach ($preview as $p) {
                $terms = collect($p['terms'])->map(function ($t) {
                    return "{$t['term_months']}mo: {$t['currency']} " . number_format($t['total_price']);
                })->implode(' | ');

                $rows[] = [
                    $p['name'],
                    $p['slug'],
                    $p['action'],
                    $p['badge'] ?? '-',
                    "{$p['currency']} " . number_format($p['base_monthly']),
                    $terms,
                    count($p['modules']) . ' modules',
                    $p['has_subscriptions'] ? 'YES (Protected)' : 'NO',
                ];
            }

            $this->table(
                ['Name', 'Slug', 'Action', 'Badge', 'Base /mo', 'Terms', 'Modules', 'Live Subscriptions?'],
                $rows
            );

            $this->info('Dry-run complete. Zero records were created or modified.');
            return self::SUCCESS;
        }

        $this->info('Executing transactional commercial package provisioning...');

        try {
            $packages = $provisioner->provisionAll($isForce);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $rows = [];
        foreach ($packages as $pkg) {
            $terms = $pkg->prices->sortBy('term_months')->map(function ($p) {
                return "{$p->term_months}mo: {$p->currency} " . number_format($p->total_price);
            })->implode(' | ');

            $rows[] = [
                $pkg->id,
                $pkg->name,
                $pkg->slug,
                $pkg->badge ?? '-',
                "PKR " . number_format($pkg->price_monthly),
                $terms,
                $pkg->modules->count() . ' modules',
                $pkg->is_active ? 'Active' : 'Inactive',
            ];
        }

        $this->table(
            ['ID', 'Name', 'Slug', 'Badge', 'Base /mo', 'Terms', 'Modules', 'Status'],
            $rows
        );

        $this->info('Commercial packages provisioned successfully.');
        return self::SUCCESS;
    }
}