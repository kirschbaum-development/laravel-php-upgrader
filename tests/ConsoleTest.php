<?php

declare(strict_types=1);

namespace Tests;

use KirschbaumDevelopment\LaravelPhpUpgrader\Console\ApplicationFactory;
use KirschbaumDevelopment\LaravelPhpUpgrader\Contracts\Provider;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Assessment;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\RuntimeVersions;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\ScanReport;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Server;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\UpgradeResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleTest extends TestCase
{
    public function test_demo_scan_is_machine_readable_and_read_only(): void
    {
        $tester = new CommandTester(ApplicationFactory::make()->find('scan'));
        self::assertSame(0, $tester->execute(['--provider' => ['demo'], '--json' => true], ['interactive' => false]));
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(3, $data['assessments']);
        self::assertSame([], $data['issues']);
    }

    public function test_non_interactive_upgrade_needs_explicit_selection_and_approval(): void
    {
        $provider = new RecordingProvider;
        $tester = $this->tester($provider);
        self::assertSame(1, $tester->execute(['--provider' => ['recording'], '--all' => true, '--json' => true], ['interactive' => false]));
        self::assertSame([], $provider->patched);
        self::assertSame(0, $provider->scans);
    }

    public function test_dry_run_never_patches_even_with_yes(): void
    {
        $provider = new RecordingProvider;
        $tester = $this->tester($provider);
        self::assertSame(0, $tester->execute(['--provider' => ['recording'], '--all' => true, '--yes' => true, '--dry-run' => true, '--json' => true], ['interactive' => false]));
        self::assertSame([], $provider->patched);
        self::assertCount(2, json_decode($tester->getDisplay(), true)['assessments']);
    }

    public function test_only_selected_server_is_patched(): void
    {
        $provider = new RecordingProvider;
        $tester = $this->tester($provider);
        self::assertSame(0, $tester->execute(['--provider' => ['recording'], '--server' => ['recording:org/2'], '--yes' => true, '--json' => true], ['interactive' => false]));
        self::assertSame(['2'], $provider->patched);
        self::assertSame('verified', json_decode($tester->getDisplay(), true)['results'][0]['status']);
    }

    public function test_stops_before_next_server_when_first_patch_is_unverified(): void
    {
        $provider = new RecordingProvider(fail: true);
        $tester = $this->tester($provider);
        self::assertSame(1, $tester->execute(['--provider' => ['recording'], '--all' => true, '--yes' => true, '--json' => true], ['interactive' => false]));
        self::assertSame(['1'], $provider->patched);
        self::assertSame(['unverified', 'not-run'], array_column(json_decode($tester->getDisplay(), true)['results'], 'status'));
    }

    public function test_unknown_server_key_does_not_patch_anything(): void
    {
        $provider = new RecordingProvider;
        $tester = $this->tester($provider);
        self::assertSame(1, $tester->execute(['--provider' => ['recording'], '--server' => ['recording:org/typo'], '--yes' => true, '--json' => true], ['interactive' => false]));
        self::assertSame([], $provider->patched);
    }

    public function test_incomplete_scan_is_not_reported_as_a_clean_success(): void
    {
        $provider = new RecordingProvider(incomplete: true);
        $tester = new CommandTester(ApplicationFactory::make(['recording' => $provider])->find('scan'));
        self::assertSame(2, $tester->execute(['--provider' => ['recording'], '--json' => true], ['interactive' => false]));
        self::assertSame(['One server could not be inspected.'], json_decode($tester->getDisplay(), true)['issues']);
    }

    public function test_upgrade_keeps_unknown_inspection_reasons_visible(): void
    {
        $provider = new class implements Provider
        {
            public function name(): string
            {
                return 'unknown';
            }

            public function scan(): ScanReport
            {
                return new ScanReport([new Assessment(new Server('unknown', 'org', '1', 'web'), '11', '8.3', null, '8.3.35', 'unknown', 'SSH unavailable')]);
            }

            public function patchAndVerify(Assessment $assessment): UpgradeResult
            {
                throw new \LogicException('An unknown installation must never be patched.');
            }
        };
        $tester = new CommandTester(ApplicationFactory::make(['unknown' => $provider])->find('upgrade'));
        self::assertSame(2, $tester->execute(['--provider' => ['unknown'], '--all' => true, '--yes' => true, '--json' => true], ['interactive' => false]));
        self::assertSame(['unknown:org/1/11: SSH unavailable'], json_decode($tester->getDisplay(), true)['issues']);
    }

    private function tester(RecordingProvider $provider): CommandTester
    {
        return new CommandTester(ApplicationFactory::make(['recording' => $provider])->find('upgrade'));
    }
}

final class RecordingProvider implements Provider
{
    public array $patched = [];

    public int $scans = 0;

    public function __construct(private bool $fail = false, private bool $incomplete = false) {}

    public function name(): string
    {
        return 'recording';
    }

    public function scan(): ScanReport
    {
        $this->scans++;

        return new ScanReport(array_map(fn ($id) => new Assessment(
            new Server('recording', 'org', $id, 'web-'.$id), '11', '8.3',
            new RuntimeVersions('8.3.20', '8.3.20'), '8.3.35', 'outdated'), ['2', '1']),
            $this->incomplete ? ['One server could not be inspected.'] : []);
    }

    public function patchAndVerify(Assessment $assessment): UpgradeResult
    {
        $this->patched[] = $assessment->server->id;

        return new UpgradeResult($assessment, $this->fail ? 'unverified' : 'verified', 'Test result.');
    }
}
