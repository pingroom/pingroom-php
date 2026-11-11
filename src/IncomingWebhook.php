<?php

declare(strict_types=1);

namespace PingRoom;

use PingRoom\Exception\PingRoomException;
use PingRoom\Http\Sender;
use PingRoom\Internal\Data;
use PingRoom\Internal\Guards;
use PingRoom\Internal\Json;
use PingRoom\Internal\MetricRules;
use PingRoom\Internal\PingActionRules;
use PingRoom\Internal\Url;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Fire a room's incoming webhook. The URL carries its own secret, so no agent token
 * is needed (and none is ever sent): the one-line CI or deploy ping.
 *
 *     $hook = new IncomingWebhook();
 *     $hook->send(getenv('PINGROOM_WEBHOOK_URL'), [
 *         'message' => 'Build #42 passed',
 *         'data' => ['commit' => 'abc123'],
 *     ], idempotencyKey: 'build-42');
 *
 * Put a top-level `live_status` (with `correlation_id`) in the payload to drive a
 * Live Activity instead (build it with {@see LiveStatus}), or a top-level `metric` to
 * update a room metric (no Ping is sent). Both must be at the top level: the server
 * routes on them there and strips `live_status` from `data` so a legacy caller cannot
 * spoof completion alerts. Nested under `data` they silently send an ordinary ping.
 *
 * The webhook URL is a secret. An error message never contains it: a URL a client
 * quotes in a failure is replaced by `<origin>/[redacted]`.
 */
final class IncomingWebhook
{
    /**
     * Every field the endpoint accepts, in the server's own order (the validate block
     * of `WebhookController::trigger`, plus `live_status` and `metric`, which it
     * detects first and routes to their own handlers). The request body is built by
     * walking this list, so a field is never dropped because nobody remembered to add
     * it; any key in the payload that is not here is not sent, apart from
     * `replace_key` (or its alias `replaceKey`), which goes last.
     */
    public const FIELDS = [
        'title',
        'message',
        'action',
        'emoji',
        'icon',
        'color',
        'sound',
        'data',
        'correlation_id',
        'reply_to',
        'tags',
        'is_urgent',
        'requires_ack',
        'ack_mode',
        'ack_timeout_seconds',
        // Top level, never folded into `data`.
        'live_status',
        // Top level as well: a body with a `metric` updates a room metric and sends no Ping.
        'metric',
    ];

    private readonly Sender $sender;

    /**
     * @param ClientInterface|null $httpClient    a PSR-18 client that does not follow redirects; the SDK builds one when you pass none
     * @param float                $timeout       seconds a call may take (used for a client the SDK builds)
     * @param bool                 $allowInsecure allow plain-http webhook URLs to non-loopback hosts (off by default)
     * @param Sender|null          $sender        internal: share a client's HTTP sender
     */
    public function __construct(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly float $timeout = 30.0,
        private readonly bool $allowInsecure = false,
        private readonly string $userAgent = 'pingroom-php/' . Version::VERSION,
        ?Sender $sender = null,
    ) {
        $this->sender = $sender ?? Sender::create($httpClient, $requestFactory, $streamFactory, $timeout);
    }

    /**
     * POST to a room's incoming-webhook URL (which embeds the secret in its path).
     *
     * Payload keys are the wire names ({@see self::FIELDS}):
     *
     *  - `message` (at most 160 characters, 120 in private rooms), `title` (at most 40)
     *  - `action`: Quick Ping preset 1 to 16; `null` bypasses the webhook's stored preset
     *  - `emoji`, `icon`, `color`, `sound`: per-ping overrides, validated server-side
     *  - `data`: structured data (at most 25 keys and 8 KB); the reserved `actions` key holds up to 3 buttons (see {@see PingAction}), not with `live_status`
     *  - `correlation_id`, `reply_to`, `tags` (up to 10 labels)
     *  - `replace_key`: this webhook's own handle for "the Ping I keep updating"; a fire carrying one replaces this webhook's earlier Ping with that key while it is under 24 hours old, replaced fewer than 20 times and replaceable (the result is then the same `notification_id` with `replaced: true`), and otherwise sends a new Ping that keeps the key. `replaceKey` is accepted as an alias. Not for `live_status`.
     *  - `requires_ack`, `ack_mode` (`any` or `all`), `ack_timeout_seconds`, `is_urgent`
     *  - `live_status`: drive a Live Activity (requires `correlation_id`)
     *  - `metric`: `{key, value, label?, format?, unit?}`; updates a room metric instead of sending a Ping, and cannot be combined with `live_status`
     *
     * Reuse `$idempotencyKey` when retrying after a timeout or network error:
     * identical keys within about an hour replay the first result. An empty string
     * sends no key; any other key must be 1 to 255 printable ASCII characters.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> the endpoint's JSON object, with `success: true`; plus `notification_id`, `replaced`, `action_state`, `metric`, `unchanged`, `actions` and `action_signing_secret` where they apply
     */
    public function send(string $url, array $payload, ?string $idempotencyKey = null, ?float $timeout = null): array
    {
        $parsed = Url::assertSecure($url, $this->allowInsecure);

        // A webhook URL does not reveal room visibility, so validate the public
        // ceiling here and let the server enforce 120 for private rooms.
        Guards::assertMaxLength($payload['message'] ?? null, Limits::MAX_PUBLIC_PING_MESSAGE_LENGTH, 'message');
        Guards::assertMaxLength($payload['title'] ?? null, Limits::MAX_PING_TITLE_LENGTH, 'title');
        Guards::assertActionNumber($payload['action'] ?? null);
        $view = Data::check($payload['data'] ?? null);
        // Buttons ride an ordinary webhook Ping; a `live_status` body is a stream,
        // whose Ping never carries them (the server answers 422).
        if (array_key_exists('live_status', $payload)) {
            PingActionRules::refuse($view, 'A live-progress stream');
        } else {
            PingActionRules::assertValid($view);
        }
        Guards::assertTags($payload['tags'] ?? null);
        $replaceKey = self::replaceKey($payload);
        Guards::assertReplaceKey($replaceKey);
        self::assertMetric($payload);

        $body = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field];
            if ($field === 'data' && $value !== null) {
                $value = Data::toWire($value);
            } elseif ($field === 'metric' && is_array($value) && isset($value['value']) && (is_int($value['value']) || is_float($value['value']) || is_string($value['value']))) {
                $value['value'] = MetricRules::wireValue($value['value']);
            }
            $body[$field] = $value;
        }
        // `replaceKey` is the SDK's alias for the wire field `replace_key`.
        if ($replaceKey !== null) {
            $body['replace_key'] = $replaceKey;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = Guards::normalizeIdempotencyKey($idempotencyKey);
        }

        $scrub = static fn(string $message): string => Url::redactInMessage($message, $parsed, $url);
        $request = $this->sender->build('POST', $url, $headers, Json::encodeBody(Json::asObject($body)));
        // A redirect is refused inside send(): a 307/308 must not replay this body elsewhere.
        $response = $this->sender->send($request, $timeout ?? $this->timeout, $scrub, 'Webhook request failed: ');
        $text = $this->sender->read($response, $scrub, 'Webhook request failed: ');

        $json = null;
        if ($text !== '') {
            $valid = false;
            $decoded = Json::decode($text, $valid);
            $json = $valid === true ? $decoded : $text;
        }
        $status = $response->getStatusCode();
        $ok = $status >= 200 && $status <= 299;
        if (!$ok || (is_array($json) && ($json['success'] ?? null) === false)) {
            throw PingRoomException::fromResponse($status, $json, $response->getHeaderLine('Retry-After'));
        }
        // Every success the endpoint sends is a JSON object with `success: true`.
        // Anything else is a misrouted URL or a proxy's page, not a delivered Ping.
        if (!is_array($json) || ($json['success'] ?? null) !== true) {
            throw PingRoomException::invalidResponse($status, 'a JSON object with `success: true`', $json);
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function replaceKey(array $payload): ?string
    {
        $canonical = array_key_exists('replace_key', $payload) ? $payload['replace_key'] : null;
        $alias = array_key_exists('replaceKey', $payload) ? $payload['replaceKey'] : null;
        if ($canonical !== null && $alias !== null && $canonical !== $alias) {
            throw PingRoomException::invalidRequest('Give `replace_key` or its alias `replaceKey`, not both.');
        }
        $key = $canonical ?? $alias;
        if ($key !== null && !is_string($key)) {
            throw PingRoomException::invalidRequest('`replaceKey` must be a string.');
        }

        return $key;
    }

    /**
     * A `metric` body is validated the way `Metrics::set()` validates its arguments,
     * and cannot ride with `live_status`: the server answers 422 rather than pick one.
     *
     * @param array<string, mixed> $payload
     */
    private static function assertMetric(array $payload): void
    {
        if (!array_key_exists('metric', $payload)) {
            return;
        }
        if (array_key_exists('live_status', $payload)) {
            throw PingRoomException::invalidRequest('`metric` cannot be combined with `live_status`; send them as separate requests.');
        }
        $metric = $payload['metric'];
        if (!is_array($metric) || ($metric !== [] && array_is_list($metric))) {
            throw PingRoomException::invalidRequest('`metric` must be an object with a `key` and a `value`.');
        }
        MetricRules::normalizeKey($metric['key'] ?? null, 'metric.key');
        MetricRules::assertFields($metric, true, 'metric.');
    }
}
