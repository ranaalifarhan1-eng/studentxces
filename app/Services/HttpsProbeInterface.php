<?php

namespace App\Services;

interface HttpsProbeInterface
{
    /**
     * Probe a hostname over HTTPS/TLS to verify handshake success and certificate validity.
     *
     * @param string $hostname
     * @return array{success: bool, message: string, issuer?: string, valid_to?: string}
     */
    public function probe(string $hostname): array;
}
