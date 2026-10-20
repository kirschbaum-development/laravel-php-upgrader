<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Contracts;

use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Assessment;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\ScanReport;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\UpgradeResult;

interface Provider
{
    public function name(): string;

    public function scan(): ScanReport;

    public function patchAndVerify(Assessment $assessment): UpgradeResult;
}
