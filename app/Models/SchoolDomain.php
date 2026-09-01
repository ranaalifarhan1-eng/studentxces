<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolDomain extends Model
{
    use HasFactory;

    public const TYPE_DEFAULT = 'default';
    public const TYPE_CUSTOM  = 'custom';

    public const STATUS_PENDING  = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_DISABLED = 'disabled';

    public const SSL_PENDING = 'pending';
    public const SSL_ACTIVE  = 'active';
    public const SSL_FAILED  = 'failed';

    protected $fillable = [
        'school_id',
        'hostname',
        'type',
        'is_primary',
        'status',
        'verification_token',
        'verified_at',
        'ssl_status',
    ];

    protected $casts = [
        'is_primary'  => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function isDefault(): bool
    {
        return $this->type === self::TYPE_DEFAULT;
    }

    public function isCustom(): bool
    {
        return $this->type === self::TYPE_CUSTOM;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isResolvable(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_VERIFIED], true);
    }
}
