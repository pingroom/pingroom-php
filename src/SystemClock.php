<?php

declare(strict_types=1);

namespace PingRoom;

/** The real clock: `microtime()` and `usleep()`. */
final class SystemClock implements Clock
{
    public function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
