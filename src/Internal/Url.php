<?php

declare(strict_types=1);

namespace PingRoom\Internal;

use PingRoom\Exception\PingRoomException;

/**
 * URL checks for the base URL and an incoming-webhook URL. A bearer token or a
 * webhook secret on the wire over plain http is a secret leaked, so http is
 * accepted only for loopback development hosts (or when explicitly allowed).
 *
 * @internal
 *
 * @phpstan-type ParsedUrl array{scheme: string, host: string, origin: string, path: string, query: string, fragment: string}
 */
final class Url
{
    private function __construct() {}

    /** Whether a host is a local development host: `localhost`, `*.localhost`, `127.0.0.1`, `::1`. */
    public static function isLoopbackHost(string $host): bool
    {
        $h = strtolower(trim($host, '[]'));

        return $h === 'localhost' || $h === '127.0.0.1' || $h === '::1' || str_ends_with($h, '.localhost');
    }

    /**
     * Parse an absolute http(s) URL and refuse one that would carry a credential
     * over plain http. https always passes; http only to a loopback host, or
     * anywhere when `$allowInsecure` is set.
     *
     * The input is never echoed in an error: an incoming-webhook URL carries its
     * secret in the path, and error messages end up in logs.
     *
     * @return ParsedUrl
     */
    public static function assertSecure(string $rawUrl, bool $allowInsecure): array
    {
        $url = self::parse($rawUrl);
        if ($url === null) {
            throw new PingRoomException('Invalid URL (value withheld because it may contain a secret).', 'invalid_url');
        }
        if ($url['scheme'] === 'https') {
            return $url;
        }
        if ($url['scheme'] === 'http' && ($allowInsecure || self::isLoopbackHost(self::hostOf($url['origin'])))) {
            return $url;
        }

        throw new PingRoomException(
            'Refusing to send credentials over an insecure URL (' . $url['scheme'] . '://' . self::authorityOf($url['origin'])
            . '). Use https, or set `allowInsecure: true` for trusted local development.',
            'insecure_url',
        );
    }

    /**
     * The base URL the SDK sends to: origin plus any path prefix, without a
     * trailing slash (query and fragment are dropped).
     *
     * @param ParsedUrl $url
     */
    public static function base(array $url): string
    {
        return $url['origin'] . (preg_replace('~/\z~', '', $url['path']) ?? $url['path']);
    }

    /**
     * Strip a URL out of an error message, keeping only its origin. An
     * incoming-webhook URL carries its secret in the path, and HTTP clients quote
     * the requested URL in their errors, so a message copied from one must not
     * reach a caller's logs as it is.
     *
     * @param ParsedUrl $url
     */
    public static function redactInMessage(string $message, array $url, string $rawUrl): string
    {
        $withheld = $url['origin'] . '/[redacted]';
        $out = $message;
        $href = $url['origin'] . $url['path'] . ($url['query'] !== '' ? '?' . $url['query'] : '') . ($url['fragment'] !== '' ? '#' . $url['fragment'] : '');
        foreach ([$rawUrl, $href] as $full) {
            if ($full !== '') {
                $out = str_replace($full, $withheld, $out);
            }
        }
        $tail = $url['path'] . ($url['query'] !== '' ? '?' . $url['query'] : '') . ($url['fragment'] !== '' ? '#' . $url['fragment'] : '');
        foreach ([$tail, $url['path']] as $part) {
            if (strlen($part) > 1) {
                $out = str_replace($part, '/[redacted]', $out);
            }
        }

        return $out;
    }

    /**
     * @return ParsedUrl|null
     */
    private static function parse(string $rawUrl): ?array
    {
        // Control characters and spaces never appear in a URL the SDK should send.
        if ($rawUrl === '' || preg_match('/[\x00-\x20\x7F]/', $rawUrl) === 1) {
            return null;
        }
        $parts = parse_url($rawUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null);
        $authority = $host . ($port !== null && $port !== $default ? ':' . $port : '');

        return [
            'scheme' => $scheme,
            'host' => $host,
            'origin' => $scheme . '://' . $authority,
            'path' => $parts['path'] ?? '/',
            'query' => $parts['query'] ?? '',
            'fragment' => $parts['fragment'] ?? '',
        ];
    }

    private static function authorityOf(string $origin): string
    {
        return (string) preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $origin);
    }

    private static function hostOf(string $origin): string
    {
        $authority = self::authorityOf($origin);
        if (str_starts_with($authority, '[')) {
            $end = strpos($authority, ']');

            return $end === false ? $authority : substr($authority, 0, $end + 1);
        }

        return explode(':', $authority)[0];
    }
}
