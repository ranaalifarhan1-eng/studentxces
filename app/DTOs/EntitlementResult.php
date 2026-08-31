<?php

namespace App\DTOs;

class EntitlementResult
{
    // Canonical Reason Codes
    public const ALLOWED                        = 'ALLOWED';
    public const SUPER_ADMIN_BYPASS             = 'SUPER_ADMIN_BYPASS';
    public const CORE_FEATURE                   = 'CORE_FEATURE';
    public const NO_ACTIVE_SUBSCRIPTION         = 'NO_ACTIVE_SUBSCRIPTION';
    public const SUBSCRIPTION_NOT_STARTED       = 'SUBSCRIPTION_NOT_STARTED';
    public const SUBSCRIPTION_EXPIRED           = 'SUBSCRIPTION_EXPIRED';
    public const SUBSCRIPTION_SUSPENDED         = 'SUBSCRIPTION_SUSPENDED';
    public const TRIAL_EXPIRED                  = 'TRIAL_EXPIRED';
    public const INVALID_TRIAL_CONFIGURATION    = 'INVALID_TRIAL_CONFIGURATION';
    public const AMBIGUOUS_ACTIVE_SUBSCRIPTIONS = 'AMBIGUOUS_ACTIVE_SUBSCRIPTIONS';
    public const MODULE_NOT_IN_PACKAGE          = 'MODULE_NOT_IN_PACKAGE';
    public const MODULE_DISABLED_BY_OVERRIDE    = 'MODULE_DISABLED_BY_OVERRIDE';
    public const MODULE_ENABLED_BY_OVERRIDE     = 'MODULE_ENABLED_BY_OVERRIDE';

    public function __construct(
        public readonly bool $isEntitled,
        public readonly string $reason,
        public readonly ?int $schoolId = null,
        public readonly ?string $module = null,
        public readonly ?int $subscriptionId = null,
        public readonly ?int $packageId = null,
        public readonly ?string $message = null,
    ) {}

    public static function allow(
        string $reason,
        ?int $schoolId = null,
        ?string $module = null,
        ?int $subscriptionId = null,
        ?int $packageId = null,
        ?string $message = null
    ): self {
        return new self(
            isEntitled: true,
            reason: $reason,
            schoolId: $schoolId,
            module: $module,
            subscriptionId: $subscriptionId,
            packageId: $packageId,
            message: $message ?? 'Access permitted.'
        );
    }

    public static function deny(
        string $reason,
        ?int $schoolId = null,
        ?string $module = null,
        ?int $subscriptionId = null,
        ?int $packageId = null,
        ?string $message = null
    ): self {
        return new self(
            isEntitled: false,
            reason: $reason,
            schoolId: $schoolId,
            module: $module,
            subscriptionId: $subscriptionId,
            packageId: $packageId,
            message: $message ?? 'Access denied.'
        );
    }

    public function toArray(): array
    {
        return [
            'is_entitled'     => $this->isEntitled,
            'reason'          => $this->reason,
            'school_id'       => $this->schoolId,
            'module'          => $this->module,
            'subscription_id' => $this->subscriptionId,
            'package_id'      => $this->packageId,
            'message'         => $this->message,
        ];
    }
}
