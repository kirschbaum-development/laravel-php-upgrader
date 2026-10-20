<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Infrastructure;

use KirschbaumDevelopment\LaravelPhpUpgrader\Contracts\RuntimeInspector;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\RuntimeVersions;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Server;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class SshRuntimeInspector implements RuntimeInspector
{
    public function __construct(private string $user = 'forge', private ?string $identity = null) {}

    /** @return list<string> */
    public function command(Server $server, string $branch): array
    {
        if (! preg_match('/^\d+\.\d+$/D', $branch)
            || ! preg_match('/^[a-z_][a-z0-9_-]*$/iD', $this->user)
            || ! filter_var($server->host, FILTER_VALIDATE_IP)
            || $server->sshPort < 1 || $server->sshPort > 65535) {
            throw new RuntimeException('Invalid SSH host, user, port, or PHP branch.');
        }

        $command = ['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=10', '-p', (string) $server->sshPort];
        if ($this->identity !== null) {
            $command[] = '-i';
            $command[] = $this->identity;
        }
        $command[] = '--';
        $command[] = $this->user.'@'.$server->host;
        $command[] = 'sh -s -- '.escapeshellarg($branch);

        return $command;
    }

    public function read(Server $server, string $branch, int $timeout = 20): RuntimeVersions
    {
        $process = new Process($this->command($server, $branch), env: ['FORGE_API_TOKEN' => false, 'LARAVEL_CLOUD_TOKEN' => false]);
        $process->setInput(<<<'SH'
set -eu
branch="$1"
"/usr/bin/php${branch}" -n -r 'echo PHP_VERSION, "\n";'
"/usr/sbin/php-fpm${branch}" -n -v
SH);
        $process->setTimeout(max(1, $timeout));
        try {
            $process->mustRun();
        } catch (\Throwable) {
            throw new RuntimeException('SSH inspection failed. Check SSH access, known_hosts, and PHP CLI/FPM binaries.');
        }
        $output = $process->getOutput();
        if (! preg_match('/^(\d+\.\d+\.\d+)\RPHP (\d+\.\d+\.\d+)(?:\s|$)/', $output, $matches)) {
            throw new RuntimeException('Could not read stable PHP CLI and FPM patch versions over SSH.');
        }

        return new RuntimeVersions($matches[1], $matches[2]);
    }
}
