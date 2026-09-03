<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainProvisioningRequest extends Model
{
    use HasFactory;

    public const ACTION_PROVISION = 'provision';

    public const STATUS_QUEUED    = 'queued';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';

    public const ERROR_DNS_CHANGED              = 'dns_changed';
    public const ERROR_NGINX_VALIDATION_FAILED  = 'nginx_validation_failed';
    public const ERROR_CERTIFICATE_FAILED       = 'certificate_failed';
    public const ERROR_TLS_PROBE_FAILED         = 'tls_probe_failed';
    public const ERROR_ACTIVATION_FAILED        = 'activation_failed';
    public const ERROR_PROVISIONING_TIMEOUT     = 'provisioning_timeout';

    public const SAFE_ERROR_CODES = [
        self::ERROR_DNS_CHANGED,
        self::ERROR_NGINX_VALIDATION_FAILED,
        self::ERROR_CERTIFICATE_FAILED,
        self::ERROR_TLS_PROBE_FAILED,
        self::ERROR_ACTIVATION_FAILED,
        self::ERROR_PROVISIONING_TIMEOUT,
    ];

    protected $fillable = [
        'school_domain_id',
        'requested_by',
        'action',
        'status',
        'attempt_count',
        'safe_error_code',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'attempt_count' => 'integer',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
    ];

    public function schoolDomain(): BelongsTo
    {
        return $this->belongsTo(SchoolDomain::class, 'school_domain_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }
}
