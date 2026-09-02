<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'badge', 'description', 'price_monthly', 'price_yearly',
        'currency', 'max_students', 'max_staff', 'storage_gb', 'is_active', 'features',
    ];

    protected $casts = [
        'features'      => 'array',
        'is_active'     => 'boolean',
        'price_monthly' => 'decimal:2',
        'price_yearly'  => 'decimal:2',
    ];

    public function prices(): HasMany
    {
        return $this->hasMany(PackagePrice::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(PackageModule::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(SchoolSubscription::class);
    }

    /**
     * Get a specific active pricing term (3, 6, or 12 months).
     */
    public function getTermPrice(int $months): ?PackagePrice
    {
        return $this->prices->firstWhere('term_months', $months);
    }
}
