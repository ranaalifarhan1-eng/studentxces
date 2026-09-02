<?php

namespace App\Console\Commands;

use App\Services\CommercialPackageProvisioner;
use Illuminate\Console\Command;

class ProvisionCommercialPackagesCommand extends Command
{
    protected $signature = 'packages:provision-commercial';
    protected $description = 'Provision canonical StudentXces commercial packages (Starter, Standard, Pro) with multi-term pricing';

    public function handle(CommercialPackageProvisioner $provisioner): int
    {
        $this->info('Provisioning canonical commercial packages...');

        $packages = $provisioner->provisionAll();

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