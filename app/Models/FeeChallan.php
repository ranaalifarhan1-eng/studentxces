<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeeChallan extends Model
{
    use BelongsToSchool, SoftDeletes;

    protected $fillable = [
        'school_id',
        'challan_no',
        'billing_period_key',
        'active_period_key',
        'student_id',
        'class_id',
        'section_id',
        'academic_year_id',
        'student_name',
        'admission_no',
        'class_name',
        'section_name',
        'academic_year_name',
        'issue_date',
        'due_date',
        'issued_at',
        'gross_amount',
        'discount_amount',
        'discount_title',
        'fine_amount',
        'total_payable',
        'paid_amount',
        'previous_outstanding_snapshot',
        'status',
        'void_reason',
        'voided_at',
        'voided_by',
        'notes',
    ];

    protected $casts = [
        'issue_date'                    => 'date',
        'due_date'                      => 'date',
        'issued_at'                     => 'datetime',
        'voided_at'                     => 'datetime',
        'gross_amount'                  => 'decimal:2',
        'discount_amount'               => 'decimal:2',
        'fine_amount'                   => 'decimal:2',
        'total_payable'                 => 'decimal:2',
        'paid_amount'                   => 'decimal:2',
        'previous_outstanding_snapshot' => 'decimal:2',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FeeChallanItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FeePayment::class);
    }

    public function getBalanceAttribute(): string
    {
        $payableCents = \App\Support\Money::toCents($this->total_payable);
        $paidCents    = \App\Support\Money::toCents($this->paid_amount);
        $balanceCents = max(0, $payableCents - $paidCents);
        return \App\Support\Money::toDecimal($balanceCents);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    /**
     * Voids an unpaid challan, recording the reason and clearing active unique keys
     * so regeneration is permitted without constraint conflicts.
     */
    public function voidChallan(string $reason, int $userId): void
    {
        $this->update([
            'status'            => 'void',
            'void_reason'       => $reason,
            'voided_at'         => now(),
            'voided_by'         => $userId,
            'active_period_key' => null,
        ]);

        foreach ($this->items as $item) {
            $item->update(['active_charge_key' => null]);
        }
    }
}
