<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeBillingRun extends Model
{
    use BelongsToSchool;

    protected $table = 'fee_billing_runs';

    protected $fillable = [
        'school_id',
        'academic_year_id',
        'target_month',
        'billing_period_key',
        'status',
        'triggered_by',
        'started_at',
        'completed_at',
        'last_executed_at',
        'execution_count',
        'students_scanned',
        'challans_created',
        'last_challans_created',
        'vouchers_updated',
        'last_vouchers_updated',
        'charges_appended',
        'last_charges_appended',
        'challans_skipped',
        'total_amount',
        'error_summary',
    ];

    protected $casts = [
        'started_at'            => 'datetime',
        'completed_at'          => 'datetime',
        'last_executed_at'      => 'datetime',
        'execution_count'       => 'integer',
        'students_scanned'      => 'integer',
        'challans_created'      => 'integer',
        'last_challans_created' => 'integer',
        'vouchers_updated'      => 'integer',
        'last_vouchers_updated' => 'integer',
        'charges_appended'      => 'integer',
        'last_charges_appended' => 'integer',
        'challans_skipped'      => 'integer',
        'total_amount'          => 'decimal:2',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeForMonth(Builder $query, string $targetMonth): Builder
    {
        return $query->where('target_month', $targetMonth);
    }
}
