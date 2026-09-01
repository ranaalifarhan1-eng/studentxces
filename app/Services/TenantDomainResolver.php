<?php

namespace App\Services;

use App\Models\School;
use App\Models\SchoolDomain;

class TenantDomainResolver
{
    /**
     * Resolve an active tenant School from an incoming HTTP host header.
     *
     * @param  string|null  $host
     * @return School|null
     */
    public function resolveFromHost(?string $host): ?School
    {
        if (blank($host)) {
            return null;
        }

        // Strip port if present
        $cleanHost = strtolower(trim(explode(':', $host)[0]));

        // Local development exception
        if ($this->isDevelopmentHost($cleanHost)) {
            return null;
        }

        // Platform admin host exception
        if ($this->isPlatformAdminHost($cleanHost)) {
            return null;
        }

        // Lookup verified/active tenant domain
        $domain = SchoolDomain::with('school')
            ->where('hostname', $cleanHost)
            ->whereIn('status', [SchoolDomain::STATUS_ACTIVE, SchoolDomain::STATUS_VERIFIED])
            ->first();

        if ($domain && $domain->school && $domain->school->status === 'active') {
            return $domain->school;
        }

        // Fail closed for unknown, disabled, or unverified hosts
        return null;
    }

    /**
     * Check if the host is a local development environment.
     */
    public function isDevelopmentHost(string $host): bool
    {
        $cleanHost = strtolower(trim(explode(':', $host)[0]));
        $devHosts = config('tenancy.development_hosts', ['localhost', '127.0.0.1', '::1']);

        return in_array($cleanHost, array_map('strtolower', $devHosts), true);
    }

    /**
     * Check if the host is the platform admin host.
     */
    public function isPlatformAdminHost(string $host): bool
    {
        $cleanHost = strtolower(trim(explode(':', $host)[0]));
        $adminHost = strtolower(trim(config('tenancy.platform_admin_host', 'admin.edusystem.store')));

        return $cleanHost === $adminHost;
    }
}
