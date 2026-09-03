<?php

namespace App\Services;

use App\Models\DomainProvisioningRequest;
use App\Models\SchoolDomain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DomainProvisioningService
{
    /**
     * Request automated infrastructure provisioning for a DNS-verified domain.
     * Accessible strictly to Super Admin operators.
     *
     * @return array{success: bool, message: string, request?: DomainProvisioningRequest, code?: string}
     */
    public function requestProvisioning(SchoolDomain $domainModel, User $actor): array
    {
        // 1. Authorization check: Super Admin only
        if (! $actor->hasRole('super-admin')) {
            return [
                'success' => false,
                'message' => 'Unauthorized: Infrastructure provisioning can only be initiated by platform Super Administrators.',
                'code'    => 'unauthorized',
            ];
        }

        // 2. Wrap in transaction and acquire row lock on SchoolDomain to eliminate race conditions
        return DB::transaction(function () use ($domainModel, $actor) {
            $domain = SchoolDomain::with(['school', 'latestProvisioningRequest'])
                ->where('id', $domainModel->id)
                ->lockForUpdate()
                ->first();

            if (! $domain) {
                return [
                    'success' => false,
                    'message' => 'Domain record not found.',
                    'code'    => 'not_found',
                ];
            }

            // Validate hostname and domain prerequisites
            try {
                TenantHostnameValidator::validateForProvisioning($domain);
            } catch (InvalidArgumentException $e) {
                return [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'code'    => 'invalid_precondition',
                ];
            }

            // Check if already active and secure
            if ($domain->status === SchoolDomain::STATUS_ACTIVE && $domain->ssl_status === SchoolDomain::SSL_ACTIVE) {
                return [
                    'success' => true,
                    'message' => "Domain '{$domain->hostname}' is already active with valid SSL. No provisioning required.",
                    'code'    => 'already_active',
                ];
            }

            // Check if there is an active (queued or running) request
            $existingActive = DomainProvisioningRequest::where('school_domain_id', $domain->id)
                ->whereIn('status', [DomainProvisioningRequest::STATUS_QUEUED, DomainProvisioningRequest::STATUS_RUNNING])
                ->lockForUpdate()
                ->first();

            if ($existingActive) {
                return [
                    'success' => false,
                    'message' => "A provisioning request for '{$domain->hostname}' is already in progress (Status: {$existingActive->status}).",
                    'request' => $existingActive,
                    'code'    => 'request_in_progress',
                ];
            }

            // Check retry cooldown and max attempts against latest request
            $latestRequest = DomainProvisioningRequest::where('school_domain_id', $domain->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($latestRequest && $latestRequest->isFailed()) {
                $maxAttempts = (int) config('tenancy.provisioning.max_attempts', 5);
                if ($latestRequest->attempt_count >= $maxAttempts) {
                    return [
                        'success' => false,
                        'message' => "Maximum activation attempts ({$maxAttempts}) reached for this domain. Please contact platform support.",
                        'code'    => 'max_attempts_reached',
                    ];
                }

                $cooldownMinutes = (int) config('tenancy.provisioning.retry_cooldown_minutes', 5);
                if ($latestRequest->completed_at && $latestRequest->completed_at->gt(now()->subMinutes($cooldownMinutes))) {
                    $remainingSecs = now()->diffInSeconds($latestRequest->completed_at->copy()->addMinutes($cooldownMinutes));
                    $remainingMins = (int) ceil($remainingSecs / 60);
                    return [
                        'success' => false,
                        'message' => "Retry cooldown active. Please wait {$remainingMins} minute(s) before retrying activation.",
                        'code'    => 'cooldown_active',
                    ];
                }
            }

            $currentAttemptCount = $latestRequest ? $latestRequest->attempt_count : 0;

            // Create new queued request
            $request = DomainProvisioningRequest::create([
                'school_domain_id' => $domain->id,
                'requested_by'     => $actor->id,
                'action'           => DomainProvisioningRequest::ACTION_PROVISION,
                'status'           => DomainProvisioningRequest::STATUS_QUEUED,
                'attempt_count'    => $currentAttemptCount,
            ]);

            return [
                'success' => true,
                'message' => "Infrastructure provisioning queued successfully for '{$domain->hostname}'.",
                'request' => $request,
                'code'    => 'queued',
            ];
        });
    }

    /**
     * Recover any stale running requests that exceeded the configured timeout.
     */
    public function recoverStaleRequests(?int $timeoutMinutes = null): int
    {
        $timeout = $timeoutMinutes ?? (int) config('tenancy.provisioning.running_timeout_minutes', 10);
        $cutoff  = now()->subMinutes($timeout);

        return DB::transaction(function () use ($cutoff) {
            $staleRequests = DomainProvisioningRequest::with('schoolDomain')
                ->where('status', DomainProvisioningRequest::STATUS_RUNNING)
                ->where('started_at', '<', $cutoff)
                ->lockForUpdate()
                ->get();

            $count = 0;
            foreach ($staleRequests as $req) {
                $req->update([
                    'status'          => DomainProvisioningRequest::STATUS_FAILED,
                    'safe_error_code' => DomainProvisioningRequest::ERROR_PROVISIONING_TIMEOUT,
                    'completed_at'    => now(),
                ]);

                if ($req->schoolDomain && $req->schoolDomain->status !== SchoolDomain::STATUS_ACTIVE) {
                    $req->schoolDomain->update(['ssl_status' => SchoolDomain::SSL_FAILED]);
                }
                $count++;
            }

            return $count;
        });
    }

    /**
     * Atomically claim the next queued provisioning request for the host runner.
     */
    public function claimNextRequest(): ?DomainProvisioningRequest
    {
        // First recover any stale requests
        $this->recoverStaleRequests();

        return DB::transaction(function () {
            $request = DomainProvisioningRequest::with(['schoolDomain.school'])
                ->where('status', DomainProvisioningRequest::STATUS_QUEUED)
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();

            if (! $request) {
                return null;
            }

            $request->update([
                'status'        => DomainProvisioningRequest::STATUS_RUNNING,
                'started_at'    => now(),
                'attempt_count' => $request->attempt_count + 1,
            ]);

            return $request->fresh(['schoolDomain.school']);
        });
    }

    /**
     * Mark a provisioning request as successfully verified and activate the domain.
     *
     * @return array{success: bool, message: string}
     */
    public function markSuccess(int $requestId, int $domainId, HttpsProbeInterface $httpsProbe): array
    {
        return DB::transaction(function () use ($requestId, $domainId, $httpsProbe) {
            $request = DomainProvisioningRequest::with('schoolDomain')
                ->where('id', $requestId)
                ->lockForUpdate()
                ->first();

            if (! $request) {
                return ['success' => false, 'message' => "Provisioning request #{$requestId} not found."];
            }

            if ($request->school_domain_id !== $domainId) {
                return ['success' => false, 'message' => "Request #{$requestId} does not match domain ID #{$domainId}."];
            }

            // Re-entrant / Idempotency handling: If already succeeded and domain active, return success
            if ($request->status === DomainProvisioningRequest::STATUS_SUCCEEDED && $request->schoolDomain?->isActive()) {
                return [
                    'success' => true,
                    'message' => "Domain '{$request->schoolDomain->hostname}' is already verified and active.",
                ];
            }

            if ($request->status !== DomainProvisioningRequest::STATUS_RUNNING) {
                return ['success' => false, 'message' => "Request #{$requestId} is not in running state (Current: {$request->status})."];
            }

            $domain = $request->schoolDomain;
            if (! $domain) {
                return ['success' => false, 'message' => "Associated domain record missing."];
            }

            // Server-side TLS verification probe
            $probeResult = $httpsProbe->probe($domain->hostname);
            if (! $probeResult['success']) {
                $request->update([
                    'status'          => DomainProvisioningRequest::STATUS_FAILED,
                    'safe_error_code' => DomainProvisioningRequest::ERROR_TLS_PROBE_FAILED,
                    'completed_at'    => now(),
                ]);
                $domain->update(['ssl_status' => SchoolDomain::SSL_FAILED]);

                return [
                    'success' => false,
                    'message' => "TLS verification probe failed: {$probeResult['message']}",
                ];
            }

            // Transition domain and request to active / succeeded
            $domain->update([
                'status'     => SchoolDomain::STATUS_ACTIVE,
                'ssl_status' => SchoolDomain::SSL_ACTIVE,
            ]);

            $request->update([
                'status'          => DomainProvisioningRequest::STATUS_SUCCEEDED,
                'safe_error_code' => null,
                'completed_at'    => now(),
            ]);

            return [
                'success' => true,
                'message' => "Domain '{$domain->hostname}' successfully verified and activated.",
            ];
        });
    }

    /**
     * Mark a provisioning request as failed with a sanitized, controlled error code.
     *
     * @return array{success: bool, message: string}
     */
    public function markFailed(int $requestId, int $domainId, string $rawErrorCode): array
    {
        $safeErrorCode = in_array($rawErrorCode, DomainProvisioningRequest::SAFE_ERROR_CODES, true)
            ? $rawErrorCode
            : DomainProvisioningRequest::ERROR_ACTIVATION_FAILED;

        return DB::transaction(function () use ($requestId, $domainId, $safeErrorCode) {
            $request = DomainProvisioningRequest::with('schoolDomain')
                ->where('id', $requestId)
                ->lockForUpdate()
                ->first();

            if (! $request) {
                return ['success' => false, 'message' => "Provisioning request #{$requestId} not found."];
            }

            if ($request->school_domain_id !== $domainId) {
                return ['success' => false, 'message' => "Request #{$requestId} does not match domain ID #{$domainId}."];
            }

            $request->update([
                'status'          => DomainProvisioningRequest::STATUS_FAILED,
                'safe_error_code' => $safeErrorCode,
                'completed_at'    => now(),
            ]);

            if ($request->schoolDomain && $request->schoolDomain->status !== SchoolDomain::STATUS_ACTIVE) {
                $request->schoolDomain->update(['ssl_status' => SchoolDomain::SSL_FAILED]);
            }

            return [
                'success' => true,
                'message' => "Request #{$requestId} marked as failed with code: {$safeErrorCode}.",
            ];
        });
    }

    /**
     * Get the sanitized provisioning status payload for UI representation.
     *
     * @return array{is_provisioning: bool, request_status: ?string, safe_error: ?string, can_activate: bool, can_retry: bool, retry_cooldown_remaining_seconds: int, max_attempts_reached: bool}
     */
    public function getStatusForUi(SchoolDomain $domain, ?User $user = null): array
    {
        $latestRequest = $domain->latestProvisioningRequest;

        $isProvisioning = $latestRequest && $latestRequest->isActive();
        $requestStatus  = $latestRequest?->status;
        $safeError      = $latestRequest?->safe_error_code;

        $isSuperAdmin = $user && $user->hasRole('super-admin');

        $maxAttempts        = (int) config('tenancy.provisioning.max_attempts', 5);
        $maxAttemptsReached = $latestRequest ? ($latestRequest->attempt_count >= $maxAttempts) : false;

        $cooldownMinutes  = (int) config('tenancy.provisioning.retry_cooldown_minutes', 5);
        $remainingCooldown = 0;
        if ($latestRequest && $latestRequest->isFailed() && $latestRequest->completed_at) {
            $cooldownEnd = $latestRequest->completed_at->copy()->addMinutes($cooldownMinutes);
            if ($cooldownEnd->gt(now())) {
                $remainingCooldown = now()->diffInSeconds($cooldownEnd);
            }
        }

        $canActivate = $isSuperAdmin
            && $domain->isCustom()
            && $domain->status === SchoolDomain::STATUS_VERIFIED
            && ! $isProvisioning
            && ! $maxAttemptsReached
            && $remainingCooldown === 0
            && ($domain->ssl_status !== SchoolDomain::SSL_ACTIVE || $domain->status !== SchoolDomain::STATUS_ACTIVE);

        $canRetry = $isSuperAdmin
            && $domain->isCustom()
            && $domain->status === SchoolDomain::STATUS_VERIFIED
            && $latestRequest
            && $latestRequest->isFailed()
            && ! $isProvisioning
            && ! $maxAttemptsReached
            && $remainingCooldown === 0;

        return [
            'is_provisioning'                  => $isProvisioning,
            'request_status'                   => $requestStatus,
            'safe_error'                       => $safeError,
            'can_activate'                     => $canActivate,
            'can_retry'                        => $canRetry,
            'retry_cooldown_remaining_seconds' => $remainingCooldown,
            'max_attempts_reached'             => $maxAttemptsReached,
        ];
    }
}
