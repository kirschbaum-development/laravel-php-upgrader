<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Actions;

use Closure;
use KirschbaumDevelopment\LaravelPhpUpgrader\Contracts\Provider;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\Assessment;
use KirschbaumDevelopment\LaravelPhpUpgrader\Data\UpgradeResult;

final class RunUpgrades
{
    /** @param list<Assessment> $assessments
     * @param  array<string, Provider>  $providers
     * @param  null|Closure(Assessment): void  $beforeEach
     * @return list<UpgradeResult>
     */
    public function run(array $assessments, array $providers, ?Closure $beforeEach = null): array
    {
        $results = [];
        $stopped = false;
        // Keep every runtime on the same server together; never run patches concurrently.
        usort($assessments, fn (Assessment $a, Assessment $b) => [$a->server->key(), $a->branch] <=> [$b->server->key(), $b->branch]);
        foreach ($assessments as $assessment) {
            if ($stopped) {
                $results[] = new UpgradeResult($assessment, 'not-run', 'Stopped after an unsuccessful or unverified update.');

                continue;
            }
            $beforeEach?->__invoke($assessment);
            try {
                $provider = $providers[$assessment->server->provider] ?? throw new \RuntimeException('Provider is not registered.');
                $result = $provider->patchAndVerify($assessment);
            } catch (\Throwable) {
                $result = new UpgradeResult($assessment, 'unverified', 'Provider stopped unexpectedly. Check its dashboard before retrying.');
            }
            $results[] = $result;
            $stopped = ! $result->succeeded();
        }

        return $results;
    }
}
