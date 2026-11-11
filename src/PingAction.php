<?php

declare(strict_types=1);

namespace PingRoom;

use PingRoom\Exception\PingRoomException;
use PingRoom\Internal\Json;
use PingRoom\Internal\PingActionRules;

/**
 * Buttons on a Ping: up to three, on the notification's long-press actions and
 * on the Ping's card in the app. They live under the reserved `data.actions`
 * key of {@see Client::broadcast()} or an incoming webhook:
 *
 *     $ping = $pr->broadcast('ab12cd', message: 'Disk 91% on db-2', data: [
 *         'actions' => [
 *             PingAction::openUrl('Open dashboard', 'https://grafana.example.com/d/disk'),
 *             PingAction::webhook('Clean up now', 'https://ops.example.com/cleanup',
 *                 id: 'clean', style: 'danger', body: ['host' => 'db-2']),
 *         ],
 *     ]);
 *     // Keep $ping['action_signing_secret']: every tap on "Clean up now" reaches
 *     // https://ops.example.com/cleanup signed with it (see Webhook::verifySignature).
 *
 * - `open_url` opens an https page. The app shows its host under the label.
 * - `webhook` never makes the phone call your URL. A tap calls PingRoom, which
 *   POSTs a signed `notification.action` event to it. The room owner must be on
 *   Pro. The URL takes no port.
 *
 * Not in public or personal rooms, not with `requiresAck`, never on a question,
 * handoff, approval, live stream, replacement or quick-action press, and a Ping
 * with buttons cannot be replaced. Each builder checks its button the way the
 * server does and throws a PingRoomException (`invalid_data`) when the server
 * would refuse it.
 */
final class PingAction
{
    public const OPEN_URL = 'open_url';
    public const WEBHOOK = 'webhook';

    public const MAX_ACTIONS = 3;
    public const ID_PATTERN = '/^[a-z0-9_-]{1,24}\z/';
    /** The id pattern as the server publishes it (`docs/contracts/ping-actions.json`). */
    public const ID_PATTERN_SOURCE = '^[a-z0-9_-]{1,24}$';
    public const MAX_LABEL_LENGTH = 26;
    public const MAX_URL_LENGTH = 2048;
    public const MAX_BODY_BYTES = 1024;
    public const MAX_BODY_KEYS = 10;
    /** A button can be run only while its Ping is younger than this. */
    public const RUN_WINDOW_HOURS = 24;
    public const TYPES = [self::OPEN_URL, self::WEBHOOK];
    public const STYLES = ['default', 'primary', 'danger'];

    private function __construct() {}

    /**
     * A button that opens an https link.
     *
     * @param string|null $id    `[a-z0-9_-]{1,24}`, unique on the Ping; defaults to `a1`, `a2`, `a3` by position
     * @param string|null $style `default`, `primary` or `danger`
     *
     * @return array<string, mixed> one entry of `data['actions']`
     */
    public static function openUrl(string $label, string $url, ?string $id = null, ?string $style = null): array
    {
        return self::build(self::OPEN_URL, $label, $url, $id, $style, null);
    }

    /**
     * A button whose tap PingRoom delivers to your https URL as a signed
     * `notification.action` event. The URL takes no port.
     *
     * @param array<array-key, mixed>|null $body a JSON object (at most 10 keys, 1024 bytes) echoed to your URL on every tap, never shown to recipients; a list is refused
     *
     * @return array<string, mixed> one entry of `data['actions']`
     */
    public static function webhook(
        string $label,
        string $url,
        ?string $id = null,
        ?string $style = null,
        ?array $body = null,
    ): array {
        return self::build(self::WEBHOOK, $label, $url, $id, $style, $body);
    }

    /**
     * @param array<array-key, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private static function build(string $type, string $label, string $url, ?string $id, ?string $style, ?array $body): array
    {
        $input = ['label' => $label, 'type' => $type, 'url' => $url];
        if ($id !== null) {
            $input['id'] = $id;
        }
        if ($style !== null) {
            $input['style'] = $style;
        }
        if ($body !== null) {
            $input['body'] = $body;
        }

        $result = PingActionRules::normalize([$input]);
        if ($result['errors'] !== [] || count($result['actions']) !== 1) {
            $problems = [];
            foreach ($result['errors'] as $field => $message) {
                $name = preg_replace('/^data\.actions\.0\.?/', '', $field);
                $problems[] = (($name === null || $name === '') ? 'button' : $name) . ': ' . $message;
            }
            throw PingRoomException::invalidData('pingAction: ' . implode(' ', $problems));
        }

        // The cleaned label is what every recipient sees.
        $input['label'] = $result['actions'][0]['label'];
        if (isset($input['body'])) {
            $input['body'] = Json::asObject($input['body']);
        }

        return $input;
    }
}
