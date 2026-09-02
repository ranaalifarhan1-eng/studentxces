<?php

namespace App\Services;

use App\DTOs\EntitlementResult;
use App\Models\School;
use App\Models\SchoolModule;
use App\Models\SchoolSubscription;
use App\Models\User;
use Carbon\Carbon;

class SchoolEntitlementResolver
{
    /**
     * Request-scoped memoization cache for subscription candidates.
     *
     * @var array<int, \Illuminate\Database\Eloquent\Collection>
     */
    protected array $memoizedCandidates = [];

    /**
     * Request-scoped memoization cache for effective modules.
     *
     * @var array<int, array<string, bool>>
     */
    protected array $memoizedEffectiveModules = [];

    /**
     * Clear request-scoped cache (useful for testing).
     */
    public function clearCache(): void
    {
        $this->memoizedCandidates = [];
        $this->memoizedEffectiveModules = [];
    }

    /**
     * Resolve the single active/valid subscription for a school.
     * Returns null if no valid subscription exists or if multiple ambiguous valid subscriptions exist.
     */
    public function resolveSubscription(int|string|School $school): ?SchoolSubscription
    {
        $schoolId = $this->resolveSchoolId($school);
        if (! $schoolId) {
            return null;
        }

        $candidates = $this->getValidSubscriptionCandidates($schoolId);

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // 0 or >1 (ambiguous) candidates return null
        return null;
    }

    /**
     * Evaluates the subscription status for a school and returns a structured EntitlementResult.
     */
    public function checkSubscription(int|string|School $school): EntitlementResult
    {
        $schoolModel = $this->resolveSchoolModel($school);
        if (! $schoolModel) {
            return EntitlementResult::deny(EntitlementResult::NO_ACTIVE_SUBSCRIPTION, null, null, null, null, 'School not found.');
        }

        $schoolId = $schoolModel->id;

        if ($schoolModel->status !== 'active') {
            return EntitlementResult::deny(EntitlementResult::SUBSCRIPTION_SUSPENDED, $schoolId, null, null, null, 'School account is not active.');
        }

        $candidates = $this->getValidSubscriptionCandidates($schoolId);

        if ($candidates->count() > 1) {
            return EntitlementResult::deny(
                EntitlementResult::AMBIGUOUS_ACTIVE_SUBSCRIPTIONS,
                $schoolId,
                null,
                null,
                null,
                "Found {$candidates->count()} conflicting active subscriptions for school."
            );
        }

        if ($candidates->count() === 1) {
            $sub = $candidates->first();
            return EntitlementResult::allow(
                EntitlementResult::ALLOWED,
                $schoolId,
                null,
                $sub->id,
                $sub->package_id,
                'Valid active subscription found.'
            );
        }

        // 0 valid candidates: diagnose specific reason from existing rows
        $allSubs = SchoolSubscription::where('school_id', $schoolId)->latest('id')->get();
        $today = Carbon::today()->toDateString();

        if ($allSubs->isEmpty()) {
            return EntitlementResult::deny(EntitlementResult::NO_ACTIVE_SUBSCRIPTION, $schoolId, null, null, null, 'No subscription records found.');
        }

        $latest = $allSubs->first();

        if ($latest->status === 'suspended') {
            return EntitlementResult::deny(EntitlementResult::SUBSCRIPTION_SUSPENDED, $schoolId, null, $latest->id, $latest->package_id, 'Subscription is suspended.');
        }

        // Trial subscriptions must have non-null trial_ends_at
        if ($latest->status === 'trial' || $latest->is_trial) {
            if (empty($latest->trial_ends_at)) {
                return EntitlementResult::deny(
                    EntitlementResult::INVALID_TRIAL_CONFIGURATION,
                    $schoolId,
                    null,
                    $latest->id,
                    $latest->package_id,
                    'Trial subscription is invalid: trial_ends_at date is missing (null).'
                );
            }

            if ($latest->trial_ends_at->toDateString() < $today) {
                return EntitlementResult::deny(
                    EntitlementResult::TRIAL_EXPIRED,
                    $schoolId,
                    null,
                    $latest->id,
                    $latest->package_id,
                    'Trial period has expired.'
                );
            }
        }

        if ($latest->end_date && $latest->end_date->toDateString() < $today) {
            return EntitlementResult::deny(EntitlementResult::SUBSCRIPTION_EXPIRED, $schoolId, null, $latest->id, $latest->package_id, 'Subscription has expired.');
        }

        if ($latest->start_date && $latest->start_date->toDateString() > $today) {
            return EntitlementResult::deny(EntitlementResult::SUBSCRIPTION_NOT_STARTED, $schoolId, null, $latest->id, $latest->package_id, 'Subscription has not started yet.');
        }

        return EntitlementResult::deny(EntitlementResult::NO_ACTIVE_SUBSCRIPTION, $schoolId, null, $latest->id, $latest->package_id, 'No valid subscription active today.');
    }

    /**
     * Evaluates entitlement for a specific module on a school.
     */
    public function checkModule(int|string|School $school, string $moduleSlug): EntitlementResult
    {
        $canonicalModules = config('modules.canonical', []);
        $coreModules      = config('modules.core', ['dashboard', 'settings', 'integrations', 'admins']);

        // Validate module slug against canonical and core registry
        if (! in_array($moduleSlug, $canonicalModules, true) && ! in_array($moduleSlug, $coreModules, true)) {
            throw new \InvalidArgumentException("Unknown or invalid module '{$moduleSlug}'. Must be defined in config('modules.canonical') or config('modules.core').");
        }

        // 1. Core / Unconditional features bypass
        if (in_array($moduleSlug, $coreModules, true)) {
            $schoolId = $this->resolveSchoolId($school);
            return EntitlementResult::allow(EntitlementResult::CORE_FEATURE, $schoolId, $moduleSlug, null, null, 'Core feature is unconditionally accessible.');
        }

        // 2. Validate subscription first (Strict Rule: No subscription = No paid modules, even with overrides)
        $subResult = $this->checkSubscription($school);
        if (! $subResult->isEntitled) {
            return EntitlementResult::deny(
                $subResult->reason,
                $subResult->schoolId,
                $moduleSlug,
                $subResult->subscriptionId,
                $subResult->packageId,
                "Module '{$moduleSlug}' denied because school has no valid subscription ({$subResult->reason})."
            );
        }

        $schoolId = $subResult->schoolId;
        $subscription = $this->resolveSubscription($school);

        // 3. Check SchoolModule explicit override
        $override = SchoolModule::where('school_id', $schoolId)
            ->where('module_slug', $moduleSlug)
            ->first();

        if ($override !== null) {
            if ($override->is_enabled) {
                return EntitlementResult::allow(
                    EntitlementResult::MODULE_ENABLED_BY_OVERRIDE,
                    $schoolId,
                    $moduleSlug,
                    $subscription?->id,
                    $subscription?->package_id,
                    "Module '{$moduleSlug}' explicitly enabled by school override."
                );
            }

            return EntitlementResult::deny(
                EntitlementResult::MODULE_DISABLED_BY_OVERRIDE,
                $schoolId,
                $moduleSlug,
                $subscription?->id,
                $subscription?->package_id,
                "Module '{$moduleSlug}' explicitly disabled by school override."
            );
        }

        // 4. Check Package Modules
        if (! $subscription || ! $subscription->package) {
            return EntitlementResult::deny(
                EntitlementResult::MODULE_NOT_IN_PACKAGE,
                $schoolId,
                $moduleSlug,
                $subscription?->id,
                $subscription?->package_id,
                "Module '{$moduleSlug}' denied: subscription has no associated package."
            );
        }

        $packageModules = $subscription->package->modules->pluck('module_slug')->toArray();

        if (in_array($moduleSlug, $packageModules, true)) {
            return EntitlementResult::allow(
                EntitlementResult::ALLOWED,
                $schoolId,
                $moduleSlug,
                $subscription->id,
                $subscription->package_id,
                "Module '{$moduleSlug}' granted by active package '{$subscription->package->name}'."
            );
        }

        return EntitlementResult::deny(
            EntitlementResult::MODULE_NOT_IN_PACKAGE,
            $schoolId,
            $moduleSlug,
            $subscription->id,
            $subscription->package_id,
            "Module '{$moduleSlug}' is not included in package '{$subscription->package->name}'."
        );
    }

    /**
     * Evaluates access for a specific user and school to a module.
     * Super Admin role receives global platform bypass.
     */
    public function canAccessModule(?User $user, int|string|School|null $school, string $moduleSlug): EntitlementResult
    {
        $canonicalModules = config('modules.canonical', []);
        $coreModules      = config('modules.core', ['dashboard', 'settings', 'integrations', 'admins']);

        // Validate module slug against canonical and core registry
        if (! in_array($moduleSlug, $canonicalModules, true) && ! in_array($moduleSlug, $coreModules, true)) {
            throw new \InvalidArgumentException("Unknown or invalid module '{$moduleSlug}'. Must be defined in config('modules.canonical') or config('modules.core').");
        }

        // 1. Super Admin bypass
        if ($user && method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
            $schoolId = $school ? $this->resolveSchoolId($school) : null;
            return EntitlementResult::allow(
                EntitlementResult::SUPER_ADMIN_BYPASS,
                $schoolId,
                $moduleSlug,
                null,
                null,
                'Super Admin has unrestricted global access.'
            );
        }

        if (! $school) {
            return EntitlementResult::deny(
                EntitlementResult::NO_ACTIVE_SUBSCRIPTION,
                null,
                $moduleSlug,
                null,
                null,
                'No tenant school context available.'
            );
        }

        return $this->checkModule($school, $moduleSlug);
    }

    /**
     * Check if a module is enabled for a school (boolean convenience helper).
     */
    public function isModuleEnabled(int|string|School $school, string $moduleSlug): bool
    {
        return $this->checkModule($school, $moduleSlug)->isEntitled;
    }

    /**
     * Check if a school has an active valid subscription (boolean convenience helper).
     */
    public function hasActiveSubscription(int|string|School $school): bool
    {
        return $this->checkSubscription($school)->isEntitled;
    }

    /**
     * Returns an associative map of all 14 canonical modules with their effective boolean enablement status.
     */
    public function getEffectiveModules(int|string|School $school): array
    {
        $schoolId = $this->resolveSchoolId($school);
        if ($schoolId && isset($this->memoizedEffectiveModules[$schoolId])) {
            return $this->memoizedEffectiveModules[$schoolId];
        }

        $canonical = config('modules.canonical', [
            'students', 'staff', 'attendance', 'timetable', 'exams',
            'fees', 'library', 'transport', 'hostel', 'inventory',
            'homework', 'communication', 'reports', 'hr',
        ]);

        $subResult = $this->checkSubscription($school);
        $resolvedSchoolId  = $subResult->schoolId;

        $effective = [];

        // If no valid subscription, all paid modules are false
        if (! $subResult->isEntitled || ! $resolvedSchoolId) {
            foreach ($canonical as $slug) {
                $effective[$slug] = false;
            }
            if ($schoolId) {
                $this->memoizedEffectiveModules[$schoolId] = $effective;
            }
            return $effective;
        }

        $subscription   = $this->resolveSubscription($school);
        $packageModules = $subscription?->package?->modules->pluck('module_slug')->toArray() ?? [];
        $overrides      = SchoolModule::where('school_id', $resolvedSchoolId)->pluck('is_enabled', 'module_slug')->toArray();

        foreach ($canonical as $slug) {
            if (isset($overrides[$slug])) {
                $effective[$slug] = (bool) $overrides[$slug];
            } else {
                $effective[$slug] = in_array($slug, $packageModules, true);
            }
        }

        if ($schoolId) {
            $this->memoizedEffectiveModules[$schoolId] = $effective;
        }

        return $effective;
    }

    /**
     * Query all valid subscription candidates for a school on current date.
     * Trials require non-null trial_ends_at.
     */
    protected function getValidSubscriptionCandidates(int $schoolId)
    {
        if (isset($this->memoizedCandidates[$schoolId])) {
            return $this->memoizedCandidates[$schoolId];
        }

        $today = Carbon::today()->toDateString();

        return $this->memoizedCandidates[$schoolId] = SchoolSubscription::with('package.modules')
            ->where('school_id', $schoolId)
            ->where(function ($q) use ($today) {
                // Paid active window
                $q->where(function ($subQ) use ($today) {
                    $subQ->where('status', 'active')
                         ->where('start_date', '<=', $today)
                         ->where('end_date', '>=', $today);
                })
                // OR Trial window (trial_ends_at MUST be non-null and >= today)
                ->orWhere(function ($subQ) use ($today) {
                    $subQ->where('status', 'trial')
                         ->where('is_trial', true)
                         ->where('start_date', '<=', $today)
                         ->whereNotNull('trial_ends_at')
                         ->where('trial_ends_at', '>=', $today);
                });
            })
            ->get();
    }

    protected function resolveSchoolId(int|string|School|null $school): ?int
    {
        if ($school instanceof School) {
            return $school->id;
        }
        return $school ? (int) $school : null;
    }

    protected function resolveSchoolModel(int|string|School|null $school): ?School
    {
        if ($school instanceof School) {
            return $school;
        }
        if ($school) {
            return School::find((int) $school);
        }
        return null;
    }
}
