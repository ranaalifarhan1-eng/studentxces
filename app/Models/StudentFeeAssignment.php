<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentFeeAssignment extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'school_id',
        'student_id',
        'fee_structure_id',
        'academic_year_id',
        'starts_on',
        'ends_on',
        'is_active',
        'assigned_by',
        'deactivated_by',
        'deactivated_at',
        'deactivation_reason',
        'notes',
    ];

    protected $casts = [
        'starts_on'      => 'date',
        'ends_on'        => 'date',
        'is_active'      => 'boolean',
        'deactivated_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForAcademicYear(Builder $query, int $academicYearId): Builder
    {
        return $query->where('academic_year_id', $academicYearId);
    }

    /**
     * Mark assignment as inactive with audit fields.
     */
    public function deactivate(int $userId, string $reason): void
    {
        $this->update([
            'is_active'           => false,
            'deactivated_by'      => $userId,
            'deactivated_at'      => now(),
            'deactivation_reason' => $reason,
        ]);
    }

    /**
     * Reactivate a previously deactivated assignment.
     */
    public function reactivate(?int $userId = null, ?Carbon $startsOn = null, ?Carbon $endsOn = null): void
    {
        $updates = [
            'is_active'           => true,
            'deactivated_by'      => null,
            'deactivated_at'      => null,
            'deactivation_reason' => null,
        ];

        if ($userId !== null) {
            $updates['assigned_by'] = $userId;
        }

        if ($startsOn !== null) {
            $updates['starts_on'] = $startsOn;
        }

        if ($endsOn !== null) {
            $updates['ends_on'] = $endsOn;
        }

        $this->update($updates);
    }
}
