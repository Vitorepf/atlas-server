<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseCoverageReporter;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseDatasetRegistry;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopHardCaseCoverageReporter::report()} at the operator surface: reports, per
 * canonical loop capability, which hard-case bench cases cover it (and which capabilities have ZERO cases) as
 * deterministic facts. Read-only.
 *
 * The canonical capability list is the set the reporter's default case→capability mapper recognizes; the
 * last-run outcome provider returns null (no run-outcome store wired yet — outcomes show as unknown).
 */
final class AtlasLoopHardCaseCoverageCommand extends Command
{
    /** The canonical capabilities the default case mapper classifies bench cases into. */
    private const CAPABILITIES = ['certify', 'decompose', 'merge', 'originate', 'regression-net'];

    protected $signature = 'atlas:loop:hard-case-coverage {--json}';

    protected $description = 'Read-only hard-case bench coverage per capability (covered cases vs. zero-case gaps).';

    public function handle(AtlasLoopHardCaseDatasetRegistry $registry): int
    {
        $report = (new AtlasLoopHardCaseCoverageReporter(
            $registry,
            static fn (): array => self::CAPABILITIES,
            static fn (string $caseId): ?string => null,
        ))->report();

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.hard_case_coverage.v1',
            'capabilities' => $report['capabilities'],
            'per_capability' => $report['per_capability'],
            'capabilities_with_zero_cases' => $report['capabilities_with_zero_cases'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
