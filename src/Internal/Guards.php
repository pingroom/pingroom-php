<?php

declare(strict_types=1);

namespace PingRoom\Internal;

use PingRoom\Exception\PingRoomException;
use PingRoom\Limits;

/**
 * The client-side checks shared by every send. None is stricter than the server
 * route behind it.
 *
 * @internal
 */
final class Guards
{
    /** A letter or digit first, then letters, digits, `-` and `_`. A tag as the server stores it. */
    public const PING_TAG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}\z/';

    /** 1-64 characters of letters, digits and `. _ : -`. */
    public const REPLACE_KEY_PATTERN = '/^[A-Za-z0-9._:-]{1,64}\z/';

    private function __construct() {}

    /**
     * Refuse a value that is not a non-blank string.
     */
    public static function requireNonEmpty(mixed $value, string $field): void
    {
        if (!is_string($value) || Str::isBlank($value)) {
            throw PingRoomException::invalidRequest("`{$field}` is required.");
        }
    }

    /** A string field with a code-point cap. `null` is absent. */
    public static function assertMaxLength(mixed $value, int $max, string $field): void
    {
        if ($value === null) {
            return;
        }
        if (!is_string($value)) {
            throw PingRoomException::invalidRequest("`{$field}` must be a string.");
        }
        if (!Str::isUtf8($value)) {
            throw PingRoomException::invalidRequest("`{$field}` must be valid UTF-8.");
        }
        $length = Str::length($value);
        if ($length > $max) {
            throw PingRoomException::invalidRequest("`{$field}` may have at most {$max} characters (got {$length}).");
        }
    }

    public static function assertActionNumber(mixed $number): void
    {
        if ($number === null) {
            return;
        }
        if (!is_int($number) || $number < 1 || $number > 16) {
            throw PingRoomException::invalidRequest('action_number must be an integer 1–16.');
        }
    }

    /**
     * Check a ping's `tags` the way the server does: trimmed, lowercased, blanks
     * dropped and duplicates removed first, then at most 10, each matching the tag
     * rule. The payload is sent as given; the server normalizes it the same way.
     */
    public static function assertTags(mixed $tags, string $field = 'tags'): void
    {
        if ($tags === null) {
            return;
        }
        if (!is_array($tags) || !array_is_list($tags)) {
            throw PingRoomException::invalidRequest("`{$field}` must be an array of strings.");
        }
        $normalized = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                throw PingRoomException::invalidRequest("`{$field}` must be an array of strings.");
            }
            $clean = self::normalizeTag($tag);
            if ($clean !== '') {
                $normalized[$clean] = true;
            }
        }
        $normalized = array_keys($normalized);
        if (count($normalized) > Limits::MAX_PING_TAGS) {
            throw PingRoomException::invalidRequest(
                "`{$field}` may have at most " . Limits::MAX_PING_TAGS . ' tags (got ' . count($normalized) . ').',
            );
        }
        foreach ($normalized as $tag) {
            self::assertTagText((string) $tag, $field);
        }
    }

    /** Check the single `tag` a read filters by, with the same rule as one entry of `tags`. */
    public static function assertTag(mixed $tag, string $field = 'tag'): void
    {
        if ($tag === null) {
            return;
        }
        if (!is_string($tag)) {
            throw PingRoomException::invalidRequest("`{$field}` must be a string.");
        }
        self::assertTagText(self::normalizeTag($tag), $field);
    }

    /**
     * Check a `replaceKey` the way the server does. The key is sent as given (the
     * server does not trim it), so a key with a space or any other character
     * outside the set is refused here rather than as a 422.
     */
    public static function assertReplaceKey(mixed $value, string $field = 'replaceKey'): void
    {
        if ($value === null) {
            return;
        }
        if (!is_string($value)) {
            throw PingRoomException::invalidRequest("`{$field}` must be a string.");
        }
        if (preg_match(self::REPLACE_KEY_PATTERN, $value) !== 1) {
            throw PingRoomException::invalidRequest(
                "`{$field}` must be 1–" . Limits::MAX_REPLACE_KEY_LENGTH . ' characters: letters, digits, ".", "_", ":" and "-".',
            );
        }
    }

    /**
     * Normalize an idempotency key the way the server reads it
     * (`RequestIdempotency::keyFrom`): trimmed with PHP's `trim()`, then 1 to 255
     * characters. It travels as the `Idempotency-Key` header, so it must also be
     * printable ASCII (space included): a header cannot carry anything else.
     */
    public static function normalizeIdempotencyKey(mixed $value, string $field = 'idempotencyKey'): string
    {
        if (!is_string($value)) {
            throw PingRoomException::invalidRequest("`{$field}` must be a string.");
        }
        $key = trim($value, " \t\n\r\0\x0B");
        if (strlen($key) > Limits::MAX_IDEMPOTENCY_KEY_LENGTH || preg_match('/^[\x20-\x7E]+\z/', $key) !== 1) {
            throw PingRoomException::invalidRequest(
                "`{$field}` must be 1–" . Limits::MAX_IDEMPOTENCY_KEY_LENGTH
                . ' printable ASCII characters (letters, digits, punctuation or spaces).',
            );
        }

        return $key;
    }

    /**
     * The headers an optional key adds: none when the key is absent.
     *
     * @return array<string, string>
     */
    public static function idempotencyHeaders(?string $key): array
    {
        return $key === null ? [] : ['Idempotency-Key' => self::normalizeIdempotencyKey($key)];
    }

    /**
     * Validate a `text`/`message`-style ping body for a send: the message is
     * required, then the same limits for every sender.
     */
    public static function assertPingText(mixed $message, mixed $title): void
    {
        self::requireNonEmpty($message, 'message');
        self::assertMaxLength($message, Limits::MAX_PUBLIC_PING_MESSAGE_LENGTH, 'message');
        self::assertMaxLength($title, Limits::MAX_PING_TITLE_LENGTH, 'title');
    }

    /** What the server does to a tag before it checks it: trim, then lowercase ASCII only. */
    private static function normalizeTag(string $tag): string
    {
        return Str::lowerAscii(Str::trimTag($tag));
    }

    private static function assertTagText(string $tag, string $field): void
    {
        if (preg_match(self::PING_TAG_PATTERN, $tag) === 1) {
            return;
        }
        $shown = Str::isUtf8($tag) && Str::length($tag) > 40 ? mb_substr($tag, 0, 40, 'UTF-8') . '…' : $tag;
        $shown = Str::isUtf8($shown)
            ? (json_encode($shown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '""')
            : '"(not valid UTF-8)"';
        throw PingRoomException::invalidRequest(
            "`{$field}` {$shown} is not a valid tag: a tag is 1 to " . Limits::MAX_PING_TAG_LENGTH
            . ' characters: lowercase letters, digits, "-" and "_", starting with a letter or digit.',
        );
    }
}
