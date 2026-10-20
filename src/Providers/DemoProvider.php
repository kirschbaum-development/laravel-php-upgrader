<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Providers;

use KirschbaumDevelopment\LaravelPhpUpgrader\Contracts\Provider;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Assessment;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\RuntimeVersions;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\ScanReport;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Server;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\UpgradeResult;

final class DemoProvider implements Provider
{
    public function name(): string
    {
        return 'demo';
    }

    public function scan(): ScanReport
    {
        $web = new Server('demo', 'example', '1', 'example-web');
        $worker = new Server('demo', 'example', '2', 'example-worker');

        return new ScanReport([
            new Assessment($web, '11', '8.3', new RuntimeVersions('8.3.20', '8.3.20'), '8.3.35', 'outdated'),
            new Assessment($web, '12', '8.4', new RuntimeVersions('8.4.26', '8.4.26'), '8.4.26', 'current'),
            new Assessment($worker, '21', '8.3', new RuntimeVersions('8.3.20', '8.3.20'), '8.3.35', 'outdated'),
        ]);
    }

    public function patchAndVerify(Assessment $assessment): UpgradeResult
    {
        return new UpgradeResult($assessment, 'verified', 'Demo only: no server was contacted.');
    }
}
