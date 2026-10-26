<?php

declare(strict_types=1);

namespace CMS\Modules\Site\Support;

use CMS\Modules\Site\Exceptions\InvalidHostException;

/**
 * Validates and normalizes an HTTP Host header into a canonical domain key.
 *
 * This is a security boundary, not a convenience helper. Every request's Host
 * header passes through here before it can influence tenant resolution, so a
 * crafted header must never be coerced onto another site. Anything that is not
 * unambiguously a DNS hostname is rejected rather than repaired.
 *
 * Canonical form produced:
 *  - lowercase
 *  - port removed
 *  - single trailing dot removed
 *  - no scheme, no path, no userinfo, no surrounding whitespace
 */
final class HostNormalizer
{
    /** RFC 1035 total hostname limit. */
    private const MAX_LENGTH = 253;

    /** Maximum length of a single DNS label. */
    private const MAX_LABEL_LENGTH = 63;

    /**
     * Normalize a raw Host header value.
     *
     * @throws InvalidHostException when the value cannot be a DNS hostname
     */
    public function normalize(string $host): string
    {
        // Control characters are rejected on the RAW value, before any trimming.
        // trim() would otherwise silently remove a trailing NUL or CRLF and turn
        // a smuggling attempt into an apparently valid host.
        if (preg_match('/[\x00-\x1f\x7f]/', $host) === 1) {
            throw new InvalidHostException('Host header contains control characters.');
        }

        $candidate = trim($host, ' ');

        if ($candidate === '') {
            throw new InvalidHostException('Host header is empty.');
        }

        // Any surviving space is internal, which is never valid in a hostname.
        if (str_contains($candidate, ' ')) {
            throw new InvalidHostException('Host header contains illegal characters.');
        }

        // A scheme, path or query means this is not a bare Host header.
        if (str_contains($candidate, '/') || str_contains($candidate, '?') || str_contains($candidate, '#')) {
            throw new InvalidHostException('Host header must not contain a path or scheme.');
        }

        // Userinfo is never valid in a Host header.
        if (str_contains($candidate, '@')) {
            throw new InvalidHostException('Host header must not contain user information.');
        }

        if (str_contains($candidate, ':')) {
            $candidate = $this->stripPort($candidate);
        }

        $candidate = rtrim($candidate, '.');

        if ($candidate === '') {
            throw new InvalidHostException('Host header is empty after normalization.');
        }

        // IPv6 literals are legal in a Host header but can never map to a
        // tenant domain, so they are rejected explicitly instead of falling
        // through to a confusing "site not found".
        if (str_starts_with($candidate, '[') || str_ends_with($candidate, ']')) {
            throw new InvalidHostException('IP literal hosts cannot resolve a site.');
        }

        if (strlen($candidate) > self::MAX_LENGTH) {
            throw new InvalidHostException('Host header is too long.');
        }

        $normalized = strtolower($candidate);

        if (filter_var($normalized, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidHostException('Host header is not a valid hostname.');
        }

        $this->assertLabels($normalized);

        return $normalized;
    }

    /**
     * Normalize without throwing; returns null for unusable input.
     *
     * Used where a malformed host should simply mean "no site" rather than a
     * hard validation error (for example a background domain-map rebuild).
     */
    public function tryNormalize(string $host): ?string
    {
        try {
            return $this->normalize($host);
        } catch (InvalidHostException) {
            return null;
        }
    }

    /**
     * Strip a trailing :port from the host.
     *
     * A bare IPv4 address, bracketed IPv6 literal, or anything with more than
     * one colon is rejected earlier or here as appropriate.
     */
    private function stripPort(string $host): string
    {
        if (substr_count($host, ':') !== 1) {
            throw new InvalidHostException('Host header contains an ambiguous port separator.');
        }

        [$name, $port] = explode(':', $host, 2);

        if ($name === '') {
            throw new InvalidHostException('Host header is missing the hostname before the port.');
        }

        if ($port === '' || !ctype_digit($port)) {
            throw new InvalidHostException('Host header contains a non-numeric port.');
        }

        $portNumber = (int) $port;

        if ($portNumber < 1 || $portNumber > 65535) {
            throw new InvalidHostException('Host header contains an out-of-range port.');
        }

        return $name;
    }

    /**
     * Enforce per-label rules that FILTER_VALIDATE_DOMAIN is lenient about.
     */
    private function assertLabels(string $host): void
    {
        foreach (explode('.', $host) as $label) {
            if ($label === '') {
                throw new InvalidHostException('Host header contains an empty label.');
            }

            if (strlen($label) > self::MAX_LABEL_LENGTH) {
                throw new InvalidHostException('Host header contains an over-long label.');
            }

            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                throw new InvalidHostException('Host header contains an invalid label.');
            }
        }
    }
}
