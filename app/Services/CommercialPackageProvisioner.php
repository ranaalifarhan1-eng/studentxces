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
     * Provision all 3 canonical commercial packages with their multi-term pricing and modules.
     *
     * @return array<string, Package>
     */
    public function provisionAll(): array
    {
        return DB::transaction(function () {
            $created = [];

            foreach (self::PACKAGES as $key => $config) {
                $package = Package::withTrashed()->where('slug', $config['slug'])->first();

                if (! $package) {
                    $package = Package::create([
                        'name'          => $config['name'],
                        'slug'          => $config['slug'],
                        'description'   => $config['description'],
                        'badge'         => $config['badge'],
                        'currency'      => $config['currency'],
                        'price_monthly' => $config['base_monthly'],
                        'price_yearly'  => $config['base_monthly'] * 12 * 0.90,
                        'max_students'  => $config['max_students'],
                        'max_staff'     => $config['max_staff'],
                        'storage_gb'    => $config['storage_gb'],
                        'is_active'     => $config['is_active'],
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
}