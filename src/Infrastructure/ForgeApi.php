<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Infrastructure;

use GuzzleHttp\ClientInterface;
use RuntimeException;

final readonly class ForgeApi
{
    public function __construct(private ClientInterface $http, private string $token) {}

    /** @return iterable<array<string, mixed>> */
    public function collection(string $path): iterable
    {
        $cursor = null;
        $seen = [];

        do {
            $query = ['page[size]' => 100];
            if ($cursor !== null) {
                $query['page[cursor]'] = $cursor;
            }
            $page = $this->get($path, $query);
            if (! isset($page['data']) || ! is_array($page['data'])) {
                throw new RuntimeException('Forge returned an invalid collection.');
            }
            foreach ($page['data'] as $resource) {
                if (! is_array($resource)) {
                    throw new RuntimeException('Forge returned an invalid resource.');
                }
                yield $resource;
            }
            $cursor = $page['meta']['next_cursor'] ?? null;
            if ($cursor !== null) {
                if (! is_string($cursor) || isset($seen[$cursor])) {
                    throw new RuntimeException('Forge returned an invalid or repeated pagination cursor.');
                }
                $seen[$cursor] = true;
            }
        } while ($cursor !== null);
    }

    /** @return array<string, mixed> */
    public function phpVersion(string $organization, string $serverId, string $runtimeId, int $timeout = 30): array
    {
        $response = $this->get($this->phpPath($organization, $serverId).'/'.rawurlencode($runtimeId), timeout: $timeout);
        if (! isset($response['data']['attributes']) || ! is_array($response['data']['attributes'])) {
            throw new RuntimeException('Forge returned an invalid PHP version resource.');
        }

        return $response['data']['attributes'];
    }

    public function requestPhpPatch(string $organization, string $serverId, string $runtimeId): void
    {
        // A write is intentionally never retried: an interrupted response may still mean it was accepted.
        $this->request('PUT', $this->phpPath($organization, $serverId).'/'.rawurlencode($runtimeId));
    }

    public function phpPath(string $organization, string $serverId): string
    {
        return 'orgs/'.rawurlencode($organization).'/servers/'.rawurlencode($serverId).'/php/versions';
    }

    /** @param array<string, int|string> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = [], int $timeout = 30): array
    {
        $body = $this->request('GET', $path, $query, $timeout);
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Forge returned invalid JSON.');
        }

        return $decoded;
    }

    /** @param array<string, int|string> $query */
    private function request(string $method, string $path, array $query = [], int $timeout = 30): string
    {
        try {
            $response = $this->http->request($method, 'https://forge.laravel.com/api/'.$path, [
                'headers' => ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/vnd.api+json', 'Content-Type' => 'application/json'],
                'query' => $query,
                'timeout' => max(1, $timeout),
                'connect_timeout' => min(10, max(1, $timeout)),
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (\Throwable) {
            throw new RuntimeException('Forge request could not be completed. If patching, check Forge before retrying.');
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(match ($status) {
                401 => 'Forge authentication failed. Check FORGE_API_TOKEN.',
                403 => 'Forge denied access. Check organization and server permissions.',
                429 => 'Forge rate limit reached. Wait before scanning again.',
                default => 'Forge returned HTTP '.$status.'.',
            });
        }

        return (string) $response->getBody();
    }
}
