<?php

namespace App\Services;

use InvalidArgumentException;

class CertbotCommandBuilder
{
    /**
     * Build an argv-safe argument array for issuing a dedicated per-tenant certificate via webroot.
     *
     * @return array<int, string>
     */
    public static function buildIssuanceArgv(int $domainId, string $hostname, string $webroot = '/var/www/html'): array
    {
        if ($domainId <= 0) {
            throw new InvalidArgumentException("Domain ID must be a positive integer.");
        }

        $validatedHost = TenantHostnameValidator::validate($hostname);
        $certName      = "studentxces-tenant-{$domainId}";

        return [
            'certbot',
            'certonly',
            '--webroot',
            '-w',
            $webroot,
            '-d',
            $validatedHost,
            '--cert-name',
            $certName,
            '--non-interactive',
            '--agree-tos',
        ];
    }

    /**
     * Build an argv-safe argument array for revoking/deleting a tenant certificate.
     *
     * @return array<int, string>
     */
    public static function buildDeletionArgv(int $domainId): array
    {
        if ($domainId <= 0) {
            throw new InvalidArgumentException("Domain ID must be a positive integer.");
        }

        $certName = "studentxces-tenant-{$domainId}";

        return [
            'certbot',
            'delete',
            '--cert-name',
            $certName,
            '--non-interactive',
        ];
    }
}
