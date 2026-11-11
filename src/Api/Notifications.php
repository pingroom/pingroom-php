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
use PingRoom\Limits;
use PingRoom\Unchanged;

/**
 * `$client->notifications`: history, one-shot waits, the `listen()` loop, and
 * replacing or taking back a ping you sent.
 *
 * Every read and write resolves with the decoded response (`array<string, mixed>`),
 * so a field the API adds later is not dropped.
 */
final class Notifications
{
    public function __construct(
        private readonly Transport $http,
        private readonly Clock $clock,
    ) {}

    /**
     * Paginated history (a polling fallback; prefer `wait()` and `listen()` for
     * real time). Each entry's `room` also carries `code`, the invite code, as
     * `listen()` reports it.
     *
     * @param string|null $type   `received` or `sent`; the server refuses any other value
     * @param string|null $roomId only pings in this room (the room's id)
     * @param string|null $tag    only pings carrying this tag; trimmed and lowercased by the server
     * @param string|null $date
     * @param int|null    $limit  page size, 1 to 25
     * @param int|null    $page   page number, starting at 1
     *
     * @return array<string, mixed> a paginated list: `data`, `current_page`, `per_page`, `total`, `last_page`, ...
     */
    public function list(
        ?string $type = null,
        ?string $roomId = null,
        ?string $tag = null,
        ?string $date = null,
        ?int $limit = null,
        ?int $page = null,
    ): array {
        Guards::assertTag($tag);
        $result = Validate::record($this->http->request('GET', '/api/agent/notifications', [
            'query' => [
                'type' => $type,
                'room_id' => $roomId,
                'tag' => $tag,
                'date' => $date,
                'limit' => $limit,
                'page' => $page,
            ],
        ]));
        $rows = $result['data'] ?? null;
        if (!is_array($rows)) {
            throw PingRoomException::invalidResponse(200, 'a paginated notification list', $result);
        }

        $result['data'] = array_map(static function (mixed $notification): mixed {
            if (is_array($notification) && isset($notification['room']) && is_array($notification['room'])
                && array_key_exists('invite_code', $notification['room'])) {
                $notification['room']['code'] = $notification['room']['invite_code'];
            }

            return $notification;
        }, $rows);

        return $result;
    }

    /**
     * Fetch one visible notification, including its current acknowledgement state.
     *
     * @return array<string, mixed>
     */
    public function get(string $notificationId): array
    {
        Guards::requireNonEmpty($notificationId, 'notificationId');

        return Validate::record($this->http->request('GET', '/api/agent/notifications/' . Path::segment($notificationId)));
    }

    /**
     * Replace the title, message and data of a ping this credential already sent.
     * The notification already on each recipient's phone changes in place:
     * silently (no second notification, no sound) and with unread counts unchanged.
     * The result is the ping, with `edited_at` set.
     *
     * `$title` and `$data` keep their current value when you leave them out and are
     * cleared by `null`. Sending the text and data the ping already has is a no-op:
     * it answers 200 and pushes nothing. A replacement counts like a send against
     * the free plan's daily agent ping limit, and has no idempotency key.
     *
     * Only an ordinary ping you sent in a private room, under 24 hours old and
     * replaced fewer than 20 times, can be replaced. Every refusal has its own
     * `errorCode` ({@see \PingRoom\ErrorCodes::REPLACE_ERROR_CODES}); a 404 with
     * no code means the server has replacing switched off.
     *
     * @param array<array-key, mixed>|object|null|Unchanged $data
     *
     * @return array<string, mixed>
     */
    public function replace(
        string $inviteCode,
        string $notificationId,
        string $message,
        string|Unchanged|null $title = Unchanged::Value,
        array|object|null $data = Unchanged::Value,
    ): array {
        Guards::requireNonEmpty($inviteCode, 'inviteCode');
        Guards::requireNonEmpty($notificationId, 'notificationId');
        Guards::requireNonEmpty($message, 'message');
        Guards::assertMaxLength($message, Limits::MAX_PUBLIC_PING_MESSAGE_LENGTH, 'message');
        Guards::assertMaxLength($title instanceof Unchanged ? null : $title, Limits::MAX_PING_TITLE_LENGTH, 'title');
        $view = $data instanceof Unchanged ? null : Data::check($data);
        // A replacement never adds, changes or removes buttons (the server answers
        // 422 on `data.actions`), and a Ping that has buttons cannot be replaced.
        PingActionRules::refuse($view, 'A replacement');

        $body = ['message' => $message];
        if (!$title instanceof Unchanged) {
            $body['title'] = $title;
        }
        if (!$data instanceof Unchanged) {
            $body['data'] = $data === null ? null : Data::toWire($data);
        }

        return Validate::record($this->http->request(
            'PUT',
            '/api/agent/rooms/' . Path::segment($inviteCode) . '/notifications/' . Path::segment($notificationId),
            ['json' => $body],
        ));
    }

    /**
     * Take back a ping this credential already sent. The notification is removed
     * from each recipient's Lock Screen and Notification Center, and the ping stays
     * in the room's history with its text and `withdrawn_at` set. Unread counts do
     * not change. The result is the ping.
     *
     * Taking back a ping that is already withdrawn is a harmless no-op (it answers
     * 200 and pushes nothing). It does not count against the free plan's daily agent
     * ping limit, and a withdrawn ping can no longer be replaced.
     *
     * Only an ordinary ping you sent in a private room and under 24 hours old can be
     * withdrawn. Every refusal has its own `errorCode`
     * ({@see \PingRoom\ErrorCodes::WITHDRAW_ERROR_CODES}); a 404 with no code means
     * the server has withdrawing switched off. Removal is best effort: a phone that
     * is offline keeps the notification until it next opens the app.
     *
     * @return array<string, mixed>
     */
    public function withdraw(string $inviteCode, string $notificationId): array
    {
        Guards::requireNonEmpty($inviteCode, 'inviteCode');
        Guards::requireNonEmpty($notificationId, 'notificationId');

        return Validate::record($this->http->request(
            'POST',
            '/api/agent/rooms/' . Path::segment($inviteCode) . '/notifications/' . Path::segment($notificationId) . '/withdraw',
            ['json' => new \stdClass()],
        ));
    }

    /**
     * One bounded long-poll for an ack-required ping. The returned state may still
     * be `open` when the hold timeout elapses; call again to keep waiting.
     *
     * @param int|null $timeout seconds the server holds the connection (0 to 30, default 20)
     *
     * @return array<string, mixed> `id` and `action_state`
     */
    public function waitForAcknowledgement(string $notificationId, ?int $timeout = null): array
    {
        Guards::requireNonEmpty($notificationId, 'notificationId');

        return Validate::record($this->http->request(
            'GET',
            '/api/agent/notifications/' . Path::segment($notificationId) . '/ack/wait',
            [
                'query' => ['timeout' => $timeout],
                'timeout' => Poller::waitTimeoutSeconds($timeout),
            ],
        ));
    }

    /**
     * One long-poll: resolves as soon as new pings land, or with an empty list at
     * timeout.
     *
     * @param string|null $after   cursor (a notification id); omit to start from the current head
     * @param int|null    $timeout seconds to hold the connection (0 to 30, default 20)
     * @param int|null    $limit   at most this many notifications (1 to 100, default 50)
     * @param string|null $tag     only wait for pings carrying this tag; others are skipped, not returned
     *
     * @return array<string, mixed> `notifications` and `cursor`
     */
    public function wait(?string $after = null, ?int $timeout = null, ?int $limit = null, ?string $tag = null): array
    {
        Guards::assertTag($tag);

        return Validate::record($this->http->request('GET', '/api/agent/notifications/wait', [
            'query' => ['after' => $after, 'timeout' => $timeout, 'limit' => $limit, 'tag' => $tag],
            'timeout' => Poller::waitTimeoutSeconds($timeout),
        ]));
    }

    /**
     * Continuous real-time inbound: a generator that long-polls in a loop,
     * advancing the cursor and yielding each new ping.
     *
     *     foreach ($pr->notifications->listen() as $ping) {
     *         echo $ping['message'], "\n";
     *         break; // stop whenever you like
     *     }
     *
     * With no `$after`, it reads the current head first, so only pings that land
     * after the call are delivered. Only a network failure, a deadline, a 429 or a
     * 5xx is retried, with backoff (a server `Retry-After` replaces it, and polls
     * start at least 2.1 s apart after an empty answer so the loop cannot use up the
     * route's 30-per-minute limit, which the human's other listeners share); any
     * other error (401, 403, 422, ...) ends the generator by throwing, so a revoked
     * credential never retries forever.
     *
     * @param int|null                                  $timeout     seconds each poll holds the connection (0 to 30, default 20)
     * @param int|null                                  $limit       at most this many notifications per poll (1 to 100, default 50)
     * @param string|null                               $tag         only yield pings carrying this tag
     * @param (callable(PingRoomException): void)|null  $onError     called with each transient error before the loop backs off and retries
     * @param int                                       $backoffMs   first backoff after a transient error, in ms (default 1000, raised to 250 if smaller); doubles up to 30 s
     * @param (callable(): bool)|null                   $isCancelled return true to end the loop quietly (checked between polls and while waiting)
     *
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    public function listen(
        ?string $after = null,
        ?int $timeout = null,
        ?int $limit = null,
        ?string $tag = null,
        ?callable $onError = null,
        int $backoffMs = Poller::TRANSIENT_BACKOFF_BASE_MS,
        ?callable $isCancelled = null,
    ): \Generator {
        Guards::assertTag($tag);

        return $this->listenLoop($after, $timeout, $limit, $tag, $onError, $backoffMs, $isCancelled);
    }

    /**
     * @param (callable(PingRoomException): void)|null $onError
     * @param (callable(): bool)|null                  $isCancelled
     *
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    private function listenLoop(
        ?string $after,
        ?int $timeout,
        ?int $limit,
        ?string $tag,
        ?callable $onError,
        int $backoffMs,
        ?callable $isCancelled,
    ): \Generator {
        $cursor = $after;
        $headRead = $after !== null;
        $failures = 0;
        while ($isCancelled === null || !$isCancelled()) {
            // The head read is an immediate answer by design; only polls ahead of
            // the cursor are spaced.
            $polledForward = $headRead;
            $pollStartedAt = $this->clock->nowMs();
            try {
                // The head read exists to obtain a cursor, so it must carry one.
                $result = self::validateListenResult(
                    $headRead
                        ? $this->wait($cursor, $timeout, $limit, $tag)
                        // No cursor yet: read the current head (an immediate, empty
                        // answer) so only pings that land after this call are delivered.
                        : $this->wait(timeout: 0),
                    !$headRead,
                );
            } catch (PingRoomException $error) {
                if ($isCancelled !== null && $isCancelled()) {
                    return;
                }
                // A revoked credential or a bad request never heals; only network
                // failures, deadlines, 429 and 5xx are retried.
                if (!Poller::isTransient($error)) {
                    throw $error;
                }
                ++$failures;
                if ($onError !== null) {
                    $onError($error);
                }
                Poller::sleep(
                    $this->clock,
                    max(
                        Poller::transientDelayMs($error, $failures, $backoffMs),
                        Poller::MIN_POLL_INTERVAL_MS - ($this->clock->nowMs() - $pollStartedAt),
                    ),
                    $isCancelled,
                );

                continue;
            }
            $failures = 0;
            $headRead = true;
            $cursor = isset($result['cursor']) && is_string($result['cursor']) ? $result['cursor'] : $cursor;
            /** @var list<array<string, mixed>> $notifications */
            $notifications = $result['notifications'];
            foreach ($notifications as $notification) {
                yield $notification;
            }
            // After an empty answer also keep polls a floor apart, so `timeout: 0` or
            // an early-answering proxy cannot spin the loop.
            $spacingMs = $polledForward && $notifications === []
                ? max(0, Poller::MIN_POLL_INTERVAL_MS - ($this->clock->nowMs() - $pollStartedAt))
                : 0;
            Poller::sleep($this->clock, $spacingMs, $isCancelled);
        }
    }

    /**
     * A 2xx that is not a wait answer (a captive portal's page, a proxy's error
     * served as 200) must not end `listen()` with a bare TypeError, nor advance its
     * cursor.
     *
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function validateListenResult(array $value, bool $requireCursor): array
    {
        $notifications = $value['notifications'] ?? null;
        $valid = is_array($notifications) && array_is_list($notifications);
        if ($valid) {
            foreach ($notifications as $notification) {
                if (!Validate::isRecord($notification)) {
                    $valid = false;
                    break;
                }
            }
        }
        if ($valid) {
            $valid = array_key_exists('cursor', $value) ? is_string($value['cursor']) : !$requireCursor;
        }
        if (!$valid) {
            throw new PingRoomException('PingRoom returned an invalid notifications wait response.', 'invalid_response');
        }

        return $value;
    }
}
