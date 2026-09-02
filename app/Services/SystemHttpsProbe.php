<?php

namespace App\Services;

class SystemHttpsProbe implements HttpsProbeInterface
{
    /**
     * Connects to https://{hostname}:443 with strict TLS peer and name verification.
     *
     * @param string $hostname
     * @return array{success: bool, message: string, issuer?: string, valid_to?: string}
     */
    public function probe(string $hostname): array
    {
        $normalized = HostnameNormalizer::normalize($hostname);

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'SNI_enabled'       => true,
                'peer_name'         => $normalized,
                'allow_self_signed' => false,
            ],
        ]);

        $errno = 0;
        $errstr = '';

        // Timeout 5 seconds for TLS connection
        $socket = @stream_socket_client(
            "ssl://{$normalized}:443",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (! $socket) {
            return [
                'success' => false,
                'message' => "TLS handshake failed ({$errno}): {$errstr}",
            ];
        }

        $params = stream_context_get_params($socket);
        fclose($socket);

        if (empty($params['options']['ssl']['peer_certificate'])) {
            return [
                'success' => false,
                'message' => 'No peer certificate presented during TLS handshake.',
            ];
        }

        $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        if (! $cert) {
            return [
                'success' => false,
                'message' => 'Failed to parse peer TLS certificate.',
            ];
        }

        $validTo = $cert['validTo_time_t'] ?? 0;
        if (time() > $validTo) {
            return [
                'success' => false,
                'message' => 'Certificate expired on ' . date('Y-m-d H:i:s', $validTo),
            ];
        }

        $issuer = $cert['issuer']['CN'] ?? ($cert['issuer']['O'] ?? 'Unknown Issuer');

        return [
            'success'  => true,
            'message'  => "Valid TLS certificate issued by {$issuer}",
            'issuer'   => $issuer,
            'valid_to' => date('Y-m-d H:i:s', $validTo),
        ];
    }
}
