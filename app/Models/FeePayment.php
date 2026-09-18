<?php

namespace App\Models;

use App\Services\DocumentSequenceService;
use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeePayment extends Model
{
    use BelongsToSchool, SoftDeletes;

    protected $fillable = [
        'school_id', 'student_id', 'fee_challan_id', 'fee_structure_id', 'receipt_no',
        'amount_due', 'amount_paid', 'discount', 'fine', 'balance_snapshot',
        'payment_date', 'month_year', 'method', 'reference', 'status', 'note', 'collected_by',
        'idempotency_key',
    ];

    protected $casts = [
        'amount_due'       => 'decimal:2',
        'amount_paid'      => 'decimal:2',
        'discount'         => 'decimal:2',
        'fine'             => 'decimal:2',
        'balance_snapshot' => 'decimal:2',
        'payment_date'     => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (FeePayment $payment) {
            if (empty($payment->receipt_no)) {
                $payment->receipt_no = DocumentSequenceService::nextNumber($payment->school_id, 'receipt', 'RCP');
            }
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function feeChallan(): BelongsTo
    {
        return $this->belongsTo(FeeChallan::class);
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function getBalanceAttribute(): string
    {
        if ($this->balance_snapshot !== null) {
            return \App\Support\Money::toDecimal(\App\Support\Money::toCents($this->balance_snapshot));
        }

        $dueCents      = \App\Support\Money::toCents($this->amount_due);
        $fineCents     = \App\Support\Money::toCents($this->fine);
        $discountCents = \App\Support\Money::toCents($this->discount);
        $paidCents     = \App\Support\Money::toCents($this->amount_paid);

        $netCents = max(0, $dueCents + $fineCents - $discountCents - $paidCents);
        return \App\Support\Money::toDecimal($netCents);
    }
}
