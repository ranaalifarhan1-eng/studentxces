<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PackageModule;
use App\Services\CommercialPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    const AVAILABLE_MODULES = [
        'students', 'staff', 'attendance', 'timetable', 'exams',
        'fees', 'library', 'transport', 'hostel', 'inventory',
        'homework', 'communication', 'reports', 'hr',
    ];

    public function __construct(
        protected CommercialPricingService $pricingService
    ) {}

    public static function availableModules(): array
    {
        return config('modules.canonical', self::AVAILABLE_MODULES);
    }

    public function index(): Response
    {
        $packages = Package::withTrashed(false)
            ->withCount('subscriptions')
            ->with(['modules', 'prices' => fn ($q) => $q->orderBy('term_months', 'asc')])
            ->latest()
            ->get();

        return Inertia::render('SuperAdmin/Packages/Index', [
            'packages'         => $packages,
            'availableModules' => self::availableModules(),
            'canonicalTerms'   => CommercialPricingService::CANONICAL_TERMS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $availableModules = self::availableModules();
        $data = $request->validate([
            'name'               => 'required|string|max:100',
            'badge'              => 'nullable|string|max:50',
            'description'        => 'nullable|string|max:500',
            'base_monthly_price' => 'nullable|numeric|min:0',
            'price_monthly'      => 'nullable|numeric|min:0',
            'currency'           => 'nullable|string|max:10',
            'max_students'       => 'required|integer|min:0',
            'max_staff'          => 'required|integer|min:0',
            'storage_gb'         => 'required|integer|min:1',
            'is_active'          => 'boolean',
            'modules'            => 'array',
            'modules.*'          => 'string|in:' . implode(',', $availableModules),
        ]);

        $baseMonthly = (float) ($data['base_monthly_price'] ?? $data['price_monthly'] ?? 0);
        $currency    = $data['currency'] ?? 'PKR';

        $package = Package::create([
            'name'          => $data['name'],
            'slug'          => Str::slug($data['name']),
            'badge'         => $data['badge'] ?? null,
            'description'   => $data['description'] ?? null,
            'currency'      => $currency,
            'price_monthly' => $baseMonthly,
            'price_yearly'  => round($baseMonthly * 12 * 0.90, 2),
            'max_students'  => $data['max_students'],
            'max_staff'     => $data['max_staff'],
            'storage_gb'    => $data['storage_gb'],
            'is_active'     => $data['is_active'] ?? true,
        ]);

        foreach ($data['modules'] ?? [] as $slug) {
            PackageModule::create(['package_id' => $package->id, 'module_slug' => $slug]);
        }

        $this->pricingService->syncPackagePrices($package, $baseMonthly, $currency);

        return back()->with('success', 'Package created.');
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        if ($package->is_internal) {
            return back()->with('error', 'Cannot modify internal grandfathered package.');
        }

        $availableModules = self::availableModules();
        $data = $request->validate([
            'name'               => 'required|string|max:100',
            'badge'              => 'nullable|string|max:50',
            'description'        => 'nullable|string|max:500',
            'base_monthly_price' => 'nullable|numeric|min:0',
            'price_monthly'      => 'nullable|numeric|min:0',
            'currency'           => 'nullable|string|max:10',
            'max_students'       => 'required|integer|min:0',
            'max_staff'          => 'required|integer|min:0',
            'storage_gb'         => 'required|integer|min:1',
            'is_active'          => 'boolean',
            'modules'            => 'array',
            'modules.*'          => 'string|in:' . implode(',', $availableModules),
        ]);

        $baseMonthly = (float) ($data['base_monthly_price'] ?? $data['price_monthly'] ?? $package->price_monthly);
        $currency    = $data['currency'] ?? $package->currency ?? 'PKR';

        $package->update([
            'name'          => $data['name'],
            'badge'         => $data['badge'] ?? null,
            'description'   => $data['description'] ?? null,
            'currency'      => $currency,
            'price_monthly' => $baseMonthly,
            'price_yearly'  => round($baseMonthly * 12 * 0.90, 2),
            'max_students'  => $data['max_students'],
            'max_staff'     => $data['max_staff'],
            'storage_gb'    => $data['storage_gb'],
            'is_active'     => $data['is_active'] ?? true,
        ]);

        $package->modules()->delete();
        foreach ($data['modules'] ?? [] as $slug) {
            PackageModule::create(['package_id' => $package->id, 'module_slug' => $slug]);
        }

        $this->pricingService->syncPackagePrices($package, $baseMonthly, $currency);

        return back()->with('success', 'Package updated.');
    }

    public function destroy(Package $package): RedirectResponse
    {
        if ($package->is_internal) {
            return back()->with('error', 'Cannot delete internal grandfathered package.');
        }

        if ($package->subscriptions()->exists()) {
            return back()->with('error', 'Cannot delete a package with subscriptions.');
        }

        $package->delete();
        return back()->with('success', 'Package deleted.');
    }
}
