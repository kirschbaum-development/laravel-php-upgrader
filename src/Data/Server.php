<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Data;

final readonly class Server
{
    public function __construct(
        public string $provider,
        public string $organization,
        public string $id,
        public string $name,
        public ?string $host = null,
        public int $sshPort = 22,
    ) {}

    public function key(): string
    {
        return $this->provider.':'.$this->organization.'/'.$this->id;
    }
}
