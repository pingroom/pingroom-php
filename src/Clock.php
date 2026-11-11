<?php

declare(strict_types=1);

namespace PingRoom;

/**
 * The time source and sleeper the wait loops use. Inject your own to make them
 * deterministic in tests (a fake whose `sleepMs()` advances `nowMs()` and never
 * sleeps for real).
 */
interface Clock
{
    /** Unix time in milliseconds. */
    public function nowMs(): int;

    /** Block for `$ms` milliseconds. */
    public function sleepMs(int $ms): void;
}
