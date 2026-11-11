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
 * `$client->handoffs`: the direct, private agent-to-human loop. A handoff reaches
 * exactly one human and no other room member can read it. It is backed by the ack
 * and question primitives, so its `state` space is their union, kept distinct per
 * `kind`:
 *
 *   ack      => open | acked | expired
 *   question => pending | answered | expired | cancelled
 *
 * A negative answer to a question handoff (for example `hold`) is a successful
 * `answered` result, not an error.
 *
 *     $task = $pr->handoffs->requestAck('Look at the failing deploy', idempotencyKey: 'deploy-42');
 *     $result = $pr->handoffs->waitForResult($task['id']);
 */
final class Handoffs
{
    private const STATES = ['open', 'acked', 'pending', 'answered', 'expired', 'cancelled'];

    private const TERMINAL_STATES = ['acked', 'answered', 'expired', 'cancelled'];

    public function __construct(
        private readonly Transport $http,
        private readonly Clock $clock,
    ) {}

    /**
     * Ask a human to acknowledge (an `ack` handoff). Resolves on the first
     * acknowledgement.
     *
     * @param string|null                         $target         who to reach: `me`, the bound human (the default); the bound human's own user id is an alias; any other id is refused with 403 `target_not_permitted`
     * @param int|null                            $expiresIn      seconds until expiry, clamped server-side to 120 to 86400 (default 900)
     * @param string|null                         $urgency        `active` (default) or `passive`
     * @param array<array-key, mixed>|object|null $data
     * @param string|null                         $idempotencyKey same key and same body replays the original resource; a different body for the same key is a 409 `idempotency_conflict`. Reuse it verbatim on network retries. An empty string sends no key.
     *
     * @return array<string, mixed>
     */
    public function requestAck(
        string $prompt,
        ?string $target = null,
        ?int $expiresIn = null,
        ?string $urgency = null,
        ?string $correlationId = null,
        ?string $replyTo = null,
        array|object|null $data = null,
        ?string $idempotencyKey = null,
    ): array {
        Guards::requireNonEmpty($prompt, 'prompt');
        $view = Data::check($data);
        PingActionRules::refuse($view, 'A handoff');

        return $this->create('ack', $prompt, null, $target, $expiresIn, $urgency, $correlationId, $replyTo, $data, $idempotencyKey);
    }

    /**
     * Ask a human a multi-option question (a `question` handoff). Resolves on the
     * first tapped answer. A bare-string option is shorthand for
     * `{value, label: value}`.
     *
     * @param array<int, string|array<string, mixed>> $options 2 to 4 options
     * @param array<array-key, mixed>|object|null     $data
     *
     * @return array<string, mixed>
     */
    public function ask(
        string $prompt,
        array $options,
        ?string $target = null,
        ?int $expiresIn = null,
        ?string $urgency = null,
        ?string $correlationId = null,
        ?string $replyTo = null,
        array|object|null $data = null,
        ?string $idempotencyKey = null,
    ): array {
        Guards::requireNonEmpty($prompt, 'prompt');
        $view = Data::check($data);
        PingActionRules::refuse($view, 'A handoff');
        if (!array_is_list($options) || count($options) < 2 || count($options) > 4) {
            throw PingRoomException::invalidRequest('`options` must contain between 2 and 4 entries for a question handoff.');
        }
        $normalized = array_map(
            static fn(string|array $option): array => is_string($option) ? ['value' => $option, 'label' => $option] : $option,
            $options,
        );

        return $this->create('question', $prompt, $normalized, $target, $expiresIn, $urgency, $correlationId, $replyTo, $data, $idempotencyKey);
    }

    /**
     * Fetch a handoff's current state.
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        Guards::requireNonEmpty($id, 'id');

        return Validate::record($this->http->request('GET', '/api/agent/handoffs/' . Path::segment($id)));
    }

    /**
     * Block until the handoff resolves (or expires or is cancelled), looping the
     * bounded long-poll under the hood. `expired`, `cancelled` and a negative answer
     * (for example `hold`) all return as normal terminal results; nothing is thrown
     * for them.
     *
     * Polls start at least 2.1 s apart, so a short `$timeout` stays under the
     * route's 30-per-minute limit. A response that is not this handoff in a known
     * state throws `invalid_response`. A network failure, 429 or 5xx is retried with
     * backoff (honouring `retryAfter`) and reported to `$onRetry`, until
     * `$isCancelled` returns true or the outage outlasts the handoff's `expires_at`
     * by 5 minutes (15 minutes of failures while no `expires_at` has been read);
     * that throws `unavailable` with the last failure as its previous exception.
     *
     * @param int|null                                       $timeout     seconds each poll holds the connection (the server caps it at 25, default 20)
     * @param (callable(PingRoomException, int): void)|null  $onRetry     called before each retry with the failure and the consecutive-failure count (1 on the first); throwing ends the wait with the thrown error
     * @param (callable(): bool)|null                        $isCancelled return true to stop waiting; the wait throws `cancelled`
     *
     * @return array<string, mixed>
     */
    public function waitForResult(
        string $id,
        ?int $timeout = null,
        ?callable $onRetry = null,
        ?callable $isCancelled = null,
    ): array {
        Guards::requireNonEmpty($id, 'id');

        /** @var array<string, mixed> */
        return Poller::pollUntilSettled(
            $this->clock,
            fn(): mixed => $this->wait($id, $timeout),
            static function (mixed $value) use ($id): array {
                if (!Validate::isRecord($value) || !is_array($value)
                    || !Validate::sameId($value['id'] ?? null, $id)
                    || !is_string($value['state'] ?? null)
                    || !in_array($value['state'], self::STATES, true)) {
                    throw Validate::invalidWaitResponse('handoff', $id, $value, 'state');
                }

                return $value;
            },
            // Terminal states never change again: the wait can stop as soon as one lands.
            static fn(array $handoff): bool => in_array($handoff['state'], self::TERMINAL_STATES, true),
            "handoff {$id}",
            Poller::MIN_POLL_INTERVAL_MS,
            static fn(array $handoff): mixed => $handoff['expires_at'] ?? null,
            $onRetry,
            $isCancelled,
        );
    }

    /**
     * List the agent's open handoffs or bounded recent history.
     *
     * @param string|null $state `open` for unresolved work, or `all` for recent history (up to 200 per kind)
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $state = null): array
    {
        $result = Validate::record($this->http->request('GET', '/api/agent/handoffs', ['query' => ['state' => $state]]));
        $handoffs = $result['handoffs'] ?? [];

        /** @var list<array<string, mixed>> */
        return is_array($handoffs) ? array_values($handoffs) : [];
    }

    /**
     * One bounded long-poll; returns the (possibly still-open) handoff.
     *
     * @return array<string, mixed>
     */
    private function wait(string $id, ?int $timeout): array
    {
        return Validate::record($this->http->request(
            'GET',
            '/api/agent/handoffs/' . Path::segment($id) . '/wait',
            ['query' => ['timeout' => $timeout], 'timeout' => Poller::waitTimeoutSeconds($timeout)],
        ));
    }

    /**
     * @param list<array<string, mixed>>|null     $options
     * @param array<array-key, mixed>|object|null $data
     *
     * @return array<string, mixed>
     */
    private function create(
        string $kind,
        string $prompt,
        ?array $options,
        ?string $target,
        ?int $expiresIn,
        ?string $urgency,
        ?string $correlationId,
        ?string $replyTo,
        array|object|null $data,
        ?string $idempotencyKey,
    ): array {
        $body = array_filter([
            'kind' => $kind,
            'prompt' => $prompt,
            'audience' => ['type' => 'direct', 'user_id' => $target ?? 'me'],
            'expires_in' => $expiresIn,
            'urgency' => $urgency,
            'options' => $options,
            'correlation_id' => $correlationId,
            'reply_to' => $replyTo,
            'data' => $data === null ? null : Data::toWire($data),
        ], static fn(mixed $value): bool => $value !== null);

        // An empty string means "no key" here, as it does for an incoming webhook;
        // any other key is checked like every other send's.
        $headers = $idempotencyKey !== null && $idempotencyKey !== ''
            ? ['Idempotency-Key' => Guards::normalizeIdempotencyKey($idempotencyKey)]
            : [];

        return Validate::record($this->http->request('POST', '/api/agent/handoffs', ['json' => $body, 'headers' => $headers]));
    }
}
