<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeChallanItem extends Model
{
    protected $fillable = [
        'fee_challan_id',
        'school_id',
        'student_id',
        'fee_structure_id',
        'charge_period_key',
        'active_charge_key',
        'fee_head_name',
        'gross_amount',
        'discount_amount',
        'net_amount',
    ];

    protected $casts = [
        'gross_amount'    => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'net_amount'      => 'decimal:2',
    ];

    public function feeChallan(): BelongsTo
    {
        return $this->belongsTo(FeeChallan::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }
}
