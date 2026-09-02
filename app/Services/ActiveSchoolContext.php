<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Support\Facades\Auth;

class ActiveSchoolContext
{
    public const SESSION_KEY = 'active_school_id';

    /**
     * Flag indicating whether execution is currently inside tenant operational scope (/school/*).
     */
    protected bool $inTenantScope = false;

    /**
     * Request-scoped memoization cache.
     */
    protected ?string $memoizedHost = null;
    protected ?School $memoizedHostSchool = null;

    /**
     * Reset request-scoped memoization cache (useful for testing and context transitions).
     */
    public function reset(): void
    {
        $this->memoizedHost = null;
        $this->memoizedHostSchool = null;
    }

    /**
     * Set explicit tenant operational scope flag.
     */
    public function setTenantOperationalScope(bool $active): void
    {
        $this->inTenantScope = $active;
    }

    /**
     * Check whether current execution is within tenant operational scope.
     */
    public function isTenantOperationalScope(): bool
    {
        if ($this->inTenantScope) {
            return true;
        }

        if (function_exists('request') && request() && request()->is('school/*')) {
            return true;
        }

        return false;
    }

    /**
     * Resolves the school directly from the request host header if mapped to an active tenant domain.
     */
    public function getHostResolvedSchool(): ?School
    {
        if (function_exists('request') && request()) {
            $host = request()->getHost();
            $cleanHost = strtolower(trim(explode(':', $host)[0]));
            if ($this->memoizedHost === $cleanHost) {
                return $this->memoizedHostSchool;
            }
            $this->memoizedHost = $cleanHost;
            return $this->memoizedHostSchool = app(TenantDomainResolver::class)->resolveFromHost($cleanHost);
        }

        return null;
    }

    /**
     * Resolves the active school ID for the current context.
     * - Host-resolved tenant domain: Strictly returns resolved school ID.
     * - Tenant users: Strictly returns auth()->user()->school_id.
     * - Super Admin: Returns validated session('active_school_id') when in tenant operational scope, or null.
     */
    public function getActiveSchoolId(): ?int
    {
        $hostSchool = $this->getHostResolvedSchool();
        if ($hostSchool) {
            return $hostSchool->id;
        }

        $user = Auth::user();
        if (! $user) {
            return null;
        }

        // Normal tenant users are strictly pinned to their own school_id
        if (! $user->hasRole('super-admin')) {
            return $user->school_id ? (int) $user->school_id : null;
        }

        // Super Admin: Only scope queries when in tenant operational scope
        if (! $this->isTenantOperationalScope()) {
            return null;
        }

        return $this->getSelectedSchoolId();
    }

    /**
     * Resolves the selected School ID from host or Super Admin session, validating it exists and is active.
     */
    public function getSelectedSchoolId(): ?int
    {
        $hostSchool = $this->getHostResolvedSchool();
        if ($hostSchool) {
            return $hostSchool->id;
        }

        $user = Auth::user();
        if (! $user) {
            return null;
        }

        if (! $user->hasRole('super-admin')) {
            return $user->school_id ? (int) $user->school_id : null;
        }

        $sessionId = session(self::SESSION_KEY);
        if (! $sessionId) {
            return null;
        }

        $schoolId = (int) $sessionId;

        // Verify school exists and is NOT soft-deleted
        $exists = School::where('id', $schoolId)->whereNull('deleted_at')->exists();
        if (! $exists) {
            session()->forget(self::SESSION_KEY);
            return null;
        }

        return $schoolId;
    }

    /**
     * Resolves the active School model.
     */
    public function getActiveSchool(): ?School
    {
        $hostSchool = $this->getHostResolvedSchool();
        if ($hostSchool) {
            return $hostSchool;
        }

        $schoolId = $this->getActiveSchoolId();
        return $schoolId ? School::find($schoolId) : null;
    }

    /**
     * Resolves the selected School model (available across both tenant and platform UI).
     */
    public function getSelectedSchool(): ?School
    {
        $hostSchool = $this->getHostResolvedSchool();
        if ($hostSchool) {
            return $hostSchool;
        }

        $schoolId = $this->getSelectedSchoolId();
        return $schoolId ? School::find($schoolId) : null;
    }

    /**
     * Set active school context for Super Admin.
     */
    public function setActiveSchoolId(int $schoolId): bool
    {
        $this->reset();

        $user = Auth::user();
        if (! $user || ! $user->hasRole('super-admin')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Only Super Admins can set active school context.');
        }

        $school = School::where('id', $schoolId)->whereNull('deleted_at')->first();
        if (! $school) {
            throw new \InvalidArgumentException("School with ID {$schoolId} does not exist or has been deleted.");
        }

        session([self::SESSION_KEY => $school->id]);

        if (function_exists('activity')) {
            activity()
                ->causedBy($user)
                ->performedOn($school)
                ->log("Super Admin selected school context [{$school->name}] (#{$school->id})");
        }

        return true;
    }

    /**
     * Clear active school context for Super Admin.
     */
    public function clearActiveSchoolId(): void
    {
        $this->reset();

        $user = Auth::user();
        if (! $user || ! $user->hasRole('super-admin')) {
            return;
        }

        $previousId = session(self::SESSION_KEY);
        session()->forget(self::SESSION_KEY);

        if ($previousId && function_exists('activity')) {
            activity()
                ->causedBy($user)
                ->log("Super Admin exited school context (previously School #{$previousId})");
        }
    }

    /**
     * Check if an active school context is established in tenant scope.
     */
    public function hasActiveSchool(): bool
    {
        return $this->getActiveSchoolId() !== null;
    }
}
