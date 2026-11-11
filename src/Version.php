<?php

declare(strict_types=1);

namespace PingRoom;

/**
 * The SDK version, in one place. The User-Agent (`pingroom-php/<version>`) and
 * the package version in composer metadata both follow it.
 */
final class Version
{
    public const VERSION = '0.1.0';

    private function __construct() {}
}
