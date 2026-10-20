<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Infrastructure;

use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use RuntimeException;

final class PhpReleaseCatalog
{
    /** @var array<string, string> */
    private array $cache = [];

    /** @param array<string, string> $minimums */
    public function __construct(private readonly ClientInterface $http, private readonly array $minimums = [])
    {
        foreach ($minimums as $branch => $version) {
            if (! preg_match('/^\d+\.\d+$/D', (string) $branch)
                || ! preg_match('/^'.preg_quote((string) $branch, '/').'\.\d+$/D', $version)) {
                throw new InvalidArgumentException('Minimums must use branch=patch, such as 8.3=8.3.35.');
            }
        }
    }

    public function latest(string $branch): string
    {
        if (isset($this->minimums[$branch])) {
            return $this->minimums[$branch];
        }
        if (isset($this->cache[$branch])) {
            return $this->cache[$branch];
        }
        if (! preg_match('/^\d+\.\d+$/D', $branch)) {
            throw new InvalidArgumentException('Invalid PHP branch.');
        }

        try {
            $response = $this->http->request('GET', 'https://www.php.net/releases/index.php', [
                'query' => ['json' => '', 'version' => $branch, 'max' => 1],
                'timeout' => 20, 'connect_timeout' => 10, 'allow_redirects' => false,
            ]);
            $releases = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $versions = is_array($releases) ? array_keys($releases) : [];
            $versions = array_filter($versions, fn ($version) => is_string($version)
                && preg_match('/^'.preg_quote($branch, '/').'\.\d+$/D', $version));
            if ($versions === []) {
                throw new RuntimeException;
            }
            usort($versions, fn ($a, $b) => version_compare($b, $a));
        } catch (\Throwable) {
            throw new RuntimeException('Could not read the stable PHP release for '.$branch.'. Use --minimum='.$branch.'='.$branch.'.x with a confirmed version.');
        }

        return $this->cache[$branch] = $versions[0];
    }
}
