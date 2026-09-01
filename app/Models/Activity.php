<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

class Activity extends SpatieActivity
{
    /**
     * Scope query to tenant-visible activities for a specific school.
     * Actions must be caused by a tenant User belonging to the specified school_id
     * who does NOT have the super-admin role.
     */
    public function scopeForTenant(Builder $query, int $schoolId): Builder
    {
        return $query->whereHasMorph('causer', [User::class], function (Builder $userQuery) use ($schoolId) {
            $userQuery->where('school_id', $schoolId)
                      ->whereDoesntHave('roles', function (Builder $roleQuery) {
                          $roleQuery->where('name', 'super-admin');
                      });
        });
    }
}
