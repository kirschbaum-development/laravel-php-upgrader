<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Providers;

use KirschbaumDevelopment\LaravelPhpUpgrader\Contracts\Clock;
use KirschbaumDevelopment\LaravelPhpUpgrader\Contracts\Provider;
use KirschbaumDevelopment\LaravelPhpUpgrader\Contracts\RuntimeInspector;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Assessment;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\ScanReport;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Server;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\UpgradeResult;
use KirschbaumDevelopment\LaravelPhpUpgrader\Infrastructure\ForgeApi;
use KirschbaumDevelopment\LaravelPhpUpgrader\Infrastructure\PhpReleaseCatalog;
use RuntimeException;
use Throwable;

final readonly class ForgeProvider implements Provider
{
    public function __construct(
        private ForgeApi $api,
        private RuntimeInspector $inspector,
        private PhpReleaseCatalog $releases,
        private Clock $clock,
        private int $timeout = 600,
        private int $pollInterval = 5,
    ) {
        if ($timeout < 1 || $pollInterval < 1) {
            throw new \InvalidArgumentException('Timeout and poll interval must be positive.');
        }
    }

    public function name(): string
    {
        return 'forge';
    }

    public function scan(): ScanReport
    {
        $assessments = [];
        $issues = [];
        try {
            foreach ($this->api->collection('orgs') as $organization) {
                $slug = $organization['attributes']['slug'] ?? null;
                if (! is_string($slug) || $slug === '') {
                    $issues[] = 'Forge returned an organization without a slug.';

                    continue;
                }
                try {
                    foreach ($this->api->collection('orgs/'.rawurlencode($slug).'/servers') as $resource) {
                        $attributes = $resource['attributes'] ?? [];
                        $server = new Server('forge', $slug, (string) ($resource['id'] ?? ''),
                            (string) ($attributes['name'] ?? 'Unnamed server'),
                            $attributes['ip_address'] ?? null, (int) ($attributes['ssh_port'] ?? 22));
                        try {
                            if ($server->id === '' || ($attributes['revoked'] ?? false) || ! ($attributes['is_ready'] ?? false)) {
                                throw new RuntimeException('Server is not ready or Forge access is revoked.');
                            }
                            $found = false;
                            foreach ($this->api->collection($this->api->phpPath($slug, $server->id)) as $runtime) {
                                $found = true;
                                $assessments[] = $this->assess($server, $runtime);
                            }
                            if (! $found) {
                                $assessments[] = new Assessment($server, 'none', '-', null, null, 'no-php', 'No Forge-managed PHP installations.');
                            }
                        } catch (Throwable $exception) {
                            $issues[] = $server->key().': '.$exception->getMessage();
                        }
                    }
                } catch (Throwable $exception) {
                    $issues[] = 'forge:'.$slug.': '.$exception->getMessage();
                }
            }
        } catch (Throwable $exception) {
            $issues[] = 'forge: '.$exception->getMessage();
        }

        return new ScanReport($assessments, $issues);
    }

    /** @param array<string, mixed> $runtime */
    private function assess(Server $server, array $runtime): Assessment
    {
        $attributes = $runtime['attributes'] ?? [];
        $runtimeId = (string) ($runtime['id'] ?? '');
        $branch = (string) ($attributes['version'] ?? '');
        $current = null;
        $target = null;
        try {
            if ($runtimeId === '' || ! preg_match('/^\d+\.\d+$/D', $branch)) {
                throw new RuntimeException('Unrecognized Forge PHP installation.');
            }
            if (($attributes['status'] ?? '') !== 'installed') {
                throw new RuntimeException('PHP installation is not idle: '.($attributes['status'] ?? 'unknown').'.');
            }
            $target = $this->releases->latest($branch);
            $current = $this->inspector->read($server, $branch);
            if (! str_starts_with($current->cli, $branch.'.') || ! str_starts_with($current->fpm, $branch.'.')) {
                throw new RuntimeException('PHP binaries do not match the branch listed by Forge.');
            }

            return new Assessment($server, $runtimeId, $branch, $current, $target,
                $current->satisfies($target) ? 'current' : 'outdated');
        } catch (Throwable $exception) {
            return new Assessment($server, $runtimeId, $branch, $current, $target, 'unknown', $exception->getMessage());
        }
    }

    public function patchAndVerify(Assessment $assessment): UpgradeResult
    {
        if ($assessment->server->provider !== $this->name() || ! $assessment->isUpgradable()) {
            return new UpgradeResult($assessment, 'failed', 'This assessment cannot be patched by Forge.');
        }

        $server = $assessment->server;
        $requested = false;
        try {
            $fresh = $this->api->phpVersion($server->organization, $server->id, $assessment->runtimeId);
            if (($fresh['version'] ?? '') !== $assessment->branch || ($fresh['status'] ?? '') !== 'installed') {
                throw new RuntimeException('The PHP installation changed or is busy. Scan again.');
            }
            $current = $this->inspector->read($server, $assessment->branch);
            if ($current->satisfies($assessment->target)) {
                return new UpgradeResult($assessment, 'already-current', 'CLI and FPM already meet '.$assessment->target.'.');
            }
            if (! str_starts_with($current->cli, $assessment->branch.'.') || ! str_starts_with($current->fpm, $assessment->branch.'.')) {
                throw new RuntimeException('PHP binaries changed branches. Scan again.');
            }

            $requested = true;
            $this->api->requestPhpPatch($server->organization, $server->id, $assessment->runtimeId);
            $deadline = $this->clock->now() + $this->timeout;
            $lastProblem = 'CLI and FPM have not both reached '.$assessment->target.'.';
            do {
                $remaining = max(1, (int) ceil($deadline - $this->clock->now()));
                try {
                    $state = $this->api->phpVersion($server->organization, $server->id, $assessment->runtimeId, min(30, $remaining));
                    if (($state['version'] ?? '') !== $assessment->branch) {
                        return new UpgradeResult($assessment, 'unverified', 'The PHP branch changed during the update. Check Forge.');
                    }
                    $status = (string) ($state['status'] ?? 'unknown');
                    if (preg_match('/fail|error/i', $status)) {
                        return new UpgradeResult($assessment, 'failed', 'Forge reports PHP status: '.$status.'.');
                    }
                    if ($status === 'installed') {
                        $remaining = max(1, (int) ceil($deadline - $this->clock->now()));
                        $current = $this->inspector->read($server, $assessment->branch, min(20, $remaining));
                        if ($current->satisfies($assessment->target)) {
                            return new UpgradeResult($assessment, 'verified', 'Verified CLI '.$current->cli.' and FPM '.$current->fpm.'.');
                        }
                    }
                } catch (Throwable $exception) {
                    $lastProblem = $exception->getMessage();
                }
                $remaining = (int) floor($deadline - $this->clock->now());
                if ($remaining <= 0) {
                    break;
                }
                $this->clock->sleep(min($this->pollInterval, $remaining));
            } while ($this->clock->now() < $deadline);

            return new UpgradeResult($assessment, 'unverified', 'Verification timed out. '.$lastProblem.' Check Forge before retrying.');
        } catch (Throwable $exception) {
            return new UpgradeResult($assessment, $requested ? 'unverified' : 'failed', $exception->getMessage());
        }
    }
}
