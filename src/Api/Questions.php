<?php

declare(strict_types=1);

namespace PingRoom\Api;

use PingRoom\Clock;
use PingRoom\Exception\PingRoomException;
use PingRoom\Http\Transport;
use PingRoom\Internal\Data;
use PingRoom\Internal\Guards;
use PingRoom\Internal\Json;
use PingRoom\Internal\Path;
use PingRoom\Internal\PingActionRules;
use PingRoom\Internal\Poller;
use PingRoom\Internal\Validate;

/**
 * `$client->questions`: the general human-in-the-loop primitive. Ask a human a
 * question with 2 to 4 options (or a short typed answer) and block until they tap
 * an answer. An approval is the two-option case; reach for questions when you need
 * more choices, a typed reply, or room-scope answering. Set
 * `resolutionPolicy: 'all'` to poll the room: everyone asked votes once, and
 * `results` carries the tally on every read.
 */
final class Questions
{
    private const STATES = ['pending', 'answered', 'expired', 'cancelled'];

    public function __construct(
        private readonly Transport $http,
        private readonly Clock $clock,
    ) {}

    /**
     * Ask a Question in a room; returns the pending Question (id and computed
     * expiry). Reuse `$idempotencyKey` when retrying after a timeout or network
     * error: the server replays the first result instead of asking twice.
     *
     * @param array<int, string|array<string, mixed>>|null $options  2 to 4 options; a bare string is `{value, label: value}`. Omit for the binary Approve/Deny default.
     * @param array<string, mixed>|object|null             $textInput invite a short typed answer: `placeholder`, `max_length` (1 to 60)
     * @param string|null                                  $responderScope `direct` (default, your bound user) or `room`
     * @param string|null                                  $resolutionPolicy `first` (default) or `all` to poll the room
     * @param string|null                                  $targetUserId for `direct` scope: a specific room member (UUID)
     * @param int|null                                     $ttl seconds until expiry; omit for the server default (1 h), clamped to 30 to 86400
     * @param array<array-key, mixed>|object|null          $data structured data (at most 25 keys and 8 KB)
     * @param list<string>|null                            $attachmentIds ordered ids from `attachments->upload()` (at most 4)
     *
     * @return array<string, mixed>
     */
    public function ask(
        string $inviteCode,
        string $prompt,
        ?string $context = null,
        ?array $options = null,
        array|object|null $textInput = null,
        ?string $responderScope = null,
        ?string $resolutionPolicy = null,
        ?string $targetUserId = null,
        ?int $ttl = null,
        array|object|null $data = null,
        ?string $correlationId = null,
        ?string $replyTo = null,
        ?array $attachmentIds = null,
        ?string $idempotencyKey = null,
    ): array {
        Guards::requireNonEmpty($prompt, 'prompt');
        $view = Data::check($data);
        PingActionRules::refuse($view, 'A Question');
        self::assertPollShape($resolutionPolicy, $responderScope, $targetUserId, $textInput);
        $headers = Guards::idempotencyHeaders($idempotencyKey);

        $body = self::drop([
            'prompt' => $prompt,
            'context' => $context,
            'options' => $options,
            'text_input' => $textInput === null ? null : Json::asObject($textInput),
            'responder_scope' => $responderScope,
            'resolution_policy' => $resolutionPolicy,
            'target_user_id' => $targetUserId,
            'ttl' => $ttl,
            'data' => $data === null ? null : Data::toWire($data),
            'correlation_id' => $correlationId,
            'reply_to' => $replyTo,
            'attachment_ids' => $attachmentIds,
        ]);

        return Validate::record($this->http->request(
            'POST',
            '/api/agent/rooms/' . Path::segment($inviteCode) . '/questions',
            ['json' => $body, 'headers' => $headers],
        ));
    }

    /**
     * Fetch a Question's current state.
     *
     * @return array<string, mixed>
     */
    public function get(string $questionId): array
    {
        return Validate::record($this->http->request('GET', '/api/agent/questions/' . Path::segment($questionId)));
    }

    /**
     * One long-poll for the answer; returns the (possibly still-pending) Question.
     *
     * @param int|null $timeout seconds the server holds the connection (0 to 30, default 20)
     *
     * @return array<string, mixed>
     */
    public function wait(string $questionId, ?int $timeout = null): array
    {
        return Validate::record($this->http->request(
            'GET',
            '/api/agent/questions/' . Path::segment($questionId) . '/wait',
            ['query' => ['timeout' => $timeout], 'timeout' => Poller::waitTimeoutSeconds($timeout)],
        ));
    }

    /**
     * Block until the human answers (or the question expires or is cancelled),
     * looping `wait()` under the hood. Returns the resolved Question: inspect
     * `state` and `answer['value']` to branch. A poll returns once it closes; its
     * `answer` is the leading option, or null on a tie, and `results` holds the
     * tally.
     *
     * Polls start at least 2.1 s apart. A response that is not this Question in a
     * known state throws `invalid_response`. A network failure, 429 or 5xx is
     * retried with backoff (honouring `retryAfter`) and reported to `$onRetry`,
     * until `$isCancelled` returns true or the outage outlasts the Question's
     * `expires_at` by 5 minutes (15 minutes of failures while no `expires_at` has
     * been read); that throws `unavailable` with the last failure as its previous
     * exception.
     *
     * @param int|null                                  $timeout     seconds each poll holds the connection (default 20)
     * @param (callable(PingRoomException, int): void)|null $onRetry called before each retry with the failure and the consecutive-failure count (1 on the first); throwing ends the wait with the thrown error
     * @param (callable(): bool)|null                   $isCancelled return true to stop waiting; the wait throws `cancelled`
     *
     * @return array<string, mixed>
     */
    public function waitForAnswer(
        string $questionId,
        ?int $timeout = null,
        ?callable $onRetry = null,
        ?callable $isCancelled = null,
    ): array {
        Guards::requireNonEmpty($questionId, 'questionId');

        /** @var array<string, mixed> */
        return Poller::pollUntilSettled(
            $this->clock,
            fn(): mixed => $this->wait($questionId, $timeout),
            static function (mixed $value) use ($questionId): array {
                if (!Validate::isRecord($value) || !is_array($value)
                    || !Validate::sameId($value['id'] ?? null, $questionId)
                    || !is_string($value['state'] ?? null)
                    || !in_array($value['state'], self::STATES, true)) {
                    throw Validate::invalidWaitResponse('question', $questionId, $value, 'state');
                }

                return $value;
            },
            static fn(array $question): bool => $question['state'] !== 'pending',
            "question {$questionId}",
            Poller::MIN_POLL_INTERVAL_MS,
            static fn(array $question): mixed => $question['expires_at'] ?? null,
            $onRetry,
            $isCancelled,
        );
    }

    /**
     * List the asker's Questions, optionally filtered by state.
     *
     * @param string|null $state `pending`, `answered`, `expired`, `cancelled`, or `all`; omit for the recent window across states
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $state = null): array
    {
        $result = Validate::record($this->http->request('GET', '/api/agent/questions', ['query' => ['state' => $state]]));
        $questions = $result['questions'] ?? [];

        /** @var list<array<string, mixed>> */
        return is_array($questions) ? array_values($questions) : [];
    }

    /**
     * Withdraw a still-pending Question (409 if it already resolved).
     *
     * @return array<string, mixed>
     */
    public function cancel(string $questionId): array
    {
        return Validate::record($this->http->request(
            'POST',
            '/api/agent/questions/' . Path::segment($questionId) . '/cancel',
            ['json' => new \stdClass()],
        ));
    }

    /**
     * A poll asks the whole room and counts option votes, so the server refuses it
     * (422 on `resolution_policy`) alongside a direct scope, a named target, or a
     * typed answer. Fail before the request instead.
     */
    private static function assertPollShape(?string $policy, ?string $scope, ?string $target, mixed $textInput): void
    {
        if ($policy !== 'all') {
            return;
        }
        if ($scope === 'direct' || ($target !== null && $target !== '') || $textInput !== null) {
            throw PingRoomException::invalidRequest(
                "A poll (`resolutionPolicy: 'all'`) asks the whole room with options only; it cannot use `responderScope: 'direct'`, `targetUserId`, or `textInput`.",
            );
        }
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function drop(array $fields): array
    {
        return array_filter($fields, static fn(mixed $value): bool => $value !== null);
    }
}
