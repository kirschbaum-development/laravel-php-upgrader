<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Console;

use GuzzleHttp\Client;
use KirschbaumDevelopment\LaravelPhpUpgrader\Actions\RunUpgrades;
use KirschbaumDevelopment\LaravelPhpUpgrader\Contracts\Provider;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Assessment;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\ScanReport;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\UpgradeResult;
use KirschbaumDevelopment\LaravelPhpUpgrader\Infrastructure\ForgeApi;
use KirschbaumDevelopment\LaravelPhpUpgrader\Infrastructure\PhpReleaseCatalog;
use KirschbaumDevelopment\LaravelPhpUpgrader\Infrastructure\SshRuntimeInspector;
use KirschbaumDevelopment\LaravelPhpUpgrader\Providers\DemoProvider;
use KirschbaumDevelopment\LaravelPhpUpgrader\Providers\ForgeProvider;
use KirschbaumDevelopment\LaravelPhpUpgrader\Support\SystemClock;
use Laravel\Prompts\Prompt;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;

final class UpgradeCommand extends Command
{
    /** @param array<string, Provider> $registered */
    public function __construct(private readonly bool $scanOnly, private readonly array $registered = [])
    {
        parent::__construct($scanOnly ? 'scan' : 'upgrade');
    }

    protected function configure(): void
    {
        $this->setDescription($this->scanOnly ? 'Inspect PHP versions without changing servers.' : 'Select servers and patch their outdated PHP installations sequentially.')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Provider name; repeat for multiple providers.', [])
            ->addOption('providers-file', null, InputOption::VALUE_REQUIRED, 'Explicit PHP file returning additional named Provider instances.')
            ->addOption('minimum', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Confirmed branch=patch target, e.g. 8.3=8.3.35.', [])
            ->addOption('ssh-user', null, InputOption::VALUE_REQUIRED, 'SSH user for Forge version inspection.', 'forge')
            ->addOption('identity', null, InputOption::VALUE_REQUIRED, 'Optional SSH identity file.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to verify each Forge patch.', '600')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output.');
        if (! $this->scanOnly) {
            $this->addOption('server', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exact server key from scan, e.g. forge:my-org/123.', [])
                ->addOption('all', null, InputOption::VALUE_NONE, 'Select all servers with confirmed outdated installations.')
                ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Approve the selected patch requests.')
                ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Display the selected plan without requesting patches.');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interactive = $input->isInteractive() && defined('STDIN') && stream_isatty(STDIN) && ! $input->getOption('json');
        Prompt::setOutput($output);
        try {
            if (! $this->scanOnly && ! $interactive && ! $input->getOption('dry-run')) {
                if (! $input->getOption('yes') || (! $input->getOption('all') && $input->getOption('server') === [])) {
                    throw new RuntimeException('Non-interactive upgrades require --yes and explicit --server keys or --all. Use scan or --dry-run to inspect.');
                }
            }
            if (! $this->scanOnly && $input->getOption('all') && $input->getOption('server') !== []) {
                throw new RuntimeException('Choose --all or --server, not both.');
            }
            $providers = $this->providers($input, $interactive);
            $all = [];
            $issues = [];
            foreach ($providers as $provider) {
                if (! $input->getOption('json')) {
                    $output->writeln('Scanning '.OutputFormatter::escape($provider->name()).'…');
                }
                $report = $provider->scan();
                array_push($all, ...$report->assessments);
                array_push($issues, ...$report->issues);
            }
            foreach ($all as $assessment) {
                if ($assessment->status === 'unknown') {
                    $issues[] = $assessment->key().': '.$assessment->reason;
                }
            }
            $report = new ScanReport($all, $issues);
            $incomplete = $issues !== [];
            if ($this->scanOnly) {
                $this->renderScan($report, $input, $output);

                return $incomplete ? 2 : 0;
            }

            $candidates = $report->outdated();
            $selected = $input->getOption('server');
            $knownKeys = array_unique(array_map(fn (Assessment $a) => $a->server->key(), $all));
            foreach ($selected as $key) {
                if (! in_array($key, $knownKeys, true)) {
                    throw new RuntimeException('Server was not found in the scan: '.$key);
                }
            }
            if ($selected !== []) {
                $candidates = array_values(array_filter($candidates, fn (Assessment $a) => in_array($a->server->key(), $selected, true)));
            } elseif (! $input->getOption('all') && ! $input->getOption('dry-run') && $interactive && $candidates !== []) {
                $this->renderScan($report, $input, $output);
                $options = [];
                foreach ($candidates as $candidate) {
                    $options[$candidate->server->key()] = $candidate->server->name.' ('.$candidate->server->key().')';
                }
                $selected = multiselect('Which servers should receive PHP patches?', $options, hint: 'Every confirmed outdated PHP branch on selected servers will be patched.');
                $candidates = array_values(array_filter($candidates, fn (Assessment $a) => in_array($a->server->key(), $selected, true)));
            }

            if ($input->getOption('dry-run')) {
                $this->renderScan(new ScanReport($candidates, $issues), $input, $output);

                return $incomplete ? 2 : 0;
            }
            if ($candidates === []) {
                $this->renderScan(new ScanReport([], $issues), $input, $output);

                return $incomplete ? 2 : 0;
            }
            if (! $input->getOption('yes')) {
                if (! confirm('Patch '.count($candidates).' PHP installations? PHP services may restart.', default: false)) {
                    $output->writeln('Cancelled.');

                    return 0;
                }
            }
            $results = (new RunUpgrades)->run($candidates, $providers,
                $input->getOption('json') ? null : function (Assessment $a) use ($output): void {
                    $output->writeln('Patching '.OutputFormatter::escape($a->server->name).' / PHP '.$a->branch.'…');
                });
            $this->renderResults($results, $issues, $input, $output);

            return array_filter($results, fn (UpgradeResult $r) => ! $r->succeeded()) !== [] ? 1 : ($incomplete ? 2 : 0);
        } catch (\Throwable $exception) {
            if ($input->getOption('json')) {
                $output->writeln(json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln('<error>'.OutputFormatter::escape($exception->getMessage()).'</error>');
            }

            return 1;
        }
    }

    /** @return array<string, Provider> */
    private function providers(InputInterface $input, bool $interactive): array
    {
        $registered = $this->registered + ['demo' => new DemoProvider];
        if ($file = $input->getOption('providers-file')) {
            if (! is_file($file)) {
                throw new RuntimeException('Provider configuration file was not found.');
            }
            $additional = require $file;
            if (! is_array($additional)) {
                throw new RuntimeException('Provider configuration must return an array of Provider instances.');
            }
            $registered = $additional + $registered;
        }
        $names = array_unique($input->getOption('provider') ?: ['forge']);
        $providers = [];
        foreach ($names as $name) {
            if (isset($registered[$name])) {
                $provider = $registered[$name];
            } elseif ($name === 'forge') {
                $timeout = filter_var($input->getOption('timeout'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);
                if ($timeout === false) {
                    throw new RuntimeException('--timeout must be between 1 and 3600 seconds.');
                }
                $minimums = [];
                foreach ($input->getOption('minimum') as $minimum) {
                    $parts = explode('=', $minimum, 2);
                    if (count($parts) !== 2) {
                        throw new RuntimeException('Use --minimum=branch=patch, such as --minimum=8.3=8.3.35.');
                    }
                    $minimums[$parts[0]] = $parts[1];
                }
                $token = getenv('FORGE_API_TOKEN') ?: ($interactive ? password('Forge API token', required: true) : '');
                if ($token === '') {
                    throw new RuntimeException('Set FORGE_API_TOKEN or run interactively to enter it.');
                }
                $provider = new ForgeProvider(new ForgeApi(new Client, $token),
                    new SshRuntimeInspector($input->getOption('ssh-user'), $input->getOption('identity')),
                    new PhpReleaseCatalog(new Client, $minimums), new SystemClock, $timeout);
            } else {
                throw new RuntimeException('Unknown provider: '.$name.'. Register it with --providers-file.');
            }
            if (! $provider instanceof Provider || $provider->name() !== $name) {
                throw new RuntimeException('Each provider must implement Provider and match its registered name.');
            }
            $providers[$name] = $provider;
        }

        return $providers;
    }

    private function renderScan(ScanReport $report, InputInterface $input, OutputInterface $output): void
    {
        if ($input->getOption('json')) {
            $output->writeln(json_encode(['assessments' => array_map(fn (Assessment $a) => $a->toArray(), $report->assessments), 'issues' => $report->issues], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return;
        }
        $rows = array_map(fn (Assessment $a) => array_map(OutputFormatter::escape(...), [
            $a->server->key(), $a->server->name, $a->branch,
            ($a->current?->cli ?? '?').' / '.($a->current?->fpm ?? '?'),
            $a->target ?? '?', $a->status, $a->reason,
        ]), $report->assessments);
        (new Table($output))->setHeaders(['Server', 'Name', 'PHP', 'CLI / FPM', 'Target', 'State', 'Notes'])->setRows($rows)->render();
        foreach ($report->issues as $issue) {
            $output->writeln('<comment>'.OutputFormatter::escape($issue).'</comment>');
        }
        if ($report->assessments === []) {
            $output->writeln('No confirmed outdated installations selected.');
        }
    }

    /** @param list<UpgradeResult> $results
     * @param  list<string>  $issues
     */
    private function renderResults(array $results, array $issues, InputInterface $input, OutputInterface $output): void
    {
        $rows = array_map(fn (UpgradeResult $r) => ['key' => $r->assessment->key(), 'status' => $r->status, 'message' => $r->message], $results);
        if ($input->getOption('json')) {
            $output->writeln(json_encode(['results' => $rows, 'issues' => $issues], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return;
        }
        (new Table($output))->setHeaders(['Installation', 'Result', 'Details'])
            ->setRows(array_map(fn ($row) => array_map(OutputFormatter::escape(...), array_values($row)), $rows))->render();
        foreach ($issues as $issue) {
            $output->writeln('<comment>'.OutputFormatter::escape($issue).'</comment>');
        }
    }
}
