<?php

declare(strict_types=1);

namespace PingRoom\Tests\Support;

use PingRoom\Clock;

/** A clock whose sleeps advance its time and never block. */
final class FakeClock implements Clock
{
    /** @var list<int> */
    public array $sleeps = [];

    public function __construct(private int $now = 1_794_146_700_000) {}

    public function nowMs(): int
    {
        return $this->now;
    }

    public function sleepMs(int $ms): void
    {
        $this->sleeps[] = $ms;
        $this->now += $ms;
    }

    public function advance(int $ms): void
    {
        $this->now += $ms;
    }
}
