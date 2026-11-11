<?php

declare(strict_types=1);

namespace PingRoom\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use PingRoom\Client;
use PingRoom\Clock;
use PingRoom\Exception\PingRoomException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

abstract class TestCase extends PHPUnitTestCase
{
    protected const TOKEN = 'agt_live_fixture_credential_5f2c9e';

    /**
     * A client wired to a mock HTTP client.
     *
     * @param callable(RequestInterface, int): ResponseInterface $responder
     *
     * @return array{Client, MockHttpClient}
     */
    protected function clientWith(callable $responder, ?Clock $clock = null, ?string $token = self::TOKEN, string $baseUrl = 'https://api.pingroom.io'): array
    {
        $http = new MockHttpClient($responder);
        $factory = new Psr17Factory();
        $client = new Client(
            token: $token,
            baseUrl: $baseUrl,
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
            clock: $clock ?? new FakeClock(),
        );

        return [$client, $http];
    }

    /**
     * A client whose every call is answered with the same JSON.
     *
     * @return array{Client, MockHttpClient}
     */
    protected function clientAnswering(mixed $body, int $status = 200): array
    {
        return $this->clientWith(fn(): ResponseInterface => self::json($status, $body));
    }

    /**
     * Run `$fn` and return the PingRoomException it throws.
     *
     * @param callable(): mixed $fn
     */
    protected function thrown(callable $fn): PingRoomException
    {
        try {
            $fn();
        } catch (PingRoomException $e) {
            return $e;
        }

        self::fail('Expected a PingRoomException, but nothing was thrown.');
    }

    /**
     * The JSON text of a request's body is the wire contract, so tests read it as text too.
     */
    protected static function decode(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $headers
     */
    protected static function json(int $status, mixed $body, array $headers = []): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json', ...$headers],
            $body === null ? '' : (is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    protected static function raw(int $status, string $body, array $headers = []): ResponseInterface
    {
        return new Response($status, $headers, $body);
    }
}
