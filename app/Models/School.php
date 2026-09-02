<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class School extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'logo', 'email', 'phone',
        'address', 'city', 'state', 'country',
        'timezone', 'currency', 'language',
        'settings', 'status',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (School $school) {
            if (empty($school->slug)) {
                $school->slug = Str::slug($school->name);
            }
        });

        static::saved(function () {
            if (app()->bound(\App\Services\TenantDomainResolver::class)) {
                app(\App\Services\TenantDomainResolver::class)->clearCache();
            }
            if (app()->bound(\App\Services\ActiveSchoolContext::class)) {
                app(\App\Services\ActiveSchoolContext::class)->reset();
            }
        });

        static::deleted(function () {
            if (app()->bound(\App\Services\TenantDomainResolver::class)) {
                app(\App\Services\TenantDomainResolver::class)->clearCache();
            }
            if (app()->bound(\App\Services\ActiveSchoolContext::class)) {
                app(\App\Services\ActiveSchoolContext::class)->reset();
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    public function currentAcademicYear()
    {
        return $this->academicYears()->where('is_current', true)->first();
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/' . $this->logo) : null;
    }

    public function domains(): HasMany
    {
        return $this->hasMany(SchoolDomain::class);
    }

    public function primaryDomain()
    {
        return $this->hasOne(SchoolDomain::class)->where('is_primary', true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
