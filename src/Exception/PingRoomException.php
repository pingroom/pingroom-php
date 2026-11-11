<?php

declare(strict_types=1);

namespace PingRoom\Exception;

/**
 * Every failure of this SDK is a PingRoomException (or a subclass), so one
 * `catch` covers a refusal from the server and an input refused before sending.
 *
 * `status` is the HTTP status, or 0 for a client-side failure. `errorCode` is
 * the API's machine code (`room_not_granted`, `rate_limited`, ...) or a client
 * code (`network_error`, `invalid_request`, ...); see {@see \PingRoom\ErrorCodes}.
 * `retryAfter` is the seconds to wait before retrying, when the API gave one
 * (retry any 429 after it, whatever its code). `body` is the decoded response
 * body, or the raw text when it was not JSON. The agent token is never stored on
 * an exception, in its message or in its body.
 */
class PingRoomException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly int $status = 0,
        public readonly int|float|null $retryAfter = null,
        public readonly mixed $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Map a non-2xx response. The code is the body's `code`, else its `error`;
     * the message is the body's `message`, else the code, else `HTTP <status>`.
     * `retryAfter` is the body's `retry_after`, else a whole-seconds
     * `Retry-After` header.
     */
    public static function fromResponse(int $status, mixed $body, ?string $retryAfterHeader = null): self
    {
        $fields = is_array($body) ? $body : [];

        $code = null;
        foreach (['code', 'error'] as $key) {
            if (isset($fields[$key]) && is_string($fields[$key]) && $fields[$key] !== '') {
                $code = $fields[$key];
                break;
            }
        }

        $message = isset($fields['message']) && is_string($fields['message']) && $fields['message'] !== ''
            ? $fields['message']
            : ($code ?? "HTTP {$status}");

        $retryAfter = null;
        if (isset($fields['retry_after']) && (is_int($fields['retry_after']) || is_float($fields['retry_after']))) {
            $retryAfter = $fields['retry_after'];
        } elseif ($retryAfterHeader !== null) {
            $header = trim($retryAfterHeader);
            if (preg_match('/^\d+\z/', $header) === 1) {
                $retryAfter = (int) $header;
            }
        }

        return new self($message, $code, $status, $retryAfter, $body);
    }

    /** Input refused before any request was made. */
    public static function invalidRequest(string $message): self
    {
        return new self($message, 'invalid_request');
    }

    /** A `data` (or other structured) value refused before any request was made. */
    public static function invalidData(string $message): self
    {
        return new self($message, 'invalid_data');
    }

    /**
     * A 2xx the PingRoom API never sends: a gateway's page, a captive portal, or
     * a URL that is not PingRoom's. The body is kept short, for inspection only.
     */
    public static function invalidResponse(int $status, string $expected, mixed $body): self
    {
        return new self(
            "PingRoom returned an invalid response (HTTP {$status}): expected {$expected}.",
            'invalid_response',
            0,
            null,
            is_string($body) ? mb_substr($body, 0, 512, 'UTF-8') : $body,
        );
    }
}
