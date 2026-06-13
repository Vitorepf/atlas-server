<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopUtilityGradeService;
use Illuminate\Console\Command;

/**
 * The honest Utility/Impact grade of the Evolution Loop, re-resolved from the live
 * caller graph (never the loop's self-report). Run `--json` for the machine form the
 * morning digest publishes.
 */
class AtlasLoopUtilityGradeCommand extends Command
{
    protected $signature = 'atlas:loop:utility-grade
        {--window= : How many recent merged-to-main proposals to grade (default config)}
        {--json : Emit the canonical JSON verdict}';

    protected $description = 'Honest Utility/Impact grade (0-10) of the loop, re-resolving the caller graph fresh from committed merges.';

    public function handle(AtlasLoopUtilityGradeService $service): int
    {
        $window = $this->option('window') !== null ? (int) $this->option('window') : null;
        $result = $service->grade($window);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $axes = $result['axes'] ?? [];
        $this->info(sprintf('Atlas Loop — Utility/Impact: %.2f / 10  (%d merges graded)', $result['grade'] ?? 0.0, $result['graded_merges'] ?? 0));
        $this->line(sprintf(
            '  WIRED %.2f | REAL_TARGET %.2f | NON_TRIVIAL %.2f | COMPOUNDING %.2f | SAFETY %.2f',
            $axes['wired'] ?? 0,
            $axes['real_target'] ?? 0,
            $axes['non_trivial'] ?? 0,
            $axes['compounding'] ?? 0,
            $axes['safety'] ?? 0,
        ));
        $this->line('  '.($result['method'] ?? ''));

        return self::SUCCESS;
    }
}
