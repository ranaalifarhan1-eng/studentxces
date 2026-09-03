<?php

namespace App\Services;

use App\Models\SchoolDomain;
use InvalidArgumentException;

class TenantHostnameValidator
{
    /**
     * Validate and normalize a hostname for tenant domain provisioning.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string $rawHostname): string
    {
        $hostname = trim(strtolower($rawHostname));

        if (empty($hostname)) {
            throw new InvalidArgumentException('Hostname cannot be empty.');
        }

        // Reject URI schemes, paths, ports, query strings
        if (str_contains($hostname, '://') || str_contains($hostname, '/') || str_contains($hostname, ':') || str_contains($hostname, '?')) {
            throw new InvalidArgumentException("Hostname '{$rawHostname}' must be a plain domain name without URI schemes, ports, or paths.");
        }

        // Reject shell metacharacters and control characters
        if (preg_match('/[;&|`$><(){}\[\]*!?~\\\\\"\'\s]/', $hostname)) {
            throw new InvalidArgumentException("Hostname '{$rawHostname}' contains invalid characters or shell metacharacters.");
        }

        // Overall FQDN length constraint
        if (strlen($hostname) > 253) {
            throw new InvalidArgumentException("Hostname exceeds maximum allowable length of 253 characters.");
        }

        // Reject IP addresses (IPv4 and IPv6)
        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException("IP addresses are not permitted as tenant hostnames.");
        }

        // Reject localhost and single label hosts
        if ($hostname === 'localhost' || ! str_contains($hostname, '.')) {
            throw new InvalidArgumentException("Hostname must be a fully qualified domain name with at least two labels.");
        }

        // RFC 1123 label validation
        $labels = explode('.', $hostname);
        if (count($labels) < 2) {
            throw new InvalidArgumentException("Hostname must contain at least one dot separating domain labels.");
        }

        foreach ($labels as $label) {
            if ($label === '') {
                throw new InvalidArgumentException("Hostname contains empty labels (consecutive or trailing dots).");
            }
            if (strlen($label) > 63) {
                throw new InvalidArgumentException("Domain label '{$label}' exceeds maximum allowable length of 63 characters.");
            }
            if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                throw new InvalidArgumentException("Domain label '{$label}' contains invalid characters or start/end hyphens.");
            }
        }

        // Check protected hosts denylist
        $protectedHosts = config('tenancy.protected_hosts', []);
        if (in_array($hostname, $protectedHosts, true)) {
            throw new InvalidArgumentException("Hostname '{$hostname}' is a protected platform domain and cannot be provisioned.");
        }

        return $hostname;
    }

    /**
     * Validate an existing domain model against all provisioning prerequisites.
     *
     * @throws InvalidArgumentException
     */
    public static function validateForProvisioning(SchoolDomain $domain): void
    {
        self::validate($domain->hostname);

        if (! $domain->isCustom()) {
            throw new InvalidArgumentException("Only custom domains can undergo automated infrastructure provisioning.");
        }

        if (! $domain->school || $domain->school->status !== 'active') {
            throw new InvalidArgumentException("Owning school is inactive or does not exist.");
        }

        if ($domain->status !== SchoolDomain::STATUS_VERIFIED && $domain->status !== SchoolDomain::STATUS_ACTIVE) {
            throw new InvalidArgumentException("Domain must be DNS verified before infrastructure provisioning. Current status: '{$domain->status}'.");
        }
    }
}
