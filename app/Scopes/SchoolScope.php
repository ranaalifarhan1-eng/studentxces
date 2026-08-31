<?php

namespace App\Scopes;

use App\Services\ActiveSchoolContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! auth()->check()) {
            return;
        }

        $context = app(ActiveSchoolContext::class);
        $schoolId = $context->getActiveSchoolId();

        if ($schoolId !== null) {
            $builder->where($model->getTable() . '.school_id', $schoolId);
        }
    }
}
