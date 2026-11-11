<?php

declare(strict_types=1);

namespace PingRoom;

/**
 * Verify a webhook PingRoom sent you: an outgoing room webhook, or the delivery of
 * a Ping button (a `webhook` action's tap, signed with that Ping's
 * `action_signing_secret`). Both use the same scheme.
 *
 * New deliveries carry two lowercase-hex HMAC-SHA256 signatures:
 *
 *  - `X-PingRoom-Signature-V2` signs `"v2\n{timestamp}\n{deliveryId}\n{rawBody}"`, so
 *    it binds `X-PingRoom-Delivery` as well as the timestamp and the body.
 *  - `X-PingRoom-Signature` keeps the legacy `"{timestamp}.{rawBody}"` contract so
 *    receivers written before V2 keep working.
 *
 * `timestamp` is the Unix-seconds value of `X-PingRoom-Timestamp`.
 *
 * Verify over the exact raw request body, as received: re-encoding parsed JSON can
 * change the bytes and invalidate the signature. If V2 is present, it must verify;
 * V1 is tried only when V2 is absent. A timestamp more than five minutes from now
 * (by default) is refused, so a captured delivery cannot be replayed later.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'X-PingRoom-Signature';

    public const SIGNATURE_V2_HEADER = 'X-PingRoom-Signature-V2';

    public const TIMESTAMP_HEADER = 'X-PingRoom-Timestamp';

    public const DELIVERY_HEADER = 'X-PingRoom-Delivery';

    public const EVENT_HEADER = 'X-PingRoom-Event';

    /** The default replay window, in seconds. */
    public const DEFAULT_MAX_AGE_SECONDS = 300;

    private function __construct() {}

    /**
     * Constant-time verification of a webhook signature. Returns a boolean and never
     * throws on a mismatch. Pass `requireV2: true` so a delivery without the V2
     * signature is refused rather than checked against V1: V1 does not sign
     * `X-PingRoom-Delivery`, so a captured body replayed without its V2 header under
     * a new delivery id would still pass V1. Every current delivery carries V2, so set
     * it, and always set it when you dedupe on the delivery id.
     *
     * `$payload` is a string and must be the raw body: a parsed or re-encoded body
     * fails verification. (Passing anything that is not a string is a TypeError, not
     * `false`, so a receiver that never captured the raw bytes is not mistaken for a
     * forged delivery.)
     *
     * @param string          $payload       the raw request body, exactly as received
     * @param string          $secret        the webhook's `signing_secret`, or a Ping's `action_signing_secret` for a button delivery
     * @param string|null     $signature     the legacy `X-PingRoom-Signature` value, with or without the `sha256=` prefix; tried only when `$signatureV2` is absent and `$requireV2` is not set
     * @param string|null     $signatureV2   the `X-PingRoom-Signature-V2` value; when present, V2 must verify and V1 is not tried
     * @param string|int|null $timestamp     the `X-PingRoom-Timestamp` header value (Unix seconds)
     * @param string|null     $deliveryId    the `X-PingRoom-Delivery` value the V2 signed message needs
     * @param bool            $requireV2     refuse a delivery that carries no V2 signature
     * @param int|float       $maxAgeSeconds allowed timestamp skew in seconds (default 300)
     * @param int|float|null  $now           the current Unix time, for tests (default: the real time)
     */
    public static function verifySignature(
        string $payload,
        string $secret,
        ?string $signature = null,
        ?string $signatureV2 = null,
        string|int|null $timestamp = null,
        ?string $deliveryId = null,
        bool $requireV2 = false,
        int|float $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS,
        int|float|null $now = null,
    ): bool {
        $hasV2 = $signatureV2 !== null;
        if ($requireV2 && !$hasV2) {
            return false;
        }
        $selected = $hasV2 ? $signatureV2 : $signature;
        if ($selected === null || $selected === '' || $secret === '') {
            return false;
        }
        $ts = self::normalizeTimestamp($timestamp);
        if ($ts === null) {
            return false;
        }
        if (!is_finite((float) $maxAgeSeconds) || $maxAgeSeconds < 0) {
            return false;
        }
        if (abs(($now ?? microtime(true)) - (float) $ts) > $maxAgeSeconds) {
            return false;
        }
        $provided = strtolower(self::stripPrefix($selected));
        if (preg_match('/^[0-9a-f]{64}\z/', $provided) !== 1) {
            return false;
        }
        if ($hasV2) {
            if ($deliveryId === null || preg_match('/^[\x21-\x7E]{1,255}\z/', $deliveryId) !== 1) {
                return false;
            }
            $message = "v2\n{$ts}\n{$deliveryId}\n" . $payload;
        } else {
            $message = "{$ts}." . $payload;
        }

        return hash_equals(hash_hmac('sha256', $message, $secret), $provided);
    }

    private static function stripPrefix(string $signature): string
    {
        $trimmed = trim($signature);

        return str_starts_with($trimmed, 'sha256=') ? substr($trimmed, strlen('sha256=')) : $trimmed;
    }

    private static function normalizeTimestamp(string|int|null $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }
        $normalized = is_string($timestamp) ? trim($timestamp) : (string) $timestamp;
        if (preg_match('/^\d+\z/', $normalized) !== 1) {
            return null;
        }

        // A safe integer, as JavaScript's `Number.isSafeInteger()` reads it.
        return (float) $normalized <= 9007199254740991.0 ? $normalized : null;
    }
}
