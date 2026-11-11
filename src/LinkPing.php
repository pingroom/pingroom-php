<?php

declare(strict_types=1);

namespace PingRoom;

use PingRoom\Exception\PingRoomException;
use PingRoom\Internal\Str;

/**
 * Link pings: make a ping a tappable button that opens a URL.
 *
 * The server convention is two reserved keys inside the structured `data` object:
 * `url` (absolute http(s), at most 2048 characters) and `button_label` (at most 26
 * characters). This builds that fragment with client-side validation so a bad link
 * fails fast instead of at the API boundary:
 *
 *     $pr->broadcast('ab12cd', message: 'Build 512 ready', data: [
 *         'commit' => 'abc123',
 *         ...LinkPing::data('https://ci.example.com/b/512', 'Open build'),
 *     ]);
 */
final class LinkPing
{
    private function __construct() {}

    /**
     * Build the `data` fragment for a link ping; spread it into a ping's `data`.
     * Throws a PingRoomException (`invalid_data`) for a URL that is not absolute
     * http(s), or one that is too long.
     *
     * @param string|null $buttonLabel text on the button, at most 26 characters (a generic label is shown when omitted)
     *
     * @return array{url: string, button_label?: string}
     */
    public static function data(string $url, ?string $buttonLabel = null): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme']) || preg_match('/^[a-z][a-z0-9+.-]*\z/i', $parts['scheme']) !== 1) {
            throw PingRoomException::invalidData('linkPing: url is not a valid URL');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw PingRoomException::invalidData('linkPing: url must be an absolute http(s) URL');
        }
        $host = $parts['host'] ?? '';
        if ($host === '' || preg_match('/[\x00-\x20\x7F]/', $host) === 1) {
            throw PingRoomException::invalidData('linkPing: url is not a valid URL');
        }
        if (!Str::isUtf8($url)) {
            throw PingRoomException::invalidData('linkPing: url is not a valid URL');
        }
        if (Str::length($url) > Limits::MAX_LINK_URL_LENGTH) {
            throw PingRoomException::invalidData('linkPing: url must be at most ' . Limits::MAX_LINK_URL_LENGTH . ' characters');
        }
        if ($buttonLabel !== null && (!Str::isUtf8($buttonLabel) || Str::length($buttonLabel) > Limits::MAX_LINK_BUTTON_LABEL_LENGTH)) {
            throw PingRoomException::invalidData('linkPing: buttonLabel must be at most ' . Limits::MAX_LINK_BUTTON_LABEL_LENGTH . ' characters');
        }

        $out = ['url' => $url];
        if ($buttonLabel !== null) {
            $out['button_label'] = $buttonLabel;
        }

        return $out;
    }
}
