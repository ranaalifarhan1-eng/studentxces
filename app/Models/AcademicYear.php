<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends Model
{
    use HasFactory, SoftDeletes, BelongsToSchool;

    protected $fillable = [
        'school_id', 'name', 'start_date', 'end_date', 'is_current',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_current' => 'boolean',
    ];

    /**
     * Get safe canonical aliases for this academic year session.
     *
     * @return array<int, string>
     */
    public function getYearAliases(): array
    {
        $aliases = [];
        if (! empty($this->name)) {
            $aliases[] = trim($this->name);
        }

        $startY = null;
        $endY = null;

        if ($this->start_date && $this->end_date) {
            $start = $this->start_date instanceof \Illuminate\Support\Carbon
                ? $this->start_date
                : \Illuminate\Support\Carbon::parse($this->start_date);
            $end = $this->end_date instanceof \Illuminate\Support\Carbon
                ? $this->end_date
                : \Illuminate\Support\Carbon::parse($this->end_date);

            $startY = $start->format('Y');
            $endY = $end->format('Y');
        } elseif (! empty($this->name) && preg_match('/(\d{4})\s*[-\/]\s*(\d{2,4})/', $this->name, $matches)) {
            $startY = $matches[1];
            $endY = strlen($matches[2]) === 2 ? substr($startY, 0, 2) . $matches[2] : $matches[2];
        }

        if ($startY && $endY && $startY !== $endY) {
            $shortEndY = substr($endY, -2);
            $aliases[] = "{$startY}-{$endY}";
            $aliases[] = "{$startY}-{$shortEndY}";
            $aliases[] = "{$startY}/{$endY}";
            $aliases[] = "{$startY}/{$shortEndY}";
        }

        return array_values(array_unique(array_filter(array_map('trim', $aliases))));
    }

    /**
     * Accessor for year_aliases attribute.
     *
     * @return array<int, string>
     */
    public function getYearAliasesAttribute(): array
    {
        return $this->getYearAliases();
    }

    /**
     * Determine if a given string matches this academic year or any of its canonical aliases.
     */
    public function matchesYearString(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        $needle = mb_strtolower(trim($value));
        foreach ($this->getYearAliases() as $alias) {
            if (mb_strtolower(trim($alias)) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * When setting a year as current, unset all others for the same school.
     */
    public function makeCurrent(): void
    {
        static::where('school_id', $this->school_id)
            ->where('id', '!=', $this->id)
            ->update(['is_current' => false]);

        $this->update(['is_current' => true]);
    }
}
