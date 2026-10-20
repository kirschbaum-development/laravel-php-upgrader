<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Data;

final readonly class Assessment
{
    public function __construct(
        public Server $server,
        public string $runtimeId,
        public string $branch,
        public ?RuntimeVersions $current,
        public ?string $target,
        public string $status,
        public string $reason = '',
    ) {}

    public function key(): string
    {
        return $this->server->key().'/'.$this->runtimeId;
    }

    public function isUpgradable(): bool
    {
        return $this->status === 'outdated' && $this->target !== null && $this->current !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'server' => $this->server->key(),
            'name' => $this->server->name,
            'branch' => $this->branch,
            'cli' => $this->current?->cli,
            'fpm' => $this->current?->fpm,
            'target' => $this->target,
            'status' => $this->status,
            'reason' => $this->reason,
        ];
    }
}
