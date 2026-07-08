<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use Illuminate\Console\Command;

/**
 * atlas:provider:coverage — report the REAL provider-governance bypass rate.
 *
 * The four governance decorators (ADML routing, cost-guard, compression,
 * response-cache) only apply to executions resolved through AiProviderManager.
 * The muscle paths (Forge/loop CLI spawns, the Dev claude gateway) bypass it.
 * This command reads the coverage ledger and prints how much execution ran
 * COVERED vs BYPASS — an honest 0-baseline when nothing has run yet.
 */
final class AtlasProviderCoverageCommand extends Command
{
    protected $signature = 'atlas:provider:coverage
        {--json : Emit the raw summary as JSON}
        {--reset : Clear the ledger to start a fresh measurement window}';

    protected $description = 'Report the real provider-governance bypass rate (covered vs bypass executions).';

    public function handle(ProviderGovernanceCoverageLedger $ledger): int
    {
        if ($this->option('reset')) {
            $ledger->reset();
            $this->info('Provider coverage ledger reset.');

            return self::SUCCESS;
        }

        $summary = $ledger->summary();

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Provider governance coverage');
        $this->line('  ledger: '.$ledger->logPath());

        if ($summary['total'] === 0) {
            $this->line('  no provider executions recorded yet (honest 0-baseline).');

            return self::SUCCESS;
        }

        $this->line(sprintf('  total executions : %d', $summary['total']));
        $this->line(sprintf('  covered (manager): %d (%.1f%%)', $summary['covered'], $summary['covered_rate'] * 100));
        $this->line(sprintf('  bypass  (muscle) : %d (%.1f%%)', $summary['bypass'], $summary['bypass_rate'] * 100));

        if ($summary['by_surface'] !== []) {
            $this->line('  by surface:');
            foreach ($summary['by_surface'] as $surface => $counts) {
                $this->line(sprintf('    - %-22s covered=%d bypass=%d', $surface, $counts['covered'], $counts['bypass']));
            }
        }

        return self::SUCCESS;
    }
}
