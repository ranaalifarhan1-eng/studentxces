<?php

namespace App\Services;

interface DnsResolverInterface
{
    /**
     * Get CNAME target for a given hostname.
     *
     * @param  string  $hostname
     * @return string|null
     */
    public function getCnameRecord(string $hostname): ?string;

    /**
     * Get TXT records for a given hostname.
     *
     * @param  string  $hostname
     * @return array<string>
     */
    public function getTxtRecords(string $hostname): array;
}
