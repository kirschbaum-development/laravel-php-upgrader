<?php

declare(strict_types=1);

namespace Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\RuntimeVersions;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Server;
use KirschbaumDevelopment\LaravelPhpUpgrader\Infrastructure\PhpReleaseCatalog;
use KirschbaumDevelopment\LaravelPhpUpgrader\Infrastructure\SshRuntimeInspector;
use PHPUnit\Framework\TestCase;

final class VersionsAndSshTest extends TestCase
{
    public function test_versions_use_semantic_comparison_and_never_cross_branches(): void
    {
        self::assertTrue((new RuntimeVersions('8.3.10', '8.3.10'))->satisfies('8.3.9'));
        self::assertFalse((new RuntimeVersions('8.3.35', '8.3.20'))->satisfies('8.3.35'));
        self::assertFalse((new RuntimeVersions('8.4.1', '8.4.1'))->satisfies('8.3.35'));
    }

    public function test_php_feed_ignores_prereleases_and_sorts_versions(): void
    {
        $catalog = $this->catalog(['8.3.9' => [], '8.3.10' => [], '8.3.11RC1' => []]);
        self::assertSame('8.3.10', $catalog->latest('8.3'));
        self::assertSame('8.3.10', $catalog->latest('8.3'));
    }

    public function test_confirmed_target_overrides_feed_without_network(): void
    {
        $catalog = new PhpReleaseCatalog(new Client(['handler' => HandlerStack::create(new MockHandler([]))]), ['8.3' => '8.3.35']);
        self::assertSame('8.3.35', $catalog->latest('8.3'));
    }

    public function test_cannot_set_target_in_a_different_branch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PhpReleaseCatalog(new Client, ['8.3' => '8.4.1']);
    }

    public function test_bad_feed_does_not_guess_a_version(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->catalog(['8.4.1' => []])->latest('8.3');
    }

    public function test_ssh_uses_argument_array_batch_mode_and_existing_host_trust(): void
    {
        $command = (new SshRuntimeInspector('forge', '/tmp/key with spaces'))->command(new Server('forge', 'example', '1', 'web', '192.0.2.1', 2222), '8.3');
        self::assertContains('BatchMode=yes', $command);
        self::assertContains('StrictHostKeyChecking=yes', $command);
        self::assertContains('/tmp/key with spaces', $command);
        self::assertContains('2222', $command);
        self::assertSame('forge@192.0.2.1', $command[count($command) - 2]);
        self::assertSame("sh -s -- '8.3'", $command[count($command) - 1]);
    }

    public function test_remote_branch_cannot_inject_shell_commands(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SshRuntimeInspector)->command(new Server('forge', 'example', '1', 'web', '192.0.2.1'), '8.3; touch /tmp/no');
    }

    public function test_remote_host_cannot_inject_ssh_options(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SshRuntimeInspector)->command(new Server('forge', 'example', '1', 'web', '-oProxyCommand=anything'), '8.3');
    }

    private function catalog(array $releases): PhpReleaseCatalog
    {
        return new PhpReleaseCatalog(new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode($releases, JSON_THROW_ON_ERROR)),
        ]))]));
    }
}
