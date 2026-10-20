<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Data;

final readonly class UpgradeResult
{
    public function __construct(public Assessment $assessment, public string $status, public string $message) {}

    public function succeeded(): bool
    {
        return in_array($this->status, ['verified', 'already-current'], true);
    }
}
