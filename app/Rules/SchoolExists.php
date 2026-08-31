<?php

namespace App\Rules;

use App\Services\ActiveSchoolContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class SchoolExists implements ValidationRule
{
    /**
     * @param string $table The database table to check against
     * @param string $column The primary/foreign key column (defaults to 'id')
     * @param int|null $schoolId Specific school ID to enforce (defaults to canonical ActiveSchoolContext)
     * @param Closure|null $extraQuery Optional additional query constraints
     */
    public function __construct(
        protected string $table,
        protected string $column = 'id',
        protected ?int $schoolId = null,
        protected ?Closure $extraQuery = null
    ) {
        if ($this->schoolId === null && auth()->check()) {
            $this->schoolId = app(ActiveSchoolContext::class)->getActiveSchoolId();
        }
    }

    public static function make(string $table, string $column = 'id', ?int $schoolId = null, ?Closure $extraQuery = null): self
    {
        return new self($table, $column, $schoolId, $extraQuery);
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_null($value) || $value === '') {
            return;
        }

        $query = DB::table($this->table)->where($this->column, $value);

        // Enforce school_id scoping from explicit parameter or canonical active context
        $effectiveSchoolId = $this->schoolId ?? app(ActiveSchoolContext::class)->getActiveSchoolId();

        if ($effectiveSchoolId !== null) {
            $query->where('school_id', $effectiveSchoolId);
        }

        if ($this->extraQuery) {
            ($this->extraQuery)($query);
        }

        if (! $query->exists()) {
            $readableAttr = str_replace(['_id', 'filters.', 'guardian.', 'stops.*.'], '', $attribute);
            $readableAttr = str_replace('_', ' ', $readableAttr);
            $fail("The selected {$readableAttr} is invalid or does not belong to your school.");
        }
    }
}
