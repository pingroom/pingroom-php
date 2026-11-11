<?php

declare(strict_types=1);

namespace PingRoom\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A hand-written PSR-18 client for tests: records every request and answers from a
 * callable `fn (RequestInterface $request, int $n): ResponseInterface` (`$n` counts
 * from 1). The callable may throw to simulate a failed connection.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var callable(RequestInterface, int): ResponseInterface */
    private $responder;

    /**
     * @param callable(RequestInterface, int): ResponseInterface $responder
     */
    public function __construct(callable $responder)
    {
        $this->responder = $responder;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return ($this->responder)($request, count($this->requests));
    }

    public function request(int $index): RequestInterface
    {
        return $this->requests[$index];
    }

    public function last(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)];
    }

    /**
     * The JSON body of a recorded request, decoded.
     *
     * @return array<string, mixed>
     */
    public function jsonBody(int $index = 0): array
    {
        $decoded = json_decode((string) $this->requests[$index]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        return $decoded;
    }

    /** The raw JSON text of a recorded request body. */
    public function rawBody(int $index = 0): string
    {
        return (string) $this->requests[$index]->getBody();
    }

    /**
     * The query of a recorded request, parsed.
     *
     * @return array<string, string>
     */
    public function query(int $index = 0): array
    {
        parse_str($this->requests[$index]->getUri()->getQuery(), $query);

        return array_map(strval(...), $query);
    }
}
