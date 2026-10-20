<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Contracts;

interface Clock
{
    public function now(): float;

    public function sleep(int $seconds): void;
}
