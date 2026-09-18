<?php

namespace App\Models;

use App\Traits\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentFeeDiscount extends Model
{
    use BelongsToSchool, SoftDeletes;

    protected $fillable = [
        'school_id',
        'student_id',
        'fee_category_id',
        'academic_year_id',
        'title',
        'type',
        'value',
        'is_active',
    ];

    protected $casts = [
        'value'     => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Exact integer cent calculation for the discount against a gross amount in cents.
     */
    public function calculateDiscountCents(int $grossCents): int
    {
        if ($grossCents <= 0 || ! $this->is_active) {
            return 0;
        }

        if ($this->type === 'percentage') {
            // (grossCents * value) / 100
            // value is decimal e.g. 20.00 -> 2000 hundredths of a percent
            $valueHundredths = \App\Support\Money::toCents($this->value);
            $discountCents = intdiv($grossCents * $valueHundredths + 5000, 10000);
            return min($discountCents, $grossCents);
        }

        // Fixed amount discount in cents
        $fixedCents = \App\Support\Money::toCents($this->value);
        return min($fixedCents, $grossCents);
    }
}
