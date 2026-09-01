<?php

namespace App\Services;

class SystemDnsResolver implements DnsResolverInterface
{
    public function getCnameRecord(string $hostname): ?string
    {
        $records = @dns_get_record($hostname, DNS_CNAME);
        if (! empty($records) && isset($records[0]['target'])) {
            return strtolower(rtrim($records[0]['target'], '.'));
        }
        return null;
    }

    public function getTxtRecords(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_TXT);
        $results = [];
        if (! empty($records)) {
            foreach ($records as $r) {
                if (isset($r['txt'])) {
                    $results[] = trim($r['txt']);
                }
            }
        }
        return $results;
    }
}
