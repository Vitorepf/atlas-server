<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

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
    use EmitsCanonicalJson;

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
            $this->line($this->encode($summary));

            return self::SUCCESS;
        }

        $this->info('Provider governance coverage');
        $this->line('  ledger: '.$ledger->logPath());

        if ($summary['total'] === 0) {
            $this->line('  no provider executions recorded yet (honest 0-baseline).');

            return self::SUCCESS;
        }

        $this->line(sprintf('  total executions   : %d', $summary['total']));
        $this->line(sprintf('  governed (covered+consulted): %d (%.1f%%)', $summary['governed'], $summary['governed_rate'] * 100));
        $this->line(sprintf('    covered  (manager get)   : %d', $summary['covered']));
        $this->line(sprintf('    consulted (shared seam)  : %d', $summary['consulted']));
        $this->line(sprintf('  bypass  (blind muscle)     : %d (%.1f%%)', $summary['bypass'], $summary['bypass_rate'] * 100));
        $this->line(sprintf('  would_have_blocked         : %d (%.1f%% of consulted)', $summary['would_have_blocked_total'], $summary['would_have_blocked_rate'] * 100));
        $this->line(sprintf('  false_positives (codified) : %d', $summary['false_positive_total']));
        if ($summary['candidate_hard_units'] !== null) {
            $this->line(sprintf('  candidate hard units       : %.6f (%s)', $summary['candidate_hard_units'], $summary['candidate_derivation']['source'] ?? 'unknown'));
        }

        if ($summary['by_surface'] !== []) {
            $this->line('  by surface:');
            foreach ($summary['by_surface'] as $surface => $counts) {
                $this->line(sprintf('    - %-22s covered=%d consulted=%d bypass=%d', $surface, $counts['covered'], $counts['consulted'], $counts['bypass']));
            }
        }

        return self::SUCCESS;
    }
}
