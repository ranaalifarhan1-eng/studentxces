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
    public function requestProvisioning(SchoolDomain $domain, User $actor): array
    {
        // 1. Authorization check: Super Admin only
        if (! $actor->hasRole('super-admin')) {
            return [
                'success' => false,
                'message' => 'Unauthorized: Infrastructure provisioning can only be initiated by platform Super Administrators.',
                'code'    => 'unauthorized',
            ];
        }

        // 2. Validate hostname and domain prerequisites
        try {
            TenantHostnameValidator::validateForProvisioning($domain);
        } catch (InvalidArgumentException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'code'    => 'invalid_precondition',
            ];
        }

        // 3. Check if already active and secure
        if ($domain->status === SchoolDomain::STATUS_ACTIVE && $domain->ssl_status === SchoolDomain::SSL_ACTIVE) {
            return [
                'success' => true,
                'message' => "Domain '{$domain->hostname}' is already active with valid SSL. No provisioning required.",
                'code'    => 'already_active',
            ];
        }

        // 4. Concurrency & active request check
        return DB::transaction(function () use ($domain, $actor) {
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

            // Create new queued request
            $request = DomainProvisioningRequest::create([
                'school_domain_id' => $domain->id,
                'requested_by'     => $actor->id,
                'action'           => DomainProvisioningRequest::ACTION_PROVISION,
                'status'           => DomainProvisioningRequest::STATUS_QUEUED,
                'attempt_count'    => 0,
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
     * Atomically claim the next queued provisioning request for the host runner.
     */
    public function claimNextRequest(): ?DomainProvisioningRequest
    {
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

            if ($request->schoolDomain) {
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
     * @return array{is_provisioning: bool, request_status: ?string, safe_error: ?string, can_activate: bool, can_retry: bool}
     */
    public function getStatusForUi(SchoolDomain $domain, ?User $user = null): array
    {
        $latestRequest = $domain->latestProvisioningRequest;

        $isProvisioning = $latestRequest && $latestRequest->isActive();
        $requestStatus  = $latestRequest?->status;
        $safeError      = $latestRequest?->safe_error_code;

        $isSuperAdmin = $user && $user->hasRole('super-admin');

        $canActivate = $isSuperAdmin
            && $domain->isCustom()
            && $domain->status === SchoolDomain::STATUS_VERIFIED
            && ! $isProvisioning
            && ($domain->ssl_status !== SchoolDomain::SSL_ACTIVE || $domain->status !== SchoolDomain::STATUS_ACTIVE);

        $canRetry = $isSuperAdmin
            && $domain->isCustom()
            && $domain->status === SchoolDomain::STATUS_VERIFIED
            && $latestRequest
            && $latestRequest->isFailed()
            && ! $isProvisioning;

        return [
            'is_provisioning' => $isProvisioning,
            'request_status'  => $requestStatus,
            'safe_error'      => $safeError,
            'can_activate'    => $canActivate,
            'can_retry'       => $canRetry,
        ];
    }
}
