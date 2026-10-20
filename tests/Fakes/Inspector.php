<?php

declare(strict_types=1);

namespace Tests\Fakes;

use KirschbaumDevelopment\LaravelPhpUpgrader\Contracts\RuntimeInspector;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\RuntimeVersions;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Server;
use Throwable;

final class Inspector implements RuntimeInspector
{
    public array $calls = [];

    public function __construct(private array $answers) {}

    public function read(Server $server, string $branch, int $timeout = 20): RuntimeVersions
    {
        $this->calls[] = [$server->key(), $branch];
        $answer = count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }
}
