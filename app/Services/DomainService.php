<?php

namespace App\Services;

use App\Models\School;
use App\Models\SchoolDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DomainService
{
    public function __construct(
        protected DnsResolverInterface $dnsResolver
    ) {}

    /**
     * Generate or retrieve the default platform domain for a given school.
     */
    public function generateDefaultDomain(School $school): SchoolDomain
    {
        $baseDomain = config('tenancy.tenant_base_domain', 'edusystem.store');
        $slug = $school->slug ?: Str::slug($school->name);
        $hostname = HostnameNormalizer::normalize("{$slug}.{$baseDomain}");

        return DB::transaction(function () use ($school, $hostname) {
            $existing = SchoolDomain::where('school_id', $school->id)
                ->where('type', SchoolDomain::TYPE_DEFAULT)
                ->first();

            if ($existing) {
                if ($existing->hostname !== $hostname) {
                    $existing->update(['hostname' => $hostname]);
                }
                return $existing;
            }

            // Check if any primary domain exists for this school
            $hasPrimary = SchoolDomain::where('school_id', $school->id)
                ->where('is_primary', true)
                ->exists();

            return SchoolDomain::create([
                'school_id'          => $school->id,
                'hostname'           => $hostname,
                'type'               => SchoolDomain::TYPE_DEFAULT,
                'is_primary'         => ! $hasPrimary,
                'status'             => SchoolDomain::STATUS_VERIFIED,
                'verification_token' => null,
                'verified_at'        => now(),
                'ssl_status'         => SchoolDomain::SSL_PENDING,
            ]);
        });
    }

    /**
     * Check if a hostname is reserved by platform infrastructure.
     */
    public function isReservedHostname(string $hostname): bool
    {
        $normalized = HostnameNormalizer::normalize($hostname);

        $platformAdminHost = HostnameNormalizer::isValid(config('tenancy.platform_admin_host', 'admin.edusystem.store'))
            ? HostnameNormalizer::normalize(config('tenancy.platform_admin_host', 'admin.edusystem.store'))
            : 'admin.edusystem.store';

        $cnameTarget = HostnameNormalizer::isValid(config('tenancy.cname_target', 'tenants.edusystem.store'))
            ? HostnameNormalizer::normalize(config('tenancy.cname_target', 'tenants.edusystem.store'))
            : 'tenants.edusystem.store';

        if ($normalized === $platformAdminHost || $normalized === $cnameTarget) {
            return true;
        }

        $platformBase = strtolower(trim(config('tenancy.platform_base_domain', 'edusystem.store')));
        $tenantBase   = strtolower(trim(config('tenancy.tenant_base_domain', 'edusystem.store')));
        $reserved     = config('tenancy.reserved_subdomains', []);

        // Check if domain is a direct subdomain under platform or tenant base domain
        foreach (array_unique([$platformBase, $tenantBase]) as $base) {
            if ($base === '') continue;

            if ($normalized === $base) {
                return true;
            }

            if (str_ends_with($normalized, '.' . $base)) {
                $prefix = substr($normalized, 0, - (strlen($base) + 1));
                // Extract first label of prefix
                $firstLabel = explode('.', $prefix)[0];
                if (in_array($firstLabel, $reserved, true) || in_array($prefix, $reserved, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Add a custom domain for a school.
     */
    public function addCustomDomain(School $school, string $rawHostname): SchoolDomain
    {
        $hostname = HostnameNormalizer::normalize($rawHostname);

        if ($this->isReservedHostname($hostname)) {
            throw new InvalidArgumentException("The hostname '{$hostname}' is reserved by the platform.");
        }

        if (SchoolDomain::where('hostname', $hostname)->exists()) {
            throw new InvalidArgumentException("The domain '{$hostname}' is already registered.");
        }

        $token = 'stx_' . Str::random(32);

        return SchoolDomain::create([
            'school_id'          => $school->id,
            'hostname'           => $hostname,
            'type'               => SchoolDomain::TYPE_CUSTOM,
            'is_primary'         => false,
            'status'             => SchoolDomain::STATUS_PENDING,
            'verification_token' => $token,
            'verified_at'        => null,
            'ssl_status'         => SchoolDomain::SSL_PENDING,
        ]);
    }

    /**
     * Get all currently trusted CNAME targets (current target + explicitly configured legacy targets).
     *
     * @return array<string>
     */
    public function getAcceptedCnameTargets(): array
    {
        $currentTarget = config('tenancy.cname_target', 'tenants.edusystem.store');
        $legacyTargets = config('tenancy.legacy_cname_targets', []);

        $targets = array_merge([$currentTarget], is_array($legacyTargets) ? $legacyTargets : []);

        return array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $targets)))));
    }

    /**
     * Attempt DNS verification for a domain using the injected DNS resolver.
     */
    public function verifyDomain(SchoolDomain $domain): bool
    {
        if ($domain->isDefault()) {
            return true;
        }

        $acceptedTargets = $this->getAcceptedCnameTargets();

        // 1. Check CNAME record against all explicitly configured accepted targets
        $cname = $this->dnsResolver->getCnameRecord($domain->hostname);
        if ($cname !== null && in_array(strtolower($cname), $acceptedTargets, true)) {
            $domain->update([
                'status'      => SchoolDomain::STATUS_VERIFIED,
                'ssl_status'  => SchoolDomain::SSL_PENDING,
                'verified_at' => now(),
            ]);
            return true;
        }

        // 2. Check TXT challenge record
        if (! empty($domain->verification_token)) {
            $challengeHost = '_studentxces-challenge.' . $domain->hostname;
            $txtRecords = $this->dnsResolver->getTxtRecords($challengeHost);
            if (in_array($domain->verification_token, $txtRecords, true)) {
                $domain->update([
                    'status'      => SchoolDomain::STATUS_VERIFIED,
                    'ssl_status'  => SchoolDomain::SSL_PENDING,
                    'verified_at' => now(),
                ]);
                return true;
            }
        }

        $domain->update(['status' => SchoolDomain::STATUS_FAILED]);
        return false;
    }

    /**
     * Set a verified or active domain as the primary domain for its school.
     */
    public function setPrimary(SchoolDomain $domain): void
    {
        if (! in_array($domain->status, [SchoolDomain::STATUS_ACTIVE, SchoolDomain::STATUS_VERIFIED], true)) {
            throw new InvalidArgumentException('Cannot make an unverified or inactive domain primary.');
        }

        DB::transaction(function () use ($domain) {
            // Acquire row locks to prevent concurrent race conditions
            SchoolDomain::where('school_id', $domain->school_id)
                ->lockForUpdate()
                ->get();

            SchoolDomain::where('school_id', $domain->school_id)
                ->update(['is_primary' => false]);

            $domain->update(['is_primary' => true]);
        });
    }

    /**
     * Delete a domain with safe primary fallback and concurrency locks.
     */
    public function deleteDomain(SchoolDomain $domain): void
    {
        DB::transaction(function () use ($domain) {
            $schoolId = $domain->school_id;

            // Acquire row locks for all domains of this school
            SchoolDomain::where('school_id', $schoolId)
                ->lockForUpdate()
                ->get();

            $totalCount = SchoolDomain::where('school_id', $schoolId)->count();
            if ($domain->isDefault() && $totalCount === 1) {
                throw new InvalidArgumentException('Cannot delete the only default platform domain.');
            }

            $wasPrimary = $domain->is_primary;

            $domain->delete();

            if ($wasPrimary) {
                $fallback = SchoolDomain::where('school_id', $schoolId)
                    ->where('type', SchoolDomain::TYPE_DEFAULT)
                    ->first()
                    ?? SchoolDomain::where('school_id', $schoolId)
                        ->whereIn('status', [SchoolDomain::STATUS_ACTIVE, SchoolDomain::STATUS_VERIFIED])
                        ->first();

                if ($fallback) {
                    $fallback->update(['is_primary' => true]);
                }
            }
        });
    }

    /**
     * Activate a domain for production traffic after rigorous DNS and HTTPS/TLS validation.
     *
     * @param SchoolDomain $domain
     * @param HttpsProbeInterface $httpsProbe
     * @return array{success: bool, message: string, domain?: SchoolDomain}
     */
    public function activateDomain(SchoolDomain $domain, HttpsProbeInterface $httpsProbe): array
    {
        // 1. Precondition: Domain must be in verified or active state
        if (! in_array($domain->status, [SchoolDomain::STATUS_VERIFIED, SchoolDomain::STATUS_ACTIVE], true)) {
            return [
                'success' => false,
                'message' => "Domain must be in 'verified' or 'active' status before activation. Current status: '{$domain->status}'.",
            ];
        }

        // 2. Precondition: Owning school must exist and be active
        $school = $domain->school;
        if (! $school || $school->status !== 'active') {
            return [
                'success' => false,
                'message' => "The owning school for this domain is inactive or does not exist.",
            ];
        }

        // 3. Re-verify DNS ownership immediately before activation
        if ($domain->isCustom()) {
            $dnsOk = $this->verifyDomain($domain);
            if (! $dnsOk) {
                return [
                    'success' => false,
                    'message' => "DNS re-verification failed. Ensure CNAME or TXT challenge records are configured correctly.",
                ];
            }
        }

        // 4. Validate HTTPS / TLS handshake and certificate
        $tlsResult = $httpsProbe->probe($domain->hostname);
        if (! $tlsResult['success']) {
            return [
                'success' => false,
                'message' => "HTTPS TLS probe failed: {$tlsResult['message']}",
            ];
        }

        // 5. Apply activation transition in transaction
        return DB::transaction(function () use ($domain, $school, $tlsResult) {
            $lockedDomain = SchoolDomain::where('id', $domain->id)->lockForUpdate()->first();

            $prevStatus = $lockedDomain->status;
            $prevSsl    = $lockedDomain->ssl_status;

            $lockedDomain->update([
                'status'     => SchoolDomain::STATUS_ACTIVE,
                'ssl_status' => SchoolDomain::SSL_ACTIVE,
            ]);

            // Platform audit logging (hidden from tenant audit views)
            if (function_exists('activity')) {
                activity('platform')
                    ->performedOn($lockedDomain)
                    ->withProperties([
                        'hostname'        => $lockedDomain->hostname,
                        'school_id'       => $lockedDomain->school_id,
                        'school_name'     => $school->name,
                        'previous_status' => $prevStatus,
                        'new_status'      => SchoolDomain::STATUS_ACTIVE,
                        'previous_ssl'    => $prevSsl,
                        'new_ssl'         => SchoolDomain::SSL_ACTIVE,
                        'issuer'          => $tlsResult['issuer'] ?? null,
                        'valid_to'        => $tlsResult['valid_to'] ?? null,
                        'action'          => 'domain_activated',
                    ])
                    ->log("Domain [{$lockedDomain->hostname}] activated for school [{$school->name}] (#{$school->id})");
            }

            return [
                'success' => true,
                'message' => "Domain [{$lockedDomain->hostname}] successfully activated with active SSL.",
                'domain'  => $lockedDomain->fresh(),
            ];
        });
    }

    /**
     * Deactivate a domain, setting its status to disabled and removing it from tenant resolution.
     *
     * @param SchoolDomain $domain
     * @return array{success: bool, message: string, domain?: SchoolDomain}
     */
    public function deactivateDomain(SchoolDomain $domain): array
    {
        return DB::transaction(function () use ($domain) {
            $lockedDomain = SchoolDomain::where('id', $domain->id)->lockForUpdate()->first();

            $prevStatus = $lockedDomain->status;
            $wasPrimary = $lockedDomain->is_primary;
            $schoolId   = $lockedDomain->school_id;
            $school     = $lockedDomain->school;

            $lockedDomain->update([
                'status'     => SchoolDomain::STATUS_DISABLED,
                'is_primary' => false,
            ]);

            if ($wasPrimary) {
                $fallback = SchoolDomain::where('school_id', $schoolId)
                    ->where('type', SchoolDomain::TYPE_DEFAULT)
                    ->where('status', SchoolDomain::STATUS_ACTIVE)
                    ->first();

                if ($fallback) {
                    $fallback->update(['is_primary' => true]);
                }
            }

            // Platform audit logging
            if (function_exists('activity')) {
                activity('platform')
                    ->performedOn($lockedDomain)
                    ->withProperties([
                        'hostname'        => $lockedDomain->hostname,
                        'school_id'       => $lockedDomain->school_id,
                        'school_name'     => $school?->name,
                        'previous_status' => $prevStatus,
                        'new_status'      => SchoolDomain::STATUS_DISABLED,
                        'action'          => 'domain_deactivated',
                    ])
                    ->log("Domain [{$lockedDomain->hostname}] deactivated for school [{$school?->name}] (#{$schoolId})");
            }

            return [
                'success' => true,
                'message' => "Domain [{$lockedDomain->hostname}] deactivated successfully.",
                'domain'  => $lockedDomain->fresh(),
            ];
        });
    }
}
