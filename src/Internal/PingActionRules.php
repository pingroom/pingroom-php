<?php

declare(strict_types=1);

namespace PingRoom\Internal;

use PingRoom\Exception\PingRoomException;
use PingRoom\PingAction;

/**
 * The rules for buttons on a Ping (the reserved `data.actions` key), checked here
 * the way the server checks them, so a button it would refuse fails before any
 * request with the same reason.
 *
 * This mirrors the server's `App\Support\PingActionRules` and is pinned to it by
 * `tests/contracts/ping-actions.json`, which holds the limits, the reserved
 * labels and the server's verdict on every label and URL vector.
 *
 * @internal
 *
 * @phpstan-type NormalizedAction array{id: string, position: int, type: string, label: string, style: string, url: string, host: string, body: array<array-key, mixed>|null}
 */
final class PingActionRules
{
    private const ACTION_KEYS = ['id', 'label', 'type', 'url', 'style', 'body'];

    /** Last labels that never name a public host. */
    public const NON_PUBLIC_TLDS = [
        'localhost', 'localdomain', 'local', 'internal', 'lan', 'intranet', 'corp',
        'home', 'arpa', 'onion', 'test', 'invalid', 'example',
    ];

    /**
     * PingRoom's own notification buttons in their comparison form
     * ({@see self::comparisonForm()}): every shipped translation of Pong back,
     * Mute room, Confirm and the claim buttons, plus the bare words. A label equal
     * to one of these is refused. Equal to `reserved_labels` in the contract
     * fixture.
     */
    public const RESERVED_LABELS = [
        'abbrechen', 'abgeben', 'ack', 'acknowledge', 'annuler', 'annulla',
        'balasdenganpong', 'batal', 'benhallederim', 'bestätigen', 'bırak', 'cancel',
        'cancelar', 'cipensoio', 'completato', 'concluir', 'concluído', 'conferma',
        'confirm', 'confirmar', 'confirmer', 'couperlesondusalon', 'dejarlo', 'devolverpong',
        'done', 'erledigt', 'euassumo', 'eucuidodisso', 'fatto', 'fertig',
        'ichübernehme', 'illtakeit', 'i̇ptal', 'jemenoccupe', 'laisser', 'lascia',
        'lepaskan', 'letgo', 'liberar', 'listo', 'meencargoyo', 'meneoccupoio',
        'mute', 'muteroom', 'odayısessizeal', 'onayla', 'pingroom', 'pong',
        'pongback', 'ponggönder', 'pongzurück', 'pongभेजें', 'pongを返す', 'raumstummschalten',
        'renvoyerunpong', 'respondercompong', 'rispondiconpong', 'sahkan', 'sayaambil', 'sayauruskan',
        'selesai', 'senyapkanbilik', 'silenciarsala', 'silenziastanza', 'soltar', 'tamamla',
        'tamamlandı', 'terminé', 'unmute', 'беру', 'готово', 'ответитьpong',
        'отказаться', 'отключитьзвуккомнаты', 'отмена', 'отменить', 'подтвердить', 'явозьмусь',
        'أتخلىعنه', 'إلغاء', 'انجامشد', 'بیصداکردناتاق', 'تأكيد', 'تأیید',
        'تخلَّعنه', 'تم', 'ردبـpong', 'رهاکردن', 'سأتولىالأمر', 'كتمالغرفة',
        'لغو', 'منانجامشمیدهم', 'پاسخباpong', 'छोड़दें', 'छोड़ें', 'पुष्टिकरें',
        'पूराहुआ', 'मैंइसेलेलूँ', 'मैंसंभालूँगा', 'रद्दकरें', 'रूमम्यूटकरें', 'キャンセル',
        'ルームをミュート', '取消', '回复pong', '完了', '完成', '将房间静音',
        '已完成', '引き受ける', '我來處理', '我来', '我来处理', '手放す',
        '放弃', '放棄', '确认', '確認',
    ];

    private const MESSAGE_PROHIBITED = 'Buttons (data.actions) can only be sent with an ordinary Ping from an agent or an incoming webhook.';

    private function __construct() {}

    /**
     * Whether a `data` value carries the reserved key at all. `null` counts as
     * absent; anything else (an empty list included) is a request for buttons and
     * is validated as one.
     *
     * @param array<array-key, mixed>|null $data
     */
    public static function present(?array $data): bool
    {
        return $data !== null && array_key_exists('actions', $data) && $data['actions'] !== null;
    }

    /**
     * Refuse a `data` whose buttons the server would refuse, naming every field.
     * A no-op without `data.actions`.
     *
     * @param array<array-key, mixed>|null $data the data as the server decodes it
     */
    public static function assertValid(?array $data): void
    {
        if ($data === null || !self::present($data)) {
            return;
        }
        $errors = self::normalize($data['actions'])['errors'];
        if ($errors === []) {
            return;
        }
        $parts = [];
        foreach ($errors as $field => $message) {
            $parts[] = "{$field}: {$message}";
        }
        throw PingRoomException::invalidData('Invalid buttons: ' . implode(' ', $parts));
    }

    /**
     * Refuse `data.actions` on a route that never carries buttons (a Question, a
     * handoff, an approval, a live-progress stream, a replacement). The server
     * answers 422 there; this says so before the request.
     *
     * @param array<array-key, mixed>|null $data the data as the server decodes it
     */
    public static function refuse(?array $data, string $where): void
    {
        if ($data === null || !self::present($data)) {
            return;
        }
        throw PingRoomException::invalidData("{$where} cannot carry buttons. " . self::MESSAGE_PROHIBITED);
    }

    /**
     * Validate and clean a `data.actions` value. Each normalized button is
     * `{id, position, type, label, style, url, host, body}`; `errors` maps a field
     * (`data.actions.1.label`) to its refusal and is empty only when every button
     * is valid.
     *
     * @return array{actions: list<NormalizedAction>, errors: array<string, string>}
     */
    public static function normalize(mixed $actions): array
    {
        if (!is_array($actions) || !array_is_list($actions)) {
            return ['actions' => [], 'errors' => ['data.actions' => 'Buttons (data.actions) must be a list.']];
        }

        if ($actions === [] || count($actions) > PingAction::MAX_ACTIONS) {
            return ['actions' => [], 'errors' => ['data.actions' => 'A Ping takes 1 to ' . PingAction::MAX_ACTIONS . ' buttons.']];
        }

        $errors = [];
        $normalized = [];
        $seen = [];

        foreach ($actions as $index => $action) {
            $field = "data.actions.{$index}";
            $errorsBefore = count($errors);

            if (!is_array($action) || ($action !== [] && array_is_list($action))) {
                $errors[$field] = 'Each button must be an object.';

                continue;
            }

            $unknown = array_diff(array_keys($action), self::ACTION_KEYS);
            if ($unknown !== []) {
                $errors[$field] = 'Unknown button field: ' . implode(', ', array_map('strval', $unknown)) . '.';

                continue;
            }

            $id = $action['id'] ?? 'a' . ($index + 1);
            if (!is_string($id) || preg_match(PingAction::ID_PATTERN, $id) !== 1) {
                $errors["{$field}.id"] = 'A button id is 1 to 24 characters: lowercase letters, digits, "-" and "_".';
            } elseif (isset($seen[$id])) {
                $errors["{$field}.id"] = 'Each button on a Ping needs its own id.';
            } else {
                $seen[$id] = true;
            }

            $type = $action['type'] ?? null;
            if (!is_string($type) || !in_array($type, PingAction::TYPES, true)) {
                $errors["{$field}.type"] = 'A button type is open_url or webhook.';
                $type = null;
            }

            $style = $action['style'] ?? 'default';
            if (!is_string($style) || !in_array($style, PingAction::STYLES, true)) {
                $errors["{$field}.style"] = 'A button style is default, primary or danger.';
            }

            $label = self::cleanLabel($action['label'] ?? null);
            if ($label['error'] !== null) {
                $errors["{$field}.label"] = $label['error'];
            }

            $url = self::urlVerdict($action['url'] ?? null, $type ?? PingAction::OPEN_URL);
            if ($url['error'] !== null) {
                $errors["{$field}.url"] = $url['error'];
            }

            $body = $action['body'] ?? null;
            if ($body !== null) {
                $problem = $type === PingAction::WEBHOOK
                    ? self::bodyProblem($body)
                    : 'Only a webhook button takes a body.';
                if ($problem !== null) {
                    $errors["{$field}.body"] = $problem;
                }
            }

            if (count($errors) !== $errorsBefore) {
                continue;
            }

            $normalized[] = [
                'id' => is_string($id) ? $id : '',
                'position' => $index,
                'type' => $type ?? '',
                'label' => $label['label'],
                'style' => is_string($style) ? $style : 'default',
                'url' => $url['url'],
                'host' => $url['host'],
                'body' => is_array($body) && $body !== [] ? $body : null,
            ];
        }

        return $errors === [] ? ['actions' => $normalized, 'errors' => []] : ['actions' => [], 'errors' => $errors];
    }

    /**
     * Clean one label: NFC, refuse every control, private-use and invisible
     * formatting character (bidirectional overrides and isolates, LRM/RLM/ALM,
     * zero-width space, word joiner, BOM) but the zero-width (non-)joiners that
     * emoji and Persian need and the emoji tag block; collapse spaces; trim. Then
     * refuse an empty, overlong, address-shaped or reserved label.
     *
     * @return array{label: string, error: string|null}
     */
    public static function cleanLabel(mixed $label): array
    {
        if (!is_string($label) || !Str::isUtf8($label)) {
            return ['label' => '', 'error' => 'A button needs a label.'];
        }

        $label = \Normalizer::normalize($label, \Normalizer::FORM_C);
        if (!is_string($label)) {
            return ['label' => '', 'error' => 'A button label must be valid text.'];
        }

        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}\p{Co}\p{Cs}]/u', $label) === 1
            || preg_match('/(?![\x{200C}\x{200D}\x{E0020}-\x{E007F}])\p{Cf}/u', $label) === 1) {
            return ['label' => '', 'error' => 'A button label may not contain line breaks, control characters or invisible formatting characters.'];
        }

        $label = trim((string) preg_replace('/\p{Zs}+/u', ' ', $label));

        if ($label === '') {
            return ['label' => '', 'error' => 'A button needs a label.'];
        }

        if (Str::length($label) > PingAction::MAX_LABEL_LENGTH) {
            return ['label' => '', 'error' => 'A button label is at most ' . PingAction::MAX_LABEL_LENGTH . ' characters.'];
        }

        if (self::looksLikeAddress($label)) {
            return ['label' => '', 'error' => 'A button label may not contain a web address, domain name or IP address. The app shows where the button goes.'];
        }

        if (in_array(self::comparisonForm($label), self::RESERVED_LABELS, true)) {
            return ['label' => '', 'error' => 'That label is one of PingRoom\'s own buttons. Choose another.'];
        }

        return ['label' => $label, 'error' => null];
    }

    /**
     * Whether a label contains anything shaped like a destination: a scheme,
     * `www.`, a dotted IPv4 address, or `name.tld` with an alphabetic or punycode
     * TLD, after compatibility folding (full-width letters and the ideographic full
     * stops browsers treat as dots).
     */
    public static function looksLikeAddress(string $label): bool
    {
        $folded = (string) preg_replace('/\p{Cf}/u', '', $label);
        $folded = \Normalizer::normalize($folded, \Normalizer::FORM_KC);
        if (!is_string($folded)) {
            return true;
        }
        $folded = str_replace(["\u{3002}", "\u{FF0E}", "\u{FF61}"], '.', mb_strtolower($folded, 'UTF-8'));

        if (str_contains($folded, '://') || str_contains($folded, 'www.')) {
            return true;
        }

        if (preg_match('/(?<![\p{L}\p{N}])\d{1,3}(?:\.\d{1,3}){3}(?![\p{L}\p{N}])/u', $folded) === 1) {
            return true;
        }

        return preg_match(
            '/(?<![\p{L}\p{N}_-])[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?(?:\.[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?)*\.(?:\p{L}{2,}|xn--[a-z0-9-]+)(?![\p{L}\p{N}])/u',
            $folded,
        ) === 1;
    }

    /**
     * The form two labels are compared in: compatibility-folded, lowercased,
     * letters, digits and combining marks only. "👍 Pong Back", "pong-back" and
     * "ＰＯＮＧ ＢＡＣＫ" all compare as `pongback`.
     */
    public static function comparisonForm(string $label): string
    {
        $folded = \Normalizer::normalize($label, \Normalizer::FORM_KC);

        return (string) preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '', mb_strtolower(is_string($folded) ? $folded : $label, 'UTF-8'));
    }

    /**
     * The URL rules for both button types, and the host the server derives.
     *
     * Every byte is printable ASCII and the authority is a bare hostname (and, for
     * a link, a port), so PHP, the phone and the browser all read the same host out
     * of it: no userinfo, no backslash, no percent-encoding, no Unicode. A
     * non-ASCII hostname is refused rather than converted, so an internationalized
     * one has to arrive as punycode. Syntactic only: the server checks a `webhook`
     * target again when it delivers (DNS, private addresses), which this cannot.
     *
     * @return array{url: string, host: string, error: string|null}
     */
    public static function urlVerdict(mixed $url, string $type): array
    {
        $refuse = static fn(string $message): array => ['url' => '', 'host' => '', 'error' => $message];

        if (!is_string($url) || $url === '') {
            return $refuse('A button needs a url.');
        }

        if (strlen($url) > PingAction::MAX_URL_LENGTH) {
            return $refuse('A button url is at most ' . PingAction::MAX_URL_LENGTH . ' characters.');
        }

        if (!str_starts_with($url, 'https://')) {
            return $refuse('A button url must start with https://.');
        }

        if (preg_match('/^[\x21-\x7E]+\z/', $url) !== 1 || str_contains($url, '\\')) {
            return $refuse('A button url may contain only printable ASCII characters: percent-encode the rest, and give an internationalized domain in its punycode (xn--) form.');
        }

        $rest = substr($url, strlen('https://'));
        $authority = substr($rest, 0, strcspn($rest, '/?#'));

        if (preg_match('/^([A-Za-z0-9.-]+)(?::([0-9]{1,5}))?\z/', $authority, $match) !== 1) {
            return $refuse('A button url needs a plain hostname: no user name or password, no IP address in brackets.');
        }

        $host = strtolower($match[1]);
        $port = $match[2] ?? null;

        if ($port !== null) {
            if ($type === PingAction::WEBHOOK) {
                return $refuse('A webhook button url cannot name a port: deliveries go to the standard HTTPS port.');
            }

            if ((int) $port < 1 || (int) $port > 65535) {
                return $refuse('A button url has an invalid port.');
            }
        }

        if (!self::isPublicHostname($host)) {
            return $refuse('A button url needs a public hostname such as example.com: no IP address, localhost or internal name.');
        }

        // The same characters through PHP's own parser must name the same host,
        // or the two readings could disagree about where it goes.
        $parsed = parse_url($url);
        if (!is_array($parsed)
            || strtolower($parsed['host'] ?? '') !== $host
            || isset($parsed['user'])
            || isset($parsed['pass'])
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $refuse('A button url must be a valid https URL.');
        }

        return ['url' => $url, 'host' => $host, 'error' => null];
    }

    /**
     * A lowercase ASCII hostname of at least two labels whose last label is
     * alphabetic (or punycode) and not a special-use name. An alphabetic last label
     * is what refuses every IPv4 spelling (dotted, integer, octal, hex).
     */
    public static function isPublicHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || str_ends_with($host, '.')) {
            return false;
        }

        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $label) !== 1) {
                return false;
            }
        }

        $tld = $labels[count($labels) - 1];

        return preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})\z/', $tld) === 1
            && !in_array($tld, self::NON_PUBLIC_TLDS, true);
    }

    /**
     * A webhook button's `body`: a JSON object of at most 10 top-level keys and
     * 1024 bytes once encoded (PHP's escaped `json_encode()`, the measure `data`'s
     * 8 KB cap uses).
     */
    public static function bodyProblem(mixed $body): ?string
    {
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            return 'A webhook button body must be a JSON object.';
        }

        if (count($body) > PingAction::MAX_BODY_KEYS) {
            return 'A webhook button body takes at most ' . PingAction::MAX_BODY_KEYS . ' keys.';
        }

        try {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'A webhook button body must be valid UTF-8 JSON.';
        }

        if (strlen($encoded) > PingAction::MAX_BODY_BYTES) {
            return 'A webhook button body must not exceed ' . PingAction::MAX_BODY_BYTES . ' bytes.';
        }

        return null;
    }
}
