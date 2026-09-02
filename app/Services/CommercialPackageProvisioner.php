<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageModule;
use Illuminate\Support\Facades\DB;

class CommercialPackageProvisioner
{
    public const PACKAGES = [
        'starter' => [
            'name'         => 'Starter',
            'slug'         => 'starter',
            'description'  => 'Essential school management for growing schools.',
            'base_monthly' => 3000.00,
            'max_students' => 300,
            'max_staff'    => 25,
            'storage_gb'   => 5,
            'is_active'    => true,
            'badge'        => null,
            'currency'     => 'PKR',
            'modules'      => [
                'students', 'staff', 'attendance', 'timetable', 'exams',
                'fees', 'homework', 'communication', 'reports',
            ],
        ],
        'standard' => [
            'name'         => 'Standard',
            'slug'         => 'standard',
            'description'  => 'Comprehensive operations suite for established institutions.',
            'base_monthly' => 5000.00,
            'max_students' => 800,
            'max_staff'    => 75,
            'storage_gb'   => 20,
            'is_active'    => true,
            'badge'        => 'Most Popular',
            'currency'     => 'PKR',
            'modules'      => [
                'students', 'staff', 'attendance', 'timetable', 'exams',
                'fees', 'homework', 'communication', 'reports', 'hr',
                'library', 'transport', 'inventory',
            ],
        ],
        'pro' => [
            'name'         => 'Pro',
            'slug'         => 'pro',
            'description'  => 'Full-scale enterprise management with all modules & priority support.',
            'base_monthly' => 8000.00,
            'max_students' => 2000,
            'max_staff'    => 200,
            'storage_gb'   => 50,
            'is_active'    => true,
            'badge'        => null,
            'currency'     => 'PKR',
            'modules'      => [
                'students', 'staff', 'attendance', 'timetable', 'exams',
                'fees', 'library', 'transport', 'hostel', 'inventory',
                'homework', 'communication', 'reports', 'hr',
            ],
        ],
    ];

    public function __construct(
        protected CommercialPricingService $pricingService
    ) {}

    /**
     * Dry run simulation: Inspect what would be created or updated without making any DB writes.
     *
     * @return array<string, array>
     */
    public function previewProvision(): array
    {
        $preview = [];

        foreach (self::PACKAGES as $key => $config) {
            $package = Package::withTrashed()->where('slug', $config['slug'])->first();
            $action = 'WOULD_CREATE';
            $hasSubscriptions = false;

            if ($package) {
                $action = $package->trashed() ? 'WOULD_RESTORE_AND_UPDATE' : 'WOULD_UPDATE';
                $hasSubscriptions = $package->subscriptions()->exists();
            }

            $terms = $this->pricingService->calculateAllTerms($config['base_monthly'], $config['currency']);

            $preview[$key] = [
                'name'              => $config['name'],
                'slug'              => $config['slug'],
                'action'            => $action,
                'has_subscriptions' => $hasSubscriptions,
                'badge'             => $config['badge'],
                'currency'          => $config['currency'],
                'base_monthly'      => $config['base_monthly'],
                'terms'             => $terms,
                'max_students'      => $config['max_students'],
                'max_staff'         => $config['max_staff'],
                'storage_gb'        => $config['storage_gb'],
                'modules'           => $config['modules'],
            ];
        }

        return $preview;
    }

    /**
     * Provision all 3 canonical commercial packages with their multi-term pricing and modules.
     *
     * @param bool $forceSafeUpdate If true, permits updating configuration even if subscriptions exist without altering subscription snapshot integrity
     * @return array<string, Package>
     */
    public function provisionAll(bool $forceSafeUpdate = false): array
    {
        return DB::transaction(function () use ($forceSafeUpdate) {
            $created = [];

            foreach (self::PACKAGES as $key => $config) {
                $package = Package::withTrashed()->where('slug', $config['slug'])->first();

                if ($package && $package->subscriptions()->exists() && ! $forceSafeUpdate) {
                    throw new \RuntimeException(
                        "Safety check failed: Package '{$package->name}' (slug: {$package->slug}) has live subscriptions. Overwriting modules or prices automatically is blocked. Pass explicit force flag if intended."
                    );
                }

                if (! $package) {
                    $package = Package::create([
                        'name'          => $config['name'],
                        'slug'          => $config['slug'],
                        'description'   => $config['description'],
                        'badge'         => $config['badge'],
                        'currency'      => $config['currency'],
                        'price_monthly' => $config['base_monthly'],
                        'price_yearly'  => round($config['base_monthly'] * 12 * 0.90, 2),
                        'max_students'  => $config['max_students'],
                        'max_staff'     => $config['max_staff'],
                        'storage_gb'    => $config['storage_gb'],
                        'is_active'     => $config['is_active'],
                        'is_internal'   => false,
                    ]);
                } else {
                    if ($package->trashed()) {
                        $package->restore();
                    }
                    $package->update([
                        'name'         => $config['name'],
                        'description'  => $config['description'],
                        'badge'        => $config['badge'],
                        'currency'     => $config['currency'],
                        'max_students' => $config['max_students'],
                        'max_staff'    => $config['max_staff'],
                        'storage_gb'   => $config['storage_gb'],
                        'is_active'    => $config['is_active'],
                        'is_internal'  => false,
                    ]);
                }

                // Sync modules
                $package->modules()->delete();
                foreach ($config['modules'] as $slug) {
                    PackageModule::create([
                        'package_id'  => $package->id,
                        'module_slug' => $slug,
                    ]);
                }

                // Sync 3, 6, 12-month pricing terms
                $this->pricingService->syncPackagePrices($package, $config['base_monthly'], $config['currency']);

                $created[$key] = $package->fresh(['prices', 'modules']);
            }

            return $created;
        });
    }

    /**
     * Preview or execute deprovisioning of commercial packages (Starter, Standard, Pro).
     * Strictly refuses if any commercial package has subscriptions.
     * Strictly refuses to touch internal packages (Legacy All Access).
     *
     * @param bool $execute
     * @return array<string, string>
     */
    public function deprovisionAll(bool $execute = false): array
    {
        $targetSlugs = array_keys(self::PACKAGES);
        $results = [];

        foreach ($targetSlugs as $slug) {
            $package = Package::withTrashed()->where('slug', $slug)->first();

            if (! $package) {
                $results[$slug] = 'NOT_FOUND';
                continue;
            }

            if ($package->is_internal) {
                throw new \RuntimeException("Safety violation: Refusing to deprovision internal package '{$package->slug}'.");
            }

            if ($package->subscriptions()->exists()) {
                throw new \RuntimeException("Safety violation: Cannot deprovision package '{$package->name}' because it has active subscriptions.");
            }

            if ($execute) {
                DB::transaction(function () use ($package) {
                    $package->prices()->delete();
                    $package->modules()->delete();
                    $package->forceDelete();
                });
                $results[$slug] = 'DELETED';
            } else {
                $results[$slug] = 'WOULD_DELETE';
            }
        }

        return $results;
    }
}