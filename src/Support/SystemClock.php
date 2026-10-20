<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Support;

use KirschbaumDevelopment\LaravelPhpUpgrader\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
