<?php

declare(strict_types=1);

namespace Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Assessment;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\RuntimeVersions;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Server;
use KirschbaumDevelopment\LaravelPhpUpgrader\Infrastructure\ForgeApi;
use KirschbaumDevelopment\LaravelPhpUpgrader\Infrastructure\PhpReleaseCatalog;
use KirschbaumDevelopment\LaravelPhpUpgrader\Providers\ForgeProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\Clock;
use Tests\Fakes\Inspector;

final class ForgeProviderTest extends TestCase
{
    private array $history = [];

    public function test_scans_every_page_and_organization_and_checks_fpm_too(): void
    {
        $inspector = new Inspector([
            new RuntimeVersions('8.3.35', '8.3.20'),
            new RuntimeVersions('8.3.35', '8.3.35'),
            new RuntimeVersions('8.3.20', '8.3.20'),
        ]);
        $provider = $this->provider([
            $this->page([$this->org('first')], 'org-next'),
            $this->page([$this->server(1)], 'server-next'),
            $this->page([$this->runtime(11)], 'php-next'),
            $this->page([$this->runtime(12)]),
            $this->page([$this->server(2)]),
            $this->page([$this->runtime(21)]),
            $this->page([$this->org('second')]),
            $this->page([$this->server(3)]),
            $this->page([]),
        ], $inspector);
        $report = $provider->scan();

        self::assertSame(['outdated', 'current', 'outdated', 'no-php'], array_column($report->assessments, 'status'));
        self::assertCount(3, $inspector->calls);
        self::assertSame([], $report->issues);
        self::assertStringContainsString('page%5Bcursor%5D=php-next', (string) $this->history[3]['request']->getUri());
        self::assertStringContainsString('page%5Bcursor%5D=server-next', (string) $this->history[4]['request']->getUri());
        self::assertStringContainsString('page%5Bcursor%5D=org-next', (string) $this->history[6]['request']->getUri());
        foreach ($this->history as $request) {
            self::assertSame('GET', $request['request']->getMethod());
        }
    }

    public function test_missing_ssh_access_is_unknown_and_other_servers_still_get_scanned(): void
    {
        $provider = $this->provider([
            $this->page([$this->org('first')]),
            $this->page([$this->server(1), $this->server(2)]),
            $this->page([$this->runtime(11)]),
            $this->page([$this->runtime(21)]),
        ], new Inspector([new \RuntimeException('SSH unavailable'), new RuntimeVersions('8.3.20', '8.3.20')]));
        $report = $provider->scan();
        self::assertSame(['unknown', 'outdated'], array_column($report->assessments, 'status'));
        self::assertCount(1, $report->outdated());
    }

    public function test_forbidden_organization_does_not_hide_other_organizations(): void
    {
        $provider = $this->provider([
            $this->page([$this->org('blocked'), $this->org('allowed')]),
            new Response(403),
            $this->page([$this->server(2)]),
            $this->page([$this->runtime(21)]),
        ], new Inspector([new RuntimeVersions('8.3.20', '8.3.20')]));
        $report = $provider->scan();
        self::assertCount(1, $report->issues);
        self::assertCount(1, $report->outdated());
        self::assertSame('allowed', $report->outdated()[0]->server->organization);
    }

    public function test_waits_for_idle_state_and_both_binaries_before_verifying(): void
    {
        $provider = $this->provider([
            $this->oneRuntime(), new Response(202),
            $this->oneRuntime(), $this->oneRuntime('updating'), $this->oneRuntime(),
        ], new Inspector([
            new RuntimeVersions('8.3.20', '8.3.20'),
            new RuntimeVersions('8.3.35', '8.3.20'),
            new RuntimeVersions('8.3.35', '8.3.35'),
        ]));
        $result = $provider->patchAndVerify($this->assessment());
        self::assertSame('verified', $result->status);
        self::assertCount(5, $this->history);
        self::assertSame('PUT', $this->history[1]['request']->getMethod());
        self::assertSame('/api/orgs/example/servers/1/php/versions/11', $this->history[1]['request']->getUri()->getPath());
    }

    public function test_accepted_patch_is_not_success_when_versions_do_not_change(): void
    {
        $provider = $this->provider([
            $this->oneRuntime(), new Response(202), $this->oneRuntime(), $this->oneRuntime(),
        ], new Inspector([new RuntimeVersions('8.3.20', '8.3.20')]), timeout: 2);
        self::assertSame('unverified', $provider->patchAndVerify($this->assessment())->status);
    }

    public function test_rechecks_version_and_skips_a_patch_that_was_already_applied(): void
    {
        $provider = $this->provider([$this->oneRuntime()], new Inspector([new RuntimeVersions('8.3.35', '8.3.35')]));
        self::assertSame('already-current', $provider->patchAndVerify($this->assessment())->status);
        self::assertCount(1, $this->history);
        self::assertSame('GET', $this->history[0]['request']->getMethod());
    }

    public function test_does_not_patch_busy_or_changed_installations(): void
    {
        $provider = $this->provider([$this->oneRuntime('updating')], new Inspector([]));
        self::assertSame('failed', $provider->patchAndVerify($this->assessment())->status);
        self::assertCount(1, $this->history);
    }

    public function test_never_retries_an_ambiguous_write(): void
    {
        $provider = $this->provider([
            $this->oneRuntime(),
            new ConnectException('Do not expose token-test-secret', new Request('PUT', 'https://forge.laravel.com/api/test')),
        ], new Inspector([new RuntimeVersions('8.3.20', '8.3.20')]));
        $result = $provider->patchAndVerify($this->assessment());
        self::assertSame('unverified', $result->status);
        self::assertStringNotContainsString('token-test-secret', $result->message);
        self::assertCount(2, $this->history);
    }

    public function test_exposes_explicit_forge_failure(): void
    {
        $provider = $this->provider([
            $this->oneRuntime(), new Response(202), $this->oneRuntime('update-failed'),
        ], new Inspector([new RuntimeVersions('8.3.20', '8.3.20')]));
        self::assertSame('failed', $provider->patchAndVerify($this->assessment())->status);
    }

    public function test_repeated_cursor_is_an_error_instead_of_an_infinite_scan(): void
    {
        $api = $this->api([$this->page([], 'repeat'), $this->page([], 'repeat')]);
        $this->expectException(\RuntimeException::class);
        iterator_to_array($api->collection('orgs'));
    }

    private function provider(array $responses, Inspector $inspector, int $timeout = 10): ForgeProvider
    {
        return new ForgeProvider($this->api($responses), $inspector,
            new PhpReleaseCatalog(new Client, ['8.3' => '8.3.35']), new Clock, $timeout, 1);
    }

    private function api(array $responses): ForgeApi
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ForgeApi(new Client(['handler' => $stack]), 'fake-token');
    }

    private function assessment(): Assessment
    {
        return new Assessment(new Server('forge', 'example', '1', 'web', '192.0.2.1'), '11', '8.3', new RuntimeVersions('8.3.20', '8.3.20'), '8.3.35', 'outdated');
    }

    private function page(array $data, ?string $cursor = null): Response
    {
        return new Response(200, [], json_encode(['data' => $data, 'meta' => ['next_cursor' => $cursor]], JSON_THROW_ON_ERROR));
    }

    private function org(string $slug): array
    {
        return ['id' => $slug, 'attributes' => ['slug' => $slug]];
    }

    private function server(int $id): array
    {
        return ['id' => (string) $id, 'attributes' => ['name' => 'server-'.$id, 'ip_address' => '192.0.2.'.$id, 'is_ready' => true, 'revoked' => false, 'ssh_port' => 22]];
    }

    private function runtime(int $id, string $status = 'installed'): array
    {
        return ['id' => (string) $id, 'attributes' => ['version' => '8.3', 'status' => $status]];
    }

    private function oneRuntime(string $status = 'installed'): Response
    {
        return new Response(200, [], json_encode(['data' => $this->runtime(11, $status)], JSON_THROW_ON_ERROR));
    }
}
