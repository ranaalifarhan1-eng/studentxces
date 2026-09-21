<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeChallanAdjustment extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'school_id',
        'fee_challan_id',
        'student_id',
        'adjustment_type',
        'value',
        'adjustment_amount',
        'previous_balance',
        'new_balance',
        'reason',
        'notes',
        'idempotency_key',
        'created_by',
    ];

    protected $casts = [
        'value'             => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'previous_balance'  => 'decimal:2',
        'new_balance'       => 'decimal:2',
    ];

    public function feeChallan(): BelongsTo
    {
        return $this->belongsTo(FeeChallan::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
