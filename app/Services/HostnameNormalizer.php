<?php

namespace App\Services;

use InvalidArgumentException;

class HostnameNormalizer
{
    /**
     * Normalize and validate a hostname according to strict DNS standards.
     *
     * @param  string  $input
     * @return string
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(string $input): string
    {
        $raw = trim($input);

        if ($raw === '') {
            throw new InvalidArgumentException('Hostname cannot be empty.');
        }

        // Check for disallowed URL components or characters
        if (preg_match('/[\s\/\\?#@:*]/', $raw)) {
            throw new InvalidArgumentException('Hostname must not contain URLs, paths, ports, credentials, whitespace, or wildcards.');
        }

        // Check if protocol was provided
        if (str_starts_with(strtolower($raw), 'http://') || str_starts_with(strtolower($raw), 'https://')) {
            throw new InvalidArgumentException('Hostname must not include a protocol (http:// or https://).');
        }

        // Lowercase
        $hostname = strtolower($raw);

        // Strip single trailing dot if present
        if (str_ends_with($hostname, '.')) {
            $hostname = substr($hostname, 0, -1);
        }

        // Check overall length
        if (strlen($hostname) < 3 || strlen($hostname) > 253) {
            throw new InvalidArgumentException('Hostname length must be between 3 and 253 characters.');
        }

        // Split into labels
        $labels = explode('.', $hostname);
        if (count($labels) < 2) {
            throw new InvalidArgumentException('Hostname must contain at least a domain and a top-level domain.');
        }

        foreach ($labels as $label) {
            if ($label === '') {
                throw new InvalidArgumentException('Hostname contains empty DNS labels.');
            }

            if (strlen($label) > 63) {
                throw new InvalidArgumentException("DNS label '{$label}' exceeds the 63-character limit.");
            }

            // Must contain only letters, numbers, and hyphens (and not start or end with hyphen)
            if (! preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $label)) {
                throw new InvalidArgumentException("DNS label '{$label}' contains invalid characters or starts/ends with a hyphen.");
            }
        }

        return $hostname;
    }

    /**
     * Check if a hostname string is valid without throwing an exception.
     */
    public static function isValid(string $input): bool
    {
        try {
            self::normalize($input);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
