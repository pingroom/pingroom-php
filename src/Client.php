<?php

declare(strict_types=1);

namespace PingRoom;

use PingRoom\Api\Approvals;
use PingRoom\Api\Attachments;
use PingRoom\Api\Handoffs;
use PingRoom\Api\Live;
use PingRoom\Api\Metrics;
use PingRoom\Api\Notifications;
use PingRoom\Api\Questions;
use PingRoom\Exception\PingRoomException;
use PingRoom\Http\Sender;
use PingRoom\Http\Transport;
use PingRoom\Internal\Data;
use PingRoom\Internal\Guards;
use PingRoom\Internal\Path;
use PingRoom\Internal\PingActionRules;
use PingRoom\Internal\Url;
use PingRoom\Internal\Validate;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The PingRoom agent API client.
 *
 *     $pr = new Client(token: getenv('PINGROOM_TOKEN'));
 *     $pr->broadcast('ab12cd', message: 'Deploy shipped', data: ['version' => '1.4.0']);
 *
 * Requests go through a PSR-18 HTTP client. It must not follow redirects: the SDK
 * refuses any 3xx itself, so a redirect that a client followed for it would defeat
 * the check (see the README). When you pass none, the SDK builds a Guzzle or Symfony
 * client configured that way, or finds one with `php-http/discovery`.
 *
 * The token is never in `var_dump()`, `print_r()`, `json_encode()` or exception
 * output, and the client cannot be serialized.
 */
final class Client implements \JsonSerializable
{
    public const DEFAULT_BASE_URL = 'https://api.pingroom.io';

    /** `broadcast()` and the other ping sends take these. */
    public readonly Notifications $notifications;

    public readonly Questions $questions;

    public readonly Approvals $approvals;

    public readonly Handoffs $handoffs;

    public readonly Metrics $metrics;

    public readonly Attachments $attachments;

    public readonly Live $live;

    private readonly Transport $http;

    private readonly IncomingWebhook $incomingWebhook;

    /**
     * @param string|null                  $token          the agent credential (a Bearer JWT); optional here, required by every call
     * @param string|null                  $baseUrl        the API base URL; defaults to the `PINGROOM_API_URL` or `PINGROOM_BASE_URL` environment variable, else `https://api.pingroom.io`
     * @param float                        $timeout        seconds a request may take (a long-poll call uses its own hold time plus 10 s instead). It configures a client the SDK builds; a client you pass keeps its own timeout, which should exceed a long-poll hold (20 s by default, at most 30 s).
     * @param ClientInterface|null         $httpClient     a PSR-18 client that does not follow redirects
     * @param string|null                  $userAgent      overrides the `User-Agent` header (default `pingroom-php/<version>`)
     * @param bool                         $allowInsecure  allow plain-http base URLs to non-loopback hosts; off by default, because credentials require https
     * @param Clock|null                   $clock          the time source and sleeper of the wait loops; inject one in tests
     */
    public function __construct(
        #[\SensitiveParameter]
        ?string $token = null,
        ?string $baseUrl = null,
        float $timeout = 30.0,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?string $userAgent = null,
        bool $allowInsecure = false,
        ?Clock $clock = null,
    ) {
        if (!is_finite($timeout) || $timeout <= 0) {
            throw PingRoomException::invalidRequest('`timeout` must be a positive number of seconds.');
        }
        $base = Url::base(Url::assertSecure($baseUrl ?? self::envBaseUrl() ?? self::DEFAULT_BASE_URL, $allowInsecure));
        $agent = $userAgent !== null && $userAgent !== '' ? $userAgent : 'pingroom-php/' . Version::VERSION;
        $clock ??= new SystemClock();

        $sender = Sender::create($httpClient, $requestFactory, $streamFactory, $timeout);
        $this->http = new Transport($base, $token, $agent, $sender);
        $this->incomingWebhook = new IncomingWebhook(
            timeout: $timeout,
            allowInsecure: $allowInsecure,
            userAgent: $agent,
            sender: $sender,
        );

        $this->notifications = new Notifications($this->http, $clock);
        $this->questions = new Questions($this->http, $clock);
        $this->approvals = new Approvals($this->http, $clock);
        $this->handoffs = new Handoffs($this->http, $clock);
        $this->metrics = new Metrics($this->http);
        $this->attachments = new Attachments($this->http);
        $this->live = new Live($this->http);
    }

    /** Update the credential (for example after you refresh it). Pass null to clear it. */
    public function setToken(#[\SensitiveParameter] ?string $token): void
    {
        $this->http->setToken($token);
    }

    /** The credential, through this explicit accessor only. */
    public function getToken(): ?string
    {
        return $this->http->getToken();
    }

    /**
     * Broadcast a ping to every member of a room you own. Reuse `$idempotencyKey`
     * when retrying after a timeout or network error, so a send the server already
     * committed is replayed rather than delivered twice. It is sent as the
     * `Idempotency-Key` header, never in the body; a key is 1 to 255 printable ASCII
     * characters, trimmed as the server trims it.
     *
     * Pass `$replaceKey` to keep updating one ping instead of sending a new one each
     * time: while your earlier ping with that key is replaceable the call replaces it
     * in place and resolves with that ping and `replaced: true`. A send that asks for
     * something a replacement cannot do (`requiresAck`, `isUrgent`, `attachmentIds`,
     * `tags`, `actionNumber`, or a `correlationId`, `replyTo` or `actionIcon` that
     * differs from the stored one) always sends a new Ping.
     *
     * Parameter names are the camelCase of the wire fields (`isUrgent` is `is_urgent`).
     * A parameter left out is not sent.
     *
     * @param string                              $message          the visible body: at most 120 characters in a private room, 160 in a public one (the SDK enforces 160 and lets the server enforce 120)
     * @param string|null                         $title            the visible title, at most 40 characters
     * @param int|null                            $actionNumber     a Quick Ping slot, 1 to 16
     * @param array<array-key, mixed>|object|null $data             structured data echoed on every read surface (at most 25 keys and 8 KB). The reserved keys `url` and `button_label` make the ping a tappable link ({@see LinkPing}), `location` carries a map point ({@see LocationPing}), and `actions` holds up to 3 buttons ({@see PingAction}); the result then carries `action_signing_secret` when one is a `webhook` button.
     * @param list<string>|null                   $tags             up to 10 labels (each 1 to 32 characters of lowercase letters, digits, `-` and `_`, starting with a letter or digit); the server trims, lowercases and de-duplicates them
     * @param string|null                         $replaceKey       1 to 64 characters of letters, digits and `. _ : -`
     * @param list<string>|null                   $attachmentIds    ordered ids from `attachments->upload()` (at most 4); each is single-use
     * @param bool|null                           $requiresAck      open the acknowledgement lifecycle; recipients see a lock-screen card with an Acknowledge button. It does not raise delivery priority, which is `isUrgent`.
     * @param string|null                         $ackMode          `any` (default) or `all` (wait for every original eligible recipient)
     * @param bool|null                           $isUrgent         deliver time-sensitive so the ping breaks through Focus and reaches members who muted the room or you (a block still wins); independent of `requiresAck`
     * @param int|null                            $ackTimeoutSeconds acknowledgement deadline in seconds (60 to 86400); omit for none
     *
     * @return array<string, mixed> the ping: `id`, `message`, `created_at`, `tags`, `replaced`, `edited_at`, `action_signing_secret`, ...
     */
    public function broadcast(
        string $inviteCode,
        string $message,
        ?string $title = null,
        ?int $actionNumber = null,
        ?string $actionIcon = null,
        array|object|null $data = null,
        ?string $correlationId = null,
        ?string $replyTo = null,
        ?array $tags = null,
        ?string $replaceKey = null,
        ?array $attachmentIds = null,
        ?bool $requiresAck = null,
        ?string $ackMode = null,
        ?bool $isUrgent = null,
        ?int $ackTimeoutSeconds = null,
        ?string $idempotencyKey = null,
    ): array {
        Guards::assertPingText($message, $title);
        Guards::assertActionNumber($actionNumber);
        $view = Data::check($data);
        PingActionRules::assertValid($view);
        Guards::assertTags($tags);
        Guards::assertReplaceKey($replaceKey);
        $headers = Guards::idempotencyHeaders($idempotencyKey);

        $body = array_filter([
            'message' => $message,
            'title' => $title,
            'action_number' => $actionNumber,
            'action_icon' => $actionIcon,
            'data' => $data === null ? null : Data::toWire($data),
            'correlation_id' => $correlationId,
            'reply_to' => $replyTo,
            'tags' => $tags,
            'attachment_ids' => $attachmentIds,
            'requires_ack' => $requiresAck,
            'ack_mode' => $ackMode,
            'is_urgent' => $isUrgent,
            'ack_timeout_seconds' => $ackTimeoutSeconds,
            // `replaceKey` is the SDK's name for the wire field `replace_key`.
            'replace_key' => $replaceKey,
        ], static fn(mixed $value): bool => $value !== null);

        return Validate::record($this->http->request(
            'POST',
            '/api/agent/rooms/' . Path::segment($inviteCode) . '/notifications',
            ['json' => $body, 'headers' => $headers],
        ));
    }

    /**
     * Fire a room's incoming webhook through this client's HTTP stack. The agent
     * token is not sent. See {@see IncomingWebhook::send()} for the payload.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function sendIncomingWebhook(string $url, array $payload, ?string $idempotencyKey = null): array
    {
        return $this->incomingWebhook->send($url, $payload, $idempotencyKey);
    }

    /**
     * What a logger or `json_encode()` sees: the API origin, never the credential.
     *
     * @return array{baseUrl: string, token: string|null}
     */
    public function jsonSerialize(): array
    {
        return ['baseUrl' => $this->http->baseUrl(), 'token' => $this->http->hasToken() ? '[redacted]' : null];
    }

    /**
     * What `var_dump()` and `print_r()` see.
     *
     * @return array{baseUrl: string, token: string|null}
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** A client holds a credential and an HTTP client, neither of which may be written out. */
    public function __serialize(): never
    {
        throw new \LogicException('A PingRoom client cannot be serialized: it holds your credential.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): never
    {
        throw new \LogicException('A PingRoom client cannot be unserialized.');
    }

    private static function envBaseUrl(): ?string
    {
        foreach (['PINGROOM_API_URL', 'PINGROOM_BASE_URL'] as $name) {
            $value = getenv($name);
            if ($value === false || $value === '') {
                $value = $_ENV[$name] ?? $_SERVER[$name] ?? false;
            }
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
