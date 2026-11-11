# Contract fixtures

These four files are copies of the PingRoom server's cross-repo contracts. Laravel
writes each one from its own code (the real Form Requests and rule classes), and
every client SDK replays them, so a client that drifts from the server fails a test
instead of surfacing as a 422 in production.

| File | What it pins here |
|---|---|
| `structured-ping-vectors.json` | the 8 KB `data` rule and its 25-key cap, the `Idempotency-Key` rule, the link `button_label` limit |
| `text-limits.json` | the ping message and title limits |
| `ping-actions.json` | the button limits, reserved labels, label and URL verdicts, and the per-Ping signing vector |
| `live-templates.json` | the live card template list, field limits and immutable fields |

**Source:** `docs/contracts/` in the PingRoom workspace (the `docs` repository). Do not
edit these files by hand.

**Refresh** from the workspace root (the directory that holds `docs/` and `sdk-php/`):

```sh
cp docs/contracts/structured-ping-vectors.json docs/contracts/text-limits.json \
   docs/contracts/ping-actions.json docs/contracts/live-templates.json sdk-php/tests/contracts/
cd sdk-php && composer test
```

A fixture is regenerated only from Laravel, with the command named in its own
`regenerate` field (for example `cd laravel && WRITE_PING_ACTIONS_CONTRACT=1 php
artisan test --compact tests/Unit/PingActionsContractTest.php`). When a test here
fails after a refresh, the server's rule moved: change the SDK to match it.
