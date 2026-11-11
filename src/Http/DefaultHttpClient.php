<?php

declare(strict_types=1);

namespace PingRoom\Http;

use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr18ClientDiscovery;
use PingRoom\Exception\PingRoomException;
use Psr\Http\Client\ClientInterface;

/**
 * The PSR-18 client the SDK builds when you do not pass one: Guzzle if it is
 * installed, else Symfony's HttpClient, else whatever `php-http/discovery` finds.
 *
 * Guzzle and Symfony are configured here with the timeout and with no automatic
 * redirects. A client found by discovery is used as it comes: if you rely on it,
 * check that it does not follow redirects, or pass your own configured client.
 * (Guzzle's PSR-18 `sendRequest()` never follows them; Symfony's follows up to 20
 * unless told not to, and replays a 307/308 body to the new location.)
 *
 * @internal
 */
final class DefaultHttpClient
{
    /** The connect timeout, in seconds, at most. */
    private const CONNECT_TIMEOUT_SECONDS = 10.0;

    private function __construct() {}

    /**
     * @param float $timeout seconds the whole request may take
     */
    public static function create(float $timeout): ClientInterface
    {
        if (class_exists(\GuzzleHttp\Client::class)) {
            return self::guzzle($timeout);
        }
        if (class_exists(\Symfony\Component\HttpClient\Psr18Client::class)) {
            $client = self::symfony($timeout);
            if ($client !== null) {
                return $client;
            }
        }

        try {
            return Psr18ClientDiscovery::find();
        } catch (NotFoundException) {
            throw new PingRoomException(
                'No PSR-18 HTTP client found. Install one (for example `composer require guzzlehttp/guzzle`) or pass `httpClient`.',
                'no_http_client',
            );
        }
    }

    public static function guzzle(float $timeout): ClientInterface
    {
        return new \GuzzleHttp\Client([
            'timeout' => $timeout,
            'connect_timeout' => min($timeout, self::CONNECT_TIMEOUT_SECONDS),
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }

    public static function symfony(float $timeout): ?ClientInterface
    {
        if (!class_exists(\Symfony\Component\HttpClient\HttpClient::class)) {
            return null;
        }
        try {
            return new \Symfony\Component\HttpClient\Psr18Client(
                \Symfony\Component\HttpClient\HttpClient::create([
                    'timeout' => $timeout,
                    'max_duration' => $timeout,
                    'max_redirects' => 0,
                ]),
            );
        } catch (\Throwable) {
            // No PSR-7 implementation for Symfony to build responses with: fall back.
            return null;
        }
    }
}
