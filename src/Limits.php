<?php

declare(strict_types=1);

namespace PingRoom;

/**
 * The server's limits on what a ping carries, checked here before any request so
 * an oversized one fails fast. They are pinned to the server by the contract
 * fixtures under `tests/contracts/`.
 */
final class Limits
{
    /** The 8 KB cap on `data`, measured as PHP's escaped `json_encode()` writes it. */
    public const MAX_DATA_BYTES = 8192;

    /** The cap on top-level keys of `data`. */
    public const MAX_DATA_KEYS = 25;

    /** A ping's message, in code points, in a private room. The SDK cannot tell room visibility, so it enforces only {@see self::MAX_PUBLIC_PING_MESSAGE_LENGTH}. */
    public const MAX_PRIVATE_PING_MESSAGE_LENGTH = 120;

    /** A ping's message, in code points, in a public room. */
    public const MAX_PUBLIC_PING_MESSAGE_LENGTH = 160;

    public const MAX_PING_TITLE_LENGTH = 40;

    /** At most this many tags on a ping. */
    public const MAX_PING_TAGS = 10;

    /** Each tag is 1 to this many characters. */
    public const MAX_PING_TAG_LENGTH = 32;

    /** A `replaceKey` is 1 to this many characters. */
    public const MAX_REPLACE_KEY_LENGTH = 64;

    /** An idempotency key is 1 to this many characters. */
    public const MAX_IDEMPOTENCY_KEY_LENGTH = 255;

    /** One attachment, in bytes. */
    public const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;

    /** A link ping's `url`, in code points. */
    public const MAX_LINK_URL_LENGTH = 2048;

    /** A link ping's `button_label`, in code points. */
    public const MAX_LINK_BUTTON_LABEL_LENGTH = 26;

    private function __construct() {}
}
