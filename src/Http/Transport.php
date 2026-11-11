<?php

declare(strict_types=1);

namespace PingRoom\Http;

use PingRoom\Exception\PingRoomException;
use PingRoom\Internal\Json;
use PingRoom\Internal\Path;
use PingRoom\Internal\Str;
use Psr\Http\Message\ResponseInterface;

/**
 * The authenticated JSON client every API namespace sits on. It holds the token,
 * applies the base URL, encodes bodies, and maps non-2xx responses to a
 * PingRoomException.
 *
 * The token is held in a `SensitiveParameterValue`, which `var_dump()`,
 * `print_r()`, `var_export()`, `json_encode()`, `(array)` casts and
 * `serialize()` all refuse to reveal, and it is never written into an exception.
 *
 * @internal
 */
final class Transport
{
    private ?\SensitiveParameterValue $token;

    /**
     * @param string $baseUrl normalised: origin plus any path prefix, no trailing slash
     */
    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter]
        ?string $token,
        private readonly string $userAgent,
        private readonly Sender $sender,
    ) {
        $this->token = $token === null ? null : new \SensitiveParameterValue($token);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * What `var_dump()` and `print_r()` see: the origin, never the credential or the
     * HTTP client behind it.
     *
     * @return array{baseUrl: string, token: string|null}
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'token' => $this->hasToken() ? '[redacted]' : null];
    }

    public function sender(): Sender
    {
        return $this->sender;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function setToken(#[\SensitiveParameter] ?string $token): void
    {
        $this->token = $token === null ? null : new \SensitiveParameterValue($token);
    }

    public function getToken(): ?string
    {
        $value = $this->token?->getValue();

        return is_string($value) ? $value : null;
    }

    public function hasToken(): bool
    {
        $token = $this->getToken();

        return $token !== null && $token !== '';
    }

    /**
     * One JSON call. Resolves with the decoded body (`null` for an empty or 204
     * response).
     *
     * Options: `query` (null values are left out), `json` (present: the body, even
     * when null), `multipart` (`filename`, `contents`, `contentType`), `headers`,
     * `auth` (false for a public endpoint) and `timeout` (seconds).
     *
     * @param array{query?: array<string, int|string|bool|null>, json?: mixed, multipart?: array{filename: string, contents: string, contentType: string}, headers?: array<string, string>, auth?: bool, timeout?: float} $options
     */
    public function request(string $method, string $path, array $options = []): mixed
    {
        $response = $this->exchange($method, $path, $options);

        return $this->parse($response);
    }

    /**
     * The undecoded response, for endpoints that return bytes rather than JSON.
     * A non-2xx still raises a PingRoomException so a caller never reads an error
     * body as if it were content.
     *
     * @param array{query?: array<string, int|string|bool|null>, headers?: array<string, string>, auth?: bool, timeout?: float} $options
     */
    public function raw(string $method, string $path, array $options = []): ResponseInterface
    {
        $options['headers'] = ['Accept' => '*/*', ...($options['headers'] ?? [])];
        $response = $this->exchange($method, $path, $options);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() > 299) {
            // Route the failure through the shared JSON error mapping.
            $this->parse($response);
        }

        return $response;
    }

    /**
     * Remove the token from a message a client wrote.
     */
    public function scrub(string $message): string
    {
        $token = $this->getToken();

        return $token === null || $token === '' ? $message : str_replace($token, '[redacted]', $message);
    }

    /**
     * @param array{query?: array<string, int|string|bool|null>, json?: mixed, multipart?: array{filename: string, contents: string, contentType: string}, headers?: array<string, string>, auth?: bool, timeout?: float} $options
     */
    private function exchange(string $method, string $path, array $options): ResponseInterface
    {
        $url = $this->buildUrl($path, $options['query'] ?? []);

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
            ...($options['headers'] ?? []),
        ];

        if (($options['auth'] ?? true) !== false) {
            $token = $this->getToken();
            if ($token === null || $token === '') {
                throw new PingRoomException(
                    'This call requires an agent token. Pass `token` to the client or call setToken().',
                    'no_token',
                );
            }
            if (preg_match('/^[\x21-\x7E]+\z/', $token) !== 1) {
                throw PingRoomException::invalidRequest('The token contains characters that cannot be sent in an HTTP header.');
            }
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $body = null;
        if (isset($options['multipart'])) {
            // The boundary is part of the Content-Type, so it is generated here.
            $part = Multipart::file('file', $options['multipart']['filename'], $options['multipart']['contents'], $options['multipart']['contentType']);
            $headers['Content-Type'] = $part['contentType'];
            $body = $part['body'];
        } elseif (array_key_exists('json', $options)) {
            $headers['Content-Type'] = 'application/json';
            $body = Json::encodeBody($options['json']);
        }

        $request = $this->sender->build($method, $url, $headers, $body);

        return $this->sender->send($request, $options['timeout'] ?? $this->sender->defaultTimeout(), $this->scrub(...));
    }

    private function parse(ResponseInterface $response): mixed
    {
        $status = $response->getStatusCode();
        if ($status === 204) {
            return null;
        }
        $ok = $status >= 200 && $status <= 299;
        $text = $this->sender->read($response, $this->scrub(...));
        $json = null;
        if (Str::jsTrim($text) !== '') {
            $valid = false;
            $decoded = Json::decode($text, $valid);
            if ($valid === true) {
                $json = $decoded;
            } elseif ($ok) {
                // A non-JSON error stays text for the error mapping below; a
                // non-JSON success is a proxy's page, not data.
                throw PingRoomException::invalidResponse($status, 'a JSON body', $text);
            } else {
                $json = $text;
            }
        }
        if (!$ok) {
            throw PingRoomException::fromResponse($status, $json, $response->getHeaderLine('Retry-After'));
        }

        return $json;
    }

    /**
     * @param array<string, int|string|bool|null> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        // Every call is relative to the base URL the token was configured for. An
        // absolute URL would carry the Bearer token to another origin unchecked.
        if (preg_match('/^[a-z][a-z\d+.-]*:/i', $path) === 1) {
            throw PingRoomException::invalidRequest('Request paths must be relative to the API base URL.');
        }
        $full = $this->baseUrl . (str_starts_with($path, '/') ? $path : '/' . $path);
        $queryString = Path::query($query);

        return $queryString === '' ? $full : $full . '?' . $queryString;
    }
}
