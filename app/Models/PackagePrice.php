<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackagePrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_id',
        'term_months',
        'base_monthly_price',
        'discount_percent',
        'total_price',
        'currency',
        'is_active',
    ];

    protected $casts = [
        'term_months'        => 'integer',
        'base_monthly_price' => 'decimal:2',
        'discount_percent'   => 'decimal:2',
        'total_price'        => 'decimal:2',
        'is_active'          => 'boolean',
    ];

    protected $appends = [
        'savings_amount',
        'effective_monthly_price',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(SchoolSubscription::class);
    }

    public function getSavingsAmountAttribute(): float
    {
        $undiscounted = (float) $this->base_monthly_price * (int) $this->term_months;
        $total = (float) $this->total_price;
        return max(0.0, round($undiscounted - $total, 2));
    }

    public function getEffectiveMonthlyPriceAttribute(): float
    {
        if ($this->term_months <= 0) {
            return (float) $this->total_price;
        }
        return round((float) $this->total_price / (int) $this->term_months, 2);
    }
}