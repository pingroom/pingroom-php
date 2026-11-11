# PingRoom PHP SDK

The official PHP client for the [PingRoom](https://pingroom.io) agent API. It sends pings to a room, asks a human to approve or answer something and waits for the reply, listens for pings coming back, drives live cards, keeps room metrics, and verifies webhook signatures.

It follows the TypeScript SDK (`@pingroom/sdk`) request for request: the same paths, query strings, JSON bodies and headers, the same client-side checks, and the same wait-loop timing.

```sh
composer require pingroom/pingroom
```

## Requirements

- PHP 8.2 or newer, with `ext-json` and `ext-mbstring`.
- A PSR-18 HTTP client and PSR-17 factories. If you have Guzzle or Symfony HttpClient installed, the SDK uses it. If you have neither, install one:

  ```sh
  composer require guzzlehttp/guzzle
  ```

### What the SDK needs from the HTTP client

- **No automatic redirects.** The SDK sends your token, uploaded files and webhook bodies only to the origin it was configured for. It refuses any 3xx response itself (`redirect_refused`), but it cannot see a redirect that your client already followed. When the SDK builds the client, it configures Guzzle (`allow_redirects: false`) and Symfony HttpClient (`max_redirects: 0`) that way. If you pass your own client with `httpClient:`, make sure it does not follow redirects. Guzzle's PSR-18 `sendRequest()` never does. Symfony's `HttpClient` does, up to 20 times, unless you set `max_redirects` to 0.
- **Error statuses come back as responses.** A 4xx or 5xx must be returned, not thrown. Guzzle's PSR-18 `sendRequest()` already works this way.
- **Connection failures throw a PSR-18 exception.** The SDK turns them into `NetworkException` or `TimeoutException`.
- **Timeouts are the client's.** PSR-18 has no timeout. The `timeout:` option (seconds, default 30) configures only a client the SDK builds, and a long-poll call (`wait`, `listen`, `waitForDecision`, ...) uses its own hold time plus 10 seconds there. If you pass your own client, give it a timeout longer than a long-poll hold: the server holds a request for 20 seconds by default and at most 30.

## Quick start

```php
use PingRoom\Client;

$pr = new Client(token: $token); // an agent credential, as a string

$ping = $pr->broadcast('ab12cd', 'Deploy shipped', data: ['version' => '1.4.0']);
echo $ping['id'];
```

Parameter names are the camelCase form of the wire fields: `isUrgent` is `is_urgent`, `ackTimeoutSeconds` is `ack_timeout_seconds`. A parameter you leave out is not sent. Every call returns the decoded JSON as an array, so a field the API adds later is not dropped. Empty JSON objects decode to `[]`.

The base URL is `https://api.pingroom.io`. Override it with `baseUrl:` or with the `PINGROOM_API_URL` (then `PINGROOM_BASE_URL`) environment variable.

## Sending pings

```php
$pr->broadcast(
    'ab12cd',
    'Deploy shipped',
    title: 'CI',
    data: ['version' => '1.4.0'],
    tags: ['deploy', 'prod'],
    correlationId: 'deploy-1.4.0',
    isUrgent: true,                 // breaks through Focus; does not ask for an acknowledgement
    requiresAck: true,              // holds the ping open until someone taps Acknowledge
    ackTimeoutSeconds: 300,
    idempotencyKey: 'deploy-1.4.0',
);
```

The message is at most 160 characters in a public room and 120 in a private one. The SDK checks 160 and lets the server enforce 120, because an invite code does not say which kind of room it is. The title is at most 40 characters. Lengths count Unicode code points, as the server does.

**Retries.** A send that times out may still have been delivered. Pass an `idempotencyKey` and reuse the same key when you retry: the server replays the first result instead of delivering twice, so a retry with the same key is safe. The SDK sends it as the `Idempotency-Key` header, never in the body. A key is 1 to 255 printable ASCII characters, spaces allowed, and surrounding whitespace is trimmed as the server does. A key with anything else (an emoji, Cyrillic) is refused before sending with `invalid_request`, because a header cannot carry it. An empty string is refused too, except on `handoffs->requestAck()`, `handoffs->ask()` and incoming webhooks, where it means "no key".

**`data`** is a JSON object of at most 25 top-level keys and 8 KB, measured the way the server measures it: as PHP's `json_encode()` writes it after the server's trimming, where every non-ASCII character is a six-byte escape. Pass an associative array. An empty `[]` is sent as `{}`. For an empty object nested inside `data`, use `new stdClass()`, because an empty PHP array is written as `[]`. A list (`[1, 2, 3]`) is refused, as the server refuses it.

**Tags.** Up to 10 labels of 1 to 32 characters: lowercase letters, digits, `-` and `_`, starting with a letter or digit. The server trims, lowercases and de-duplicates them. The SDK checks them the same way and sends what you gave it.

**Replace key.** Pass `replaceKey: 'build:41'` to keep updating one ping instead of adding a new one. While your earlier ping with that key is replaceable, the call replaces it in place and returns it with `replaced: true`. Otherwise it sends a new ping that keeps the key. The key is 1 to 64 characters of letters, digits, `.`, `_`, `:` and `-`.

### Links, locations and buttons

```php
use PingRoom\LinkPing;
use PingRoom\LocationPing;
use PingRoom\PingAction;

$pr->broadcast('ab12cd', 'Build 512 ready', data: [
    'commit' => 'abc123',
    ...LinkPing::data('https://ci.example.com/b/512', 'Open build'),
]);

$pr->broadcast('ab12cd', 'Meet me here', data: LocationPing::data(25.2048, 55.2708, 'Dubai Mall'));

$ping = $pr->broadcast('ab12cd', 'Disk 91% on db-2', data: ['actions' => [
    PingAction::openUrl('Open dashboard', 'https://grafana.example.com/d/disk'),
    PingAction::webhook('Clean up now', 'https://ops.example.com/cleanup', id: 'clean', style: 'danger', body: ['host' => 'db-2']),
]]);
$secret = $ping['action_signing_secret']; // keep it: every tap on "Clean up now" is signed with it
```

A ping carries up to three buttons. `open_url` opens an https page. `webhook` makes PingRoom POST a signed `notification.action` event to your URL when someone taps it; the room owner must be on Pro. A button is checked here before any request, by the server's own rules (label, URL, id, body). A refusal is a `PingRoomException` with `errorCode` `invalid_data` that names every field. Buttons are not allowed in public or personal rooms, with `requiresAck`, or on questions, handoffs, approvals, live streams and replacements.

### Attachments

```php
$file = $pr->attachments->uploadFile('/tmp/report.pdf');   // or ->upload($bytes, 'report.pdf', 'application/pdf')
$pr->broadcast('ab12cd', 'Report attached', attachmentIds: [$file['id']]);
```

A file is 1 byte to 5 MiB with an md, pdf, html, txt, jpg, jpeg, png or zip name. A larger one is refused locally as `attachment_too_large`. Uploading needs a Pro account. `attachments->content($id)` returns the PSR-7 response with the bytes, `manifest($id)` lists a zip, and `delete($id)` discards an upload that was never attached.

## Notifications

```php
$page = $pr->notifications->list(type: 'received', tag: 'deploy', limit: 10);
$one  = $pr->notifications->get($page['data'][0]['id']);

$pr->notifications->replace('ab12cd', $one['id'], 'Build 41: green');            // keeps title and data
$pr->notifications->replace('ab12cd', $one['id'], 'Build 41: green', title: null, data: null); // clears them
$pr->notifications->withdraw('ab12cd', $one['id']);                                // takes the ping back
```

Leave `title` and `data` out of `replace()` to keep them; pass `null` to clear them. A replacement is silent: no second notification and no sound. Only an ordinary ping you sent in a private room, under 24 hours old and replaced fewer than 20 times, can be replaced or withdrawn. Each refusal has its own code (`ErrorCodes::REPLACE_ERROR_CODES`, `ErrorCodes::WITHDRAW_ERROR_CODES`).

### Listening

`listen()` is a generator. It reads the current head first, so only pings that arrive after the call are yielded, then long-polls forward.

```php
foreach ($pr->notifications->listen(tag: 'billing') as $ping) {
    echo $ping['message'], "\n";
    if ($done) {
        break;
    }
}
```

It retries a network failure, a timeout, a 429 or a 5xx with backoff (a server `Retry-After` replaces the backoff) and keeps polls at least 2.1 seconds apart after an empty answer, so one listener cannot use up the route's limit of 30 requests a minute. Any other error (401, 403, 422) ends the generator by throwing. `onError:` sees each transient error, and `isCancelled:` is a callback that returns `true` to end the loop quietly.

`notifications->wait()` is one long-poll, and `notifications->waitForAcknowledgement($id, timeout: 30)` is one long-poll for an ack-required ping. A wait timeout is not a confirmation: check `action_state['status']`.

## Asking a human

```php
$question = $pr->questions->ask('ab12cd', 'Ship 1.4.0?', options: ['ship', 'hold'], ttl: 600, idempotencyKey: 'ship-1.4.0');
$result = $pr->questions->waitForAnswer($question['id']);

if ($result['state'] === 'answered' && $result['answer']['value'] === 'ship') {
    // deploy
}
```

`approvals->request()` and `waitForDecision()` are the two-option case. `handoffs->requestAck()`, `handoffs->ask()` and `waitForResult()` reach exactly one human, privately. `questions->ask(resolutionPolicy: 'all')` polls the whole room. A negative answer (`hold`) is a normal `answered` result. An expired or cancelled one is a normal terminal result as well; nothing is thrown for it.

The three `wait*` methods loop one bounded long-poll with these rules:

- Polls start at least 2.1 seconds apart.
- A response that is not the thing you asked about, or has an unknown state, throws `invalid_response`. It is never returned as an answer.
- A network failure, timeout, 429 or 5xx is retried with backoff: 1 second doubling to 30, or the server's `Retry-After` if that is longer. `onRetry: fn (PingRoomException $e, int $attempt) => ...` sees each retry, and throwing from it ends the wait with that error.
- The retries stop with `unavailable` (the last failure is `getPrevious()`) once only failures have come back since the last valid response and the last-seen `expires_at` is more than 5 minutes past, or after 15 minutes of unbroken failures when no `expires_at` was ever read.
- `isCancelled: fn () => time() > $deadline` stops the wait, which throws `cancelled`.
- A 401, 403, 404 or 422 is permanent and is thrown at once.

```php
$deadline = time() + 900;
$result = $pr->approvals->waitForDecision($approval['id'], isCancelled: fn () => time() > $deadline);
```

## Live status

A live card updates in place on the room members' lock screens. A stream is keyed by `correlation_id`: the first ping starts it, further `running` pings move it silently, and the first `done` or `failed` ends it with one alert.

```php
use PingRoom\LiveStatus;

$pr->live->push('ab12cd', LiveStatus::progress('deploy-42', ['state' => 'running', 'progress' => 0.4, 'message' => 'Building'], ['title' => 'Deploy']));
$pr->live->push('ab12cd', LiveStatus::done('deploy-42', 'Live'));

$stream = $pr->live->get('ab12cd', 'deploy-42'); // null when there is no such stream
```

`LiveStatus` has a builder for every template: `status`, `steps`, `progress` (with `segments`), `metrics`, `countdown`, `question`, `matchup`, `claim`, `timer`, `value` and `stats`, plus `alert`, `done` and `failed`. A builder only puts the template name in place; the server checks the fields and answers 422 with the rule that was broken. The limits are in `LiveStatus::LIMITS`. `template`, `steps`, `segments` and `started_at` are fixed once a stream exists.

"Who's on it?" requests move with `$pr->live->claim('ab12cd', $notificationId, 'claim')` (or `release`, `done`, `cancel`). The receipt is checked, and a malformed one throws `invalid_response`. The SDK never repeats a transition on its own.

## Room metrics

```php
$pr->metrics->set('ab12cd', 'mrr', 12400, label: 'MRR', format: 'currency', unit: 'USD');
$pr->metrics->set('ab12cd', 'mrr', 12650);        // keeps the label, format and unit
$pr->metrics->set('ab12cd', 'status', 'All clear'); // a string makes a text metric
$all = $pr->metrics->list('ab12cd');               // ['metrics' => [...], 'limit' => 8]
$pr->metrics->delete('ab12cd', 'status');
```

Setting a metric sends no ping and no push. A room holds at most 8. Pass the value raw (`12400`, not `"$12,400"`). The key is 1 to 40 characters of letters, digits, `-` and `_`, lowercased. `unit` keeps the stored unit when you leave it out and clears it when you pass `null`.

## Incoming webhooks

A room's incoming-webhook URL carries its own secret, so no token is needed (and none is sent):

```php
use PingRoom\IncomingWebhook;

$hook = new IncomingWebhook();
$hook->send($webhookUrl, [
    'message' => 'Build #42 passed',
    'data' => ['commit' => 'abc123'],
], idempotencyKey: 'build-42');
```

The payload keys are the wire names listed in `IncomingWebhook::FIELDS`: `title`, `message`, `action`, `emoji`, `icon`, `color`, `sound`, `data`, `correlation_id`, `reply_to`, `tags`, `is_urgent`, `requires_ack`, `ack_mode`, `ack_timeout_seconds`, `live_status` and `metric`, plus `replace_key` (or the alias `replaceKey`). `action: null` is sent as `null` and bypasses the webhook's stored preset. A key that is not in the list is not sent.

Put `live_status` or `metric` at the top level. The server routes on them there; nested under `data` they silently send an ordinary ping. A `metric` updates a room metric and sends no ping, and cannot be combined with `live_status`. `$client->sendIncomingWebhook($url, $payload)` does the same through a client's HTTP stack.

The webhook URL is a secret: an error message never contains it.

## Verifying webhooks

PingRoom signs the webhooks it sends you, and the delivery of a Ping button's `webhook` action. Every delivery carries:

- `X-PingRoom-Signature-V2`: HMAC-SHA256 over `"v2\n{timestamp}\n{deliveryId}\n{rawBody}"`.
- `X-PingRoom-Signature`: the legacy HMAC over `"{timestamp}.{rawBody}"`.
- `X-PingRoom-Timestamp` (Unix seconds) and `X-PingRoom-Delivery`.

Verify over the exact raw body, before any parsing. Re-encoding parsed JSON changes the bytes. Pass `requireV2: true`: V1 does not sign the delivery id, so a captured body replayed without its V2 header under a new delivery id would still pass V1. Verification refuses a timestamp more than 5 minutes from now (`maxAgeSeconds`), compares in constant time, and returns `false` for any mismatch. The payload must be a string; anything else is a `TypeError`, so a receiver that never captured the raw body is not mistaken for a forged delivery.

Plain PHP:

```php
use PingRoom\Webhook;

$raw = file_get_contents('php://input'); // the raw body

$valid = Webhook::verifySignature(
    payload: $raw,
    secret: $signingSecret,
    signatureV2: $_SERVER['HTTP_X_PINGROOM_SIGNATURE_V2'] ?? null,
    signature: $_SERVER['HTTP_X_PINGROOM_SIGNATURE'] ?? null,
    timestamp: $_SERVER['HTTP_X_PINGROOM_TIMESTAMP'] ?? null,
    deliveryId: $_SERVER['HTTP_X_PINGROOM_DELIVERY'] ?? null,
    requireV2: true,
);
if (!$valid) {
    http_response_code(401);
    exit;
}

$event = json_decode($raw, true);
http_response_code(204);
```

Any PSR-7 framework:

```php
use PingRoom\Webhook;
use Psr\Http\Message\ServerRequestInterface;

function verifiedPingRoomEvent(ServerRequestInterface $request, string $secret): ?array
{
    $header = static fn (string $name): ?string => ($value = $request->getHeaderLine($name)) === '' ? null : $value;

    $body = $request->getBody();
    if ($body->isSeekable()) {
        $body->rewind();
    }
    $raw = (string) $body;

    $valid = Webhook::verifySignature(
        payload: $raw,
        secret: $secret,
        signatureV2: $header(Webhook::SIGNATURE_V2_HEADER),
        signature: $header(Webhook::SIGNATURE_HEADER),
        timestamp: $header(Webhook::TIMESTAMP_HEADER),
        deliveryId: $header(Webhook::DELIVERY_HEADER),
        requireV2: true,
    );

    return $valid ? json_decode($raw, true) : null;
}
```

A framework that exposes the raw body gives it to you as `$request->getContent()` (Laravel, Symfony). Do not use the parsed input.

**Ping button deliveries.** Each ping has its own secret, the `action_signing_secret` you got when you sent it. The delivery body has the ping's `notification_id`, so look the secret up by it. That id is only a lookup key until the signature checks out. Then verify with that secret and `requireV2: true`. Deliveries are at least once: run each `X-PingRoom-Delivery` only once.

## Errors

Every failure is a `PingRoom\Exception\PingRoomException`, or a subclass: `TimeoutException` and `NetworkException`.

| Property | Meaning |
|---|---|
| `$e->status` | The HTTP status, or 0 for a failure that happened on your side. |
| `$e->errorCode` | The API's machine code (`room_not_granted`, `rate_limited`, ...) or a client code (`network_error`, `invalid_request`, ...). |
| `$e->retryAfter` | Seconds to wait, from the body's `retry_after` or a `Retry-After` header. |
| `$e->body` | The decoded response body, or the raw text when it was not JSON. |
| `$e->getMessage()` | The server's message, or the code, or `HTTP <status>`. |

Branch on `status` and `errorCode`, not on the message. Retry any 429 after `retryAfter`: route throttles answer `rate_limited`, but a public room's limit answers `public_room_rate_limited` and a quick action's cooldown answers `cooldown_active`. Reuse the same idempotency key on the retry.

```php
use PingRoom\Exception\PingRoomException;

try {
    $pr->broadcast('ab12cd', 'hi', idempotencyKey: 'hello-1');
} catch (PingRoomException $e) {
    if ($e->status === 429) {
        sleep($e->retryAfter ?? 1);
        // retry with the same key
    } else {
        throw $e;
    }
}
```

The codes the SDK raises itself: `no_token`, `no_http_client`, `invalid_request`, `invalid_data`, `invalid_url`, `insecure_url`, `invalid_response` (a 2xx that is not what the call expects, such as a proxy's page), `redirect_refused`, `network_error`, `timeout`, `unavailable`, `cancelled` and `attachment_too_large`. `PingRoom\ErrorCodes` holds them all, and the server's codes per surface (`HANDOFF_ERROR_CODES`, `REPLACE_ERROR_CODES`, `LIVE_CLAIM_ERROR_CODES`, `METRIC_ERROR_CODES`, `PING_ACTION_ERROR_CODES`, `COMMON_ERROR_CODES`, ...), the same lists the TypeScript SDK exports.

The exception of a failed connection deliberately has no `getPrevious()`. HTTP client exceptions carry the request, and the request carries your token.

## Security

- **HTTPS is required.** A `baseUrl` or webhook URL over plain `http://` is refused unless the host is `localhost`, `*.localhost`, `127.0.0.1` or `::1`, or you pass `allowInsecure: true`.
- **No redirects.** See [what the SDK needs from the HTTP client](#what-the-sdk-needs-from-the-http-client).
- **The token stays out of output.** It is held in a `SensitiveParameterValue`, so `var_dump()`, `print_r()`, `var_export()`, `json_encode()` and `(array)` casts do not show it. The constructor and `setToken()` take it as `#[\SensitiveParameter]`, so it is hidden in stack traces. It is removed from any message an HTTP client wrote. The client cannot be serialized. `getToken()` is the one explicit way to read it back. Keep `zend.exception_ignore_args=On` in production anyway, so that exception traces carry no arguments at all.
- **Absolute request paths are refused**, so the token cannot be sent to another origin.
- **No telemetry, and no logging.** The SDK logs nothing and sends nothing but your requests.

## Configuration

```php
new Client(
    token: $token,                 // the agent credential
    baseUrl: 'https://api.pingroom.io',
    timeout: 30.0,                 // seconds, for a client the SDK builds
    httpClient: $psr18Client,      // your PSR-18 client (no automatic redirects)
    requestFactory: $psr17Factory, // PSR-17; found by discovery when omitted
    streamFactory: $psr17Factory,
    userAgent: 'my-bot/1.0',       // default: pingroom-php/<version>
    allowInsecure: false,
    clock: $clock,                 // PingRoom\Clock for the wait loops; see Testing
);
```

## Testing your code

Inject a PSR-18 client that returns canned responses, and a `PingRoom\Clock` whose `sleepMs()` advances time without sleeping, so a wait loop runs instantly:

```php
final class FakeClock implements \PingRoom\Clock
{
    public function __construct(private int $now = 0) {}
    public function nowMs(): int { return $this->now; }
    public function sleepMs(int $ms): void { $this->now += $ms; }
}
```

This repository's own tests do not call the real API. They use a hand-written PSR-18 mock, the fake clock above, and a small local web server for the checks that need a real socket (redirects, timeouts, multipart uploads).

The server's contract fixtures are vendored under `tests/contracts/`, with a note on where they come from and how to refresh them. The tests replay them: the `data` size rule, the idempotency-key rule, the link button label, the ping text limits, the button limits, labels and URLs, the per-Ping signing vector, and the live card templates.

```sh
composer install
composer test        # PHPUnit
composer analyse     # PHPStan: max level on src, level 5 on tests
composer cs          # php-cs-fixer, dry run
```

## Not yet

These are in the TypeScript SDK but not in 0.1.0:

- Agent registration, pairing and OAuth helpers (`auth`).
- Rooms, webhooks and quick-actions management: creating and listing rooms, managing incoming webhooks, quick-action slots and pages.
- Quick-action presses (`actions->trigger`).
- Redeeming gift and promotional codes.
- The agent directory, the agent profile, and the Agent Inbox.
- The MCP JSON-RPC client.

## Compatibility

Covers the PingRoom agent API as of 2026-11-11: broadcast (tags, `replace_key`, attachments, buttons in `data.actions`, acknowledgement modes, urgency), notifications (list, read, wait, listen, acknowledgement wait, replace, withdraw), questions and polls, approvals, handoffs, live status (all 11 templates, segmented progress, "Who's on it?"), room metrics, attachments, incoming webhooks (including `metric` and `live_status`), and webhook signature verification (V1 and V2, Ping button deliveries).

## License

MIT. See [LICENSE](LICENSE).
