<?php

declare(strict_types=1);

namespace PingRoom\Api;

use PingRoom\Clock;
use PingRoom\Exception\PingRoomException;
use PingRoom\Http\Transport;
use PingRoom\Internal\Data;
use PingRoom\Internal\Guards;
use PingRoom\Internal\Path;
use PingRoom\Internal\PingActionRules;
use PingRoom\Internal\Poller;
use PingRoom\Internal\Validate;

/**
 * `$client->approvals`: two-option human-in-the-loop decisions. Ask the human you
 * act for to decide, then block until they do.
 */
final class Approvals
{
    private const STATUSES = ['pending', 'decided', 'expired', 'cancelled'];

    public function __construct(
        private readonly Transport $http,
        private readonly Clock $clock,
    ) {}

    /**
     * Ask the human you act for to decide; returns the pending approval. Reuse
     * `$idempotencyKey` when retrying after a timeout or network error: the server
     * replays the first result instead of asking the human a second time.
     *
     * @param list<string>|null                   $options
     * @param array<array-key, mixed>|object|null $data
     *
     * @return array<string, mixed>
     */
    public function request(
        string $inviteCode,
        string $question,
        ?string $title = null,
        ?array $options = null,
        ?string $correlationId = null,
        array|object|null $data = null,
        ?int $ttl = null,
        ?string $idempotencyKey = null,
    ): array {
        Guards::requireNonEmpty($question, 'question');
        $view = Data::check($data);
        PingActionRules::refuse($view, 'An approval');
        $headers = Guards::idempotencyHeaders($idempotencyKey);

        $body = array_filter([
            'question' => $question,
            'title' => $title,
            'options' => $options,
            'correlation_id' => $correlationId,
            'data' => $data === null ? null : Data::toWire($data),
            'ttl' => $ttl,
        ], static fn(mixed $value): bool => $value !== null);

        return Validate::record($this->http->request(
            'POST',
            '/api/agent/rooms/' . Path::segment($inviteCode) . '/approvals',
            ['json' => $body, 'headers' => $headers],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $approvalId): array
    {
        return Validate::record($this->http->request('GET', '/api/agent/approvals/' . Path::segment($approvalId)));
    }

    /**
     * One long-poll for the decision; returns the (possibly still-pending) approval.
     *
     * @param int|null $timeout seconds the server holds the connection (0 to 30, default 20)
     *
     * @return array<string, mixed>
     */
    public function wait(string $approvalId, ?int $timeout = null): array
    {
        return Validate::record($this->http->request(
            'GET',
            '/api/agent/approvals/' . Path::segment($approvalId) . '/wait',
            ['query' => ['timeout' => $timeout], 'timeout' => Poller::waitTimeoutSeconds($timeout)],
        ));
    }

    /**
     * Block until the human decides (or the request expires or is cancelled),
     * looping `wait()` under the hood, with polls started at least 2.1 s apart. A
     * response that is not this approval in a known status throws
     * `invalid_response`. A network failure, 429 or 5xx is retried with backoff
     * (honouring `retryAfter`) and reported to `$onRetry`, until `$isCancelled`
     * returns true or the outage outlasts the approval's `expires_at` by 5 minutes
     * (15 minutes of failures while no `expires_at` has been read); that throws
     * `unavailable` with the last failure as its previous exception.
     *
     * A human answer has `status` `decided`; read `decision` for the chosen option.
     *
     * @param int|null                                       $timeout     seconds each poll holds the connection (default 20)
     * @param (callable(PingRoomException, int): void)|null  $onRetry     called before each retry with the failure and the consecutive-failure count (1 on the first); throwing ends the wait with the thrown error
     * @param (callable(): bool)|null                        $isCancelled return true to stop waiting; the wait throws `cancelled`
     *
     * @return array<string, mixed>
     */
    public function waitForDecision(
        string $approvalId,
        ?int $timeout = null,
        ?callable $onRetry = null,
        ?callable $isCancelled = null,
    ): array {
        Guards::requireNonEmpty($approvalId, 'approvalId');

        /** @var array<string, mixed> */
        return Poller::pollUntilSettled(
            $this->clock,
            fn(): mixed => $this->wait($approvalId, $timeout),
            static function (mixed $value) use ($approvalId): array {
                // A wait response is trusted as an outcome only when it is the
                // approval that was asked about, in a known status. A captive
                // portal's page, `{}`, or another approval must not read as "decided".
                if (!Validate::isRecord($value) || !is_array($value)
                    || !Validate::sameId($value['id'] ?? null, $approvalId)
                    || !is_string($value['status'] ?? null)
                    || !in_array($value['status'], self::STATUSES, true)
                    || !array_key_exists('decided_at', $value)
                    || !($value['decided_at'] === null || is_string($value['decided_at']))
                    // A gate acts on `decision`; a decided approval without one, or a
                    // pending one already stamped decided, is not an outcome it can act on.
                    || ($value['status'] === 'decided' && !Validate::isNonEmptyString($value['decision'] ?? null))
                    || ($value['status'] === 'pending' && $value['decided_at'] !== null)) {
                    throw Validate::invalidWaitResponse('approval', $approvalId, $value, 'status');
                }

                return $value;
            },
            static fn(array $approval): bool => $approval['status'] !== 'pending',
            "approval {$approvalId}",
            Poller::MIN_POLL_INTERVAL_MS,
            static fn(array $approval): mixed => $approval['expires_at'] ?? null,
            $onRetry,
            $isCancelled,
        );
    }
}
