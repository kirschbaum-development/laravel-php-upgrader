<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Console;

use KirschbaumDevelopment\LaravelPhpUpgrader\Contracts\Provider;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    /** @param array<string, Provider> $providers */
    public static function make(array $providers = []): Application
    {
        $application = new Application('Laravel PHP Upgrader', '0.1.0-dev');
        $application->add(new UpgradeCommand(false, $providers));
        $application->add(new UpgradeCommand(true, $providers));
        $application->setDefaultCommand('upgrade');

        return $application;
    }
}
