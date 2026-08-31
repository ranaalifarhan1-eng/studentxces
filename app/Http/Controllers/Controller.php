<?php

namespace App\Http\Controllers;

use App\Services\ActiveSchoolContext;

abstract class Controller
{
    /**
     * Return the active school_id for the current session via ActiveSchoolContext.
     * - Tenant users: Strictly their profile school_id.
     * - Super Admin: Explicitly selected session active_school_id.
     * - Fail-closed: Aborts 403 if no valid school context exists.
     */
    protected function getSchoolId(): int
    {
        $schoolId = app(ActiveSchoolContext::class)->getActiveSchoolId();

        if (! $schoolId) {
            abort(403, 'No active school context established.');
        }

        return (int) $schoolId;
    }
}
