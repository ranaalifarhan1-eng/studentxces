<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Support\Facades\Auth;

class ActiveSchoolContext
{
    public const SESSION_KEY = 'active_school_id';

    /**
     * Resolves the active school ID for the current authenticated user.
     * - Tenant users: Strictly returns auth()->user()->school_id.
     * - Super Admin: Returns validated session('active_school_id') or null.
     */
    public function getActiveSchoolId(): ?int
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        // Normal tenant users are strictly pinned to their own school_id
        if (! $user->hasRole('super-admin')) {
            return $user->school_id ? (int) $user->school_id : null;
        }

        // Super Admin: resolve from server session
        $sessionId = session(self::SESSION_KEY);
        if (! $sessionId) {
            return null;
        }

        $schoolId = (int) $sessionId;

        // Verify school still exists and is not soft-deleted
        $exists = School::where('id', $schoolId)->exists();
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
        $schoolId = $this->getActiveSchoolId();
        return $schoolId ? School::find($schoolId) : null;
    }

    /**
     * Set active school context for Super Admin.
     */
    public function setActiveSchoolId(int $schoolId): bool
    {
        $user = Auth::user();
        if (! $user || ! $user->hasRole('super-admin')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Only Super Admins can set active school context.');
        }

        $school = School::find($schoolId);
        if (! $school) {
            throw new \InvalidArgumentException("School with ID {$schoolId} does not exist.");
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
     * Check if an active school context is established.
     */
    public function hasActiveSchool(): bool
    {
        return $this->getActiveSchoolId() !== null;
    }
}
