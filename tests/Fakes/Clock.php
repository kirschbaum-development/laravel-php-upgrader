<?php

declare(strict_types=1);

namespace Tests\Fakes;

final class Clock implements \KirschbaumDevelopment\LaravelPhpUpgrader\Contracts\Clock
{
    private float $time = 0;

    public function now(): float
    {
        return $this->time;
    }

    public function sleep(int $seconds): void
    {
        $this->time += $seconds;
    }
}
