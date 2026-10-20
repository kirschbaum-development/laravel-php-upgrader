<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Contracts;

use KirschbaumDevelopment\LaravelPhpUpgrader\Data\RuntimeVersions;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Server;

interface RuntimeInspector
{
    public function read(Server $server, string $branch, int $timeout = 20): RuntimeVersions;
}
