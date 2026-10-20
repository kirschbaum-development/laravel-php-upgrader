<?php

declare(strict_types=1);

namespace KirschbaumDevelopment\LaravelPhpUpgrader\Data;

final readonly class ScanReport
{
    /** @param list<Assessment> $assessments
     * @param  list<string>  $issues
     */
    public function __construct(public array $assessments, public array $issues = []) {}

    /** @return list<Assessment> */
    public function outdated(): array
    {
        return array_values(array_filter($this->assessments, fn (Assessment $assessment) => $assessment->isUpgradable()));
    }
}
