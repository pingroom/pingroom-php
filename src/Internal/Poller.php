<?php

declare(strict_types=1);

namespace PingRoom\Internal;

use PingRoom\Clock;
use PingRoom\Exception\PingRoomException;

/**
 * The bounded wait loop behind `waitForDecision()`, `waitForAnswer()`,
 * `waitForResult()` and `listen()`: backoff, spacing, `Retry-After`, and the
 * give-up rule. The numbers are the TypeScript SDK's and the CLI's.
 *
 * @internal
 */
final class Poller
{
    /** First retry delay after a transient failure, in ms. */
    public const TRANSIENT_BACKOFF_BASE_MS = 1000;

    /**
     * The smallest backoff base, whatever a caller asks for. A persistent failure
     * would otherwise retry in a tight loop against wait routes limited to 30
     * requests a minute each.
     */
    public const MIN_TRANSIENT_BACKOFF_MS = 250;

    /** Ceiling on the exponential backoff (a server `Retry-After` may ask for longer). */
    public const TRANSIENT_BACKOFF_CAP_MS = 30_000;

    /**
     * How long past the last-seen `expires_at` a wait keeps retrying an
     * unreachable API. The server reports `expired` once `expires_at` passes, so
     * after this the only outcome left is one that cannot be read. Covers clock
     * skew.
     */
    public const WAIT_EXPIRY_GRACE_MS = 5 * 60_000;

    /** The bound on an unbroken outage while no response has carried `expires_at`. */
    public const WAIT_UNKNOWN_EXPIRY_OUTAGE_MS = 15 * 60_000;

    /**
     * Minimum spacing between the starts of two polls of one wait route. Each
     * wait route is throttled at 30 requests a minute per human; a short or zero
     * `timeout`, or a proxy that answers early, would otherwise let one loop
     * exhaust it (2100 ms keeps one loop under 30 a minute).
     */
    public const MIN_POLL_INTERVAL_MS = 2100;

    /** Extra wall-clock allowance over the server's hold window for a long-poll call. */
    public const LONG_POLL_BUFFER_SECONDS = 10;

    /** The server's default hold, in seconds, when a long-poll call names none. */
    public const DEFAULT_WAIT_SECONDS = 20;

    /** A timer's longest delay (`setTimeout` in the TypeScript SDK). */
    private const MAX_DELAY_MS = 2_147_483_647;

    /** How often a sleep that can be cancelled looks at the cancel flag. */
    private const CANCEL_CHECK_SLICE_MS = 250;

    private function __construct() {}

    /** The HTTP timeout of a long-poll call: its hold time plus a buffer. */
    public static function waitTimeoutSeconds(?int $timeout): float
    {
        return (float) (($timeout ?? self::DEFAULT_WAIT_SECONDS) + self::LONG_POLL_BUFFER_SECONDS);
    }

    /**
     * A failure a read loop may retry: a dropped connection, a request deadline, a
     * 429 (each wait route has its own 30/min bucket per human), or a 5xx.
     * Everything else (401/403/404/422, a refused redirect, a malformed response)
     * is permanent and must surface to the caller.
     */
    public static function isTransient(\Throwable $error): bool
    {
        return $error instanceof PingRoomException
            && ($error->errorCode === 'network_error'
                || $error->errorCode === 'timeout'
                || $error->status === 429
                || $error->status >= 500);
    }

    /**
     * Delay before retrying after the `$failures`-th consecutive transient failure:
     * exponential from `$baseMs`, capped at 30 s. A server `retryAfter` replaces
     * the exponential step, but is never waited for less than the base. A base
     * below {@see self::MIN_TRANSIENT_BACKOFF_MS} is raised to it.
     */
    public static function transientDelayMs(
        PingRoomException $error,
        int $failures,
        int|float $baseMs = self::TRANSIENT_BACKOFF_BASE_MS,
    ): int {
        $requested = is_finite((float) $baseMs) && $baseMs >= 0 ? $baseMs : self::TRANSIENT_BACKOFF_BASE_MS;
        $base = max(self::MIN_TRANSIENT_BACKOFF_MS, $requested);
        $retryAfter = $error->retryAfter;
        if ($retryAfter !== null && is_finite((float) $retryAfter) && $retryAfter >= 0) {
            return (int) round(min(self::MAX_DELAY_MS, max($retryAfter * 1000, $base)));
        }
        // Enough doublings to pass the cap from any base; also keeps 2 ** step finite.
        $step = min(max(0, $failures - 1), 30);

        return (int) round(min(self::MAX_DELAY_MS, max($base, self::TRANSIENT_BACKOFF_CAP_MS), $base * 2 ** $step));
    }

    /**
     * The shared human-in-the-loop wait loop. Polls until a validated response is
     * terminal; retries transient failures with backoff; stops when `$isCancelled`
     * says so. A response that fails validation is never returned as an outcome.
     *
     * The retries are bounded even without a cancel flag. Once only failures have
     * come back since the last valid response, and the last-seen `expires_at` plus
     * {@see self::WAIT_EXPIRY_GRACE_MS} has passed (or, before any `expires_at` was
     * seen, after {@see self::WAIT_UNKNOWN_EXPIRY_OUTAGE_MS} of unbroken failures),
     * the wait throws `unavailable`, the last failure as its previous exception.
     *
     * @param callable(): mixed                            $poll         one bounded long-poll; its errors are retried only when transient
     * @param callable(mixed): array<array-key, mixed>     $validate     check the response's minimal shape; throw `invalid_response` otherwise
     * @param callable(array<array-key, mixed>): bool      $settled      true once the validated value is terminal
     * @param (callable(array<array-key, mixed>): mixed)|null $expiresAt reads `expires_at` (an ISO time) from a validated response; it bounds an outage
     * @param string                                       $what         names what is waited on in the give-up message, e.g. `approval a1`
     * @param (callable(PingRoomException, int): void)|null $onRetry     called before each retry of a transient failure; throwing ends the wait with the thrown error
     * @param (callable(): bool)|null                      $isCancelled
     *
     * @return array<array-key, mixed>
     */
    public static function pollUntilSettled(
        Clock $clock,
        callable $poll,
        callable $validate,
        callable $settled,
        string $what,
        int $minIntervalMs = 0,
        ?callable $expiresAt = null,
        ?callable $onRetry = null,
        ?callable $isCancelled = null,
    ): array {
        $failures = 0;
        $failingSince = null;
        // expires_at plus grace, from the last valid response that carried one.
        $deadline = null;
        while (true) {
            self::throwIfCancelled($isCancelled);
            $startedAt = $clock->nowMs();
            try {
                $value = $validate($poll());
            } catch (PingRoomException $error) {
                self::throwIfCancelled($isCancelled);
                if (!self::isTransient($error)) {
                    throw $error;
                }
                ++$failures;
                $failingSince ??= $clock->nowMs();
                $remaining = ($deadline ?? $failingSince + self::WAIT_UNKNOWN_EXPIRY_OUTAGE_MS) - $clock->nowMs();
                if ($remaining <= 0) {
                    throw self::unavailable($error, $failingSince, $what);
                }
                if ($onRetry !== null) {
                    $onRetry($error, $failures);
                }
                // A fast failure still consumes a poll. Keep retries spaced even when
                // a pending response between failures resets the exponential backoff.
                $delay = max(self::transientDelayMs($error, $failures), $minIntervalMs - ($clock->nowMs() - $startedAt));
                // Do not retry earlier than the interval or Retry-After just to fit the
                // outage bound. Wait out the budget, then stop without another request.
                if ($delay > $remaining) {
                    self::sleep($clock, $remaining, $isCancelled);
                    self::throwIfCancelled($isCancelled);
                    throw self::unavailable($error, $failingSince, $what);
                }
                self::sleep($clock, $delay, $isCancelled);

                continue;
            }
            $failures = 0;
            $failingSince = null;
            $expiry = $expiresAt !== null ? self::timeOf($expiresAt($value)) : null;
            if ($expiry !== null) {
                $deadline = $expiry + self::WAIT_EXPIRY_GRACE_MS;
            }
            if ($settled($value)) {
                return $value;
            }
            self::sleep($clock, max(0, $minIntervalMs - ($clock->nowMs() - $startedAt)), $isCancelled);
        }
    }

    /**
     * Sleep `$ms`, looking at the cancel flag every quarter second so a caller's
     * stop takes effect promptly. Returns early, without throwing, once cancelled.
     *
     * @param (callable(): bool)|null $isCancelled
     */
    public static function sleep(Clock $clock, int $ms, ?callable $isCancelled): void
    {
        if ($ms <= 0) {
            return;
        }
        if ($isCancelled === null) {
            $clock->sleepMs($ms);

            return;
        }
        while ($ms > 0 && !$isCancelled()) {
            $slice = min($ms, self::CANCEL_CHECK_SLICE_MS);
            $clock->sleepMs($slice);
            $ms -= $slice;
        }
    }

    /**
     * Throw once the caller has asked the wait to stop.
     *
     * @param (callable(): bool)|null $isCancelled
     */
    public static function throwIfCancelled(?callable $isCancelled): void
    {
        if ($isCancelled !== null && $isCancelled()) {
            throw new PingRoomException('The wait was cancelled by the caller.', 'cancelled');
        }
    }

    /** Milliseconds since the epoch for an ISO 8601 string, or null when it is not one. */
    public static function timeOf(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]|\z)/', $value) !== 1) {
            return null;
        }
        try {
            $time = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return (int) $time->format('U') * 1000 + (int) $time->format('v');
    }

    private static function unavailable(PingRoomException $last, int $since, string $what): PingRoomException
    {
        $reason = $last->status > 0 ? "HTTP {$last->status}" : ($last->errorCode ?? 'network error');
        $at = gmdate('Y-m-d\TH:i:s', intdiv($since, 1000)) . sprintf('.%03dZ', $since % 1000);

        return new PingRoomException(
            "PingRoom unavailable since {$at} ({$reason}); stopped waiting for {$what}.",
            'unavailable',
            0,
            null,
            null,
            $last,
        );
    }
}
