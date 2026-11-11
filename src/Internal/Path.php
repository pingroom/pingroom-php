<?php

declare(strict_types=1);

namespace PingRoom\Internal;

use PingRoom\Exception\PingRoomException;

/**
 * Path segments and query strings, encoded the way the TypeScript SDK's
 * `encodeURIComponent()` and `URLSearchParams` encode them.
 *
 * @internal
 */
final class Path
{
    private function __construct() {}

    /**
     * One path segment, as `encodeURIComponent()` writes it. A segment that is
     * empty, `.` or `..` is refused: URL resolution would turn `..` into another
     * route (`live.get('AB12', '..')` would request the room itself).
     */
    public static function segment(string $segment): string
    {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw PingRoomException::invalidRequest('A path parameter may not be empty, `.` or `..`.');
        }
        if (!Str::isUtf8($segment)) {
            throw PingRoomException::invalidRequest('A path parameter must be valid UTF-8.');
        }

        // rawurlencode() also escapes ! * ' ( ), which encodeURIComponent() leaves alone.
        return strtr(rawurlencode($segment), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }

    /**
     * A query string (without the `?`), in the `application/x-www-form-urlencoded`
     * form `URLSearchParams` writes: a space is `+`, and only letters, digits and
     * `*-._` are left as they are. Parameters whose value is null are left out.
     *
     * @param array<string, int|string|bool|null> $params
     */
    public static function query(array $params): string
    {
        $pairs = [];
        foreach ($params as $name => $value) {
            if ($value === null) {
                continue;
            }
            $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $pairs[] = self::formEncode($name) . '=' . self::formEncode($text);
        }

        return implode('&', $pairs);
    }

    private static function formEncode(string $value): string
    {
        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if (preg_match('/^[A-Za-z0-9*\-._]\z/', $char) === 1) {
                $out .= $char;
            } elseif ($char === ' ') {
                $out .= '+';
            } else {
                $out .= '%' . strtoupper(bin2hex($char));
            }
        }

        return $out;
    }
}
