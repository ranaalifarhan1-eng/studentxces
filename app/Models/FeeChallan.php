<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Carbon\Carbon;
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
        'adjustment_amount',
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
        'adjustment_amount'             => 'decimal:2',
        'total_payable'                 => 'decimal:2',
        'paid_amount'                   => 'decimal:2',
        'previous_outstanding_snapshot' => 'decimal:2',
    ];

    protected $appends = ['balance', 'billing_period_label', 'display_status', 'settlement_classification', 'settlement_date', 'guardian_name', 'guardian_cnic'];

    public function getGuardianNameAttribute(): ?string
    {
        return $this->student?->guardian?->name;
    }

    public function getGuardianCnicAttribute(): ?string
    {
        return $this->student?->guardian?->cnic ?? null;
    }

    public function getBillingPeriodLabelAttribute(): string
    {
        // If billing_period_key is an explicit descriptive label (e.g. "Test Session 2026"), respect it directly
        if (! empty($this->billing_period_key) && ! preg_match('/^AY\d+-\d{4}-\d{2}$/i', $this->billing_period_key) && ! preg_match('/^\d{4}-\d{2}$/', $this->billing_period_key)) {
            return $this->billing_period_key;
        }

        if ($this->issue_date) {
            $monthStr = \Carbon\Carbon::parse($this->issue_date)->format('M Y');
        } elseif (preg_match('/(\d{4})-(\d{2})/', (string) $this->billing_period_key, $m)) {
            $monthStr = \Carbon\Carbon::createFromDate((int) $m[1], (int) $m[2], 1)->format('M Y');
        } else {
            $monthStr = $this->billing_period_key ?: 'General';
        }

        $hasAdmission = false;
        if ($this->relationLoaded('items')) {
            $hasAdmission = $this->items->contains(function ($item) {
                return str_contains(strtolower($item->fee_head_name), 'admission');
            });
        } elseif (str_contains(strtolower((string) $this->billing_period_key), 'onetime')) {
            $hasAdmission = true;
        }

        if ($hasAdmission) {
            return "{$monthStr} (Admission)";
        }

        return $monthStr;
    }

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

    public function adjustments(): HasMany
    {
        return $this->hasMany(FeeChallanAdjustment::class);
    }

    public function getBalanceAttribute(): string
    {
        $payableCents = \App\Support\Money::toCents($this->total_payable);
        $paidCents    = \App\Support\Money::toCents($this->paid_amount);
        $balanceCents = max(0, $payableCents - $paidCents);
        return \App\Support\Money::toDecimal($balanceCents);
    }

    public function getDisplayStatusAttribute(): string
    {
        if ($this->status === 'void') {
            return 'void';
        }

        $payableCents = \App\Support\Money::toCents($this->total_payable);
        $paidCents    = \App\Support\Money::toCents($this->paid_amount);
        $adjCents     = \App\Support\Money::toCents($this->adjustment_amount ?? '0.00');
        $balanceCents = max(0, $payableCents - $paidCents);

        if ($balanceCents === 0 || $this->status === 'paid') {
            if ($paidCents > 0 && $adjCents > 0) {
                return 'paid_adjusted';
            }
            if ($paidCents === 0 && $adjCents > 0) {
                return 'waived';
            }
            return 'paid';
        }

        $today = \Carbon\Carbon::today();
        $isOverdue = $this->due_date && \Carbon\Carbon::parse($this->due_date)->lt($today);

        if ($this->status === 'unpaid' && $isOverdue) {
            return 'overdue';
        }

        return $this->status;
    }

    public function getSettlementClassificationAttribute(): string
    {
        if ($this->status === 'void') {
            return 'Void';
        }

        $payableCents = \App\Support\Money::toCents($this->total_payable);
        $paidCents    = \App\Support\Money::toCents($this->paid_amount);
        $adjCents     = \App\Support\Money::toCents($this->adjustment_amount ?? '0.00');
        $balanceCents = max(0, $payableCents - $paidCents);

        if ($balanceCents === 0 || $this->status === 'paid') {
            if ($paidCents > 0 && $adjCents > 0) {
                return 'Paid + Adjusted';
            }
            if ($paidCents === 0 && $adjCents > 0) {
                return 'Settled — Adjusted';
            }
            return 'Paid';
        }

        $today = \Carbon\Carbon::today();
        $isOverdue = $this->due_date && \Carbon\Carbon::parse($this->due_date)->lt($today);

        if ($paidCents > 0) {
            return $adjCents > 0 ? 'Partially Paid + Adjusted' : 'Partially Paid';
        }

        if ($adjCents > 0) {
            return $isOverdue ? 'Overdue (Adjusted)' : 'Unpaid (Adjusted)';
        }

        return $isOverdue ? 'Overdue' : 'Unpaid';
    }

    public function getSettlementDateAttribute(): ?string
    {
        $payableCents = \App\Support\Money::toCents($this->total_payable);
        $paidCents    = \App\Support\Money::toCents($this->paid_amount);
        $balanceCents = max(0, $payableCents - $paidCents);

        // Only compute settlement date if the challan is settled (status == 'paid' or balance == 0)
        if ($balanceCents > 0 && $this->status !== 'paid') {
            return null;
        }

        $latestPaymentDate = null;
        $payments = $this->relationLoaded('payments') ? $this->payments : $this->payments()->get();
        if ($payments->isNotEmpty()) {
            $latestPayment = $payments
                ->filter(fn ($p) => ! empty($p->payment_date))
                ->sortByDesc(fn ($p) => Carbon::parse($p->payment_date)->timestamp)
                ->first();
            if ($latestPayment && $latestPayment->payment_date) {
                $latestPaymentDate = Carbon::parse($latestPayment->payment_date)->startOfDay();
            }
        }

        $latestAdjustmentDate = null;
        $adjustments = $this->relationLoaded('adjustments') ? $this->adjustments : $this->adjustments()->get();
        if ($adjustments->isNotEmpty()) {
            $latestAdjustment = $adjustments
                ->filter(fn ($a) => ! empty($a->created_at))
                ->sortByDesc(fn ($a) => Carbon::parse($a->created_at)->timestamp)
                ->first();
            if ($latestAdjustment && $latestAdjustment->created_at) {
                $latestAdjustmentDate = Carbon::parse($latestAdjustment->created_at)->startOfDay();
            }
        }

        if ($latestPaymentDate && $latestAdjustmentDate) {
            return $latestAdjustmentDate->gt($latestPaymentDate)
                ? $latestAdjustmentDate->format('Y-m-d')
                : $latestPaymentDate->format('Y-m-d');
        }

        if ($latestPaymentDate) {
            return $latestPaymentDate->format('Y-m-d');
        }

        if ($latestAdjustmentDate) {
            return $latestAdjustmentDate->format('Y-m-d');
        }

        // Fallback: if challan was marked paid without payment/adjustment records (e.g. legacy direct status update)
        if ($this->status === 'paid' && $this->updated_at) {
            return Carbon::parse($this->updated_at)->format('Y-m-d');
        }

        return null;
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
