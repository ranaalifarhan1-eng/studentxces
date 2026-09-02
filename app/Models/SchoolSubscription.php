<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolSubscription extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'school_id', 'package_id', 'coupon_id', 'billing_term_months',
        'base_monthly_price', 'discount_percent', 'billed_amount', 'currency',
        'package_price_id', 'start_date', 'end_date', 'status', 'is_trial',
        'trial_ends_at', 'amount_paid', 'payment_method', 'notes',
    ];

    protected $casts = [
        'start_date'          => 'date',
        'end_date'            => 'date',
        'trial_ends_at'       => 'date',
        'is_trial'            => 'boolean',
        'billing_term_months' => 'integer',
        'base_monthly_price'  => 'decimal:2',
        'discount_percent'    => 'decimal:2',
        'billed_amount'       => 'decimal:2',
        'amount_paid'         => 'decimal:2',
    ];

    public function school(): BelongsTo        { return $this->belongsTo(School::class); }
    public function package(): BelongsTo       { return $this->belongsTo(Package::class); }
    public function coupon(): BelongsTo        { return $this->belongsTo(Coupon::class); }
    public function packagePrice(): BelongsTo  { return $this->belongsTo(PackagePrice::class); }
}
