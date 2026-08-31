<?php

namespace App\Traits;

use App\Models\School;
use App\Scopes\SchoolScope;
use App\Services\ActiveSchoolContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to every school-scoped model.
 * Automatically:
 *   - Adds a global WHERE school_id = ? scope via ActiveSchoolContext
 *   - Sets school_id on create from the ActiveSchoolContext
 *   - Provides a ->school() relation
 */
trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope());

        static::creating(function ($model) {
            if (empty($model->school_id) && auth()->check()) {
                $context = app(ActiveSchoolContext::class);
                $model->school_id = $context->getActiveSchoolId();
            }
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
