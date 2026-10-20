<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Data;

use InvalidArgumentException;

final readonly class RuntimeVersions
{
    public function __construct(public string $cli, public string $fpm)
    {
        foreach ([$cli, $fpm] as $version) {
            if (! preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
                throw new InvalidArgumentException('Expected a stable PHP patch version.');
            }
        }
    }

    public function satisfies(string $target): bool
    {
        $branch = substr($target, 0, strrpos($target, '.'));

        return str_starts_with($this->cli, $branch.'.')
            && str_starts_with($this->fpm, $branch.'.')
            && version_compare($this->cli, $target, '>=')
            && version_compare($this->fpm, $target, '>=');
    }
}
