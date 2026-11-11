# Changelog

## 0.1.0 (2026-11-11)

First release of the PingRoom PHP SDK. It covers the PingRoom agent API as of 2026-11-11 and follows the TypeScript SDK (`@pingroom/sdk`) request for request.

### Added

- `PingRoom\Client`: token, base URL (`PINGROOM_API_URL`, then `PINGROOM_BASE_URL`, then `https://api.pingroom.io`), timeout, injectable PSR-18 client and PSR-17 factories, `User-Agent: pingroom-php/<version>`. HTTPS is required unless the host is loopback or `allowInsecure` is set. A redirect is refused, never followed. The token cannot appear in `var_dump()`, `print_r()`, `var_export()`, `json_encode()` or an exception.
- Errors: `PingRoomException` (HTTP `status`, server `errorCode`, `retryAfter`, decoded `body`), `TimeoutException`, `NetworkException`, and the error-code lists of the TypeScript SDK in `ErrorCodes`.
- Sending: `Client::broadcast()` (message, title, data, tags, correlation id, reply to, urgent, ack options, attachment ids, replace key, idempotency key), `IncomingWebhook::send()` with every field of `IncomingWebhook::FIELDS` (including `tags`, `replace_key`, `metric`, `live_status`, `data.actions`), and the `LinkPing`, `LocationPing` and `PingAction` builders.
- Notifications: `list`, `get`, `wait`, `listen` (a generator), `waitForAcknowledgement`, `replace`, `withdraw`.
- Live status: `live->push`, `live->get`, `live->claim`, and `LiveStatus` builders for the 11 templates (status, steps, progress with segments, metrics, countdown, question, matchup, claim, timer, value, stats), plus `alert`, `done` and `failed`.
- Questions (ask, get, wait, waitForAnswer, list, cancel), approvals (request, get, wait, waitForDecision), handoffs (requestAck, ask, get, waitForResult, list), room metrics (list, set, delete), attachments (upload, uploadFile, content, manifest, delete).
- `Webhook::verifySignature()`: V1 and V2 schemes, constant-time compare, replay window, `requireV2`, raw string body only; verifies Ping button deliveries as well.
- Wait loops with the TypeScript SDK's backoff, 2.1 s poll spacing, `Retry-After` handling and outage bound, and an injectable `Clock`.
- Client-side validation that is never stricter than the server, pinned by the server's contract fixtures (`tests/contracts/`).

### Not yet

Agent registration, pairing and OAuth helpers; rooms, webhooks and quick-actions management; quick-action presses; redeeming codes; the agent directory; profile; the Agent Inbox; the MCP JSON-RPC client.
