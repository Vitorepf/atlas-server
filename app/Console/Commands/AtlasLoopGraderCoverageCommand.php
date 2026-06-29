<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3GraderRegistry;
use Illuminate\Console\Command;

/**
 * Read-only verification-grader coverage: enumerates {@see AtlasLoopV3GraderRegistry::claimClasses()} and
 * reports, per claim class, the installed grader FQCN and whether it is installed — plus installed/missing
 * counts. A missing grader for a verification claim becomes visible instead of silently absent. Facts only.
 */
final class AtlasLoopGraderCoverageCommand extends Command
{
    protected $signature = 'atlas:loop:grader-coverage {--json}';

    protected $description = 'Read-only V3 verification-grader coverage report (per claim class: installed grader or gap).';

    public function handle(AtlasLoopV3GraderRegistry $registry): int
    {
        $records = [];
        $installed = 0;
        foreach ($registry->claimClasses() as $claimClass) {
            $isInstalled = $registry->isInstalled($claimClass);
            $records[] = [
                'claim_class' => (string) $claimClass,
                'grader_fqcn' => $registry->fqcnFor($claimClass),
                'installed' => $isInstalled,
            ];
            if ($isInstalled) {
                $installed++;
            }
        }
        usort($records, static fn (array $a, array $b): int => strcmp($a['claim_class'], $b['claim_class']));

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.grader_coverage.v1',
            'status' => 'ok',
            'claim_class_count' => count($records),
            'installed_count' => $installed,
            'missing_count' => count($records) - $installed,
            'coverage' => $records,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
