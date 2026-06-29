<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Sentinels\AtlasLoopReplenisherDocGapOracleCoverageSentinel;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopReplenisherDocGapOracleCoverageSentinel::check()} at the operator surface:
 * re-proves the replenisher's test-coverage oracle still covers BOTH the app/ and the mirrored tests/ half by
 * driving two fabricated probe packets through the real inspector and emitting the conformance verdict.
 *
 * Read-only + pure: it probes the inspector and reports — no minting, no queue, no mutation.
 */
final class AtlasLoopReplenisherDocGapSentinelCommand extends Command
{
    protected $signature = 'atlas:loop:replenisher-doc-gap-sentinel {--json}';

    protected $description = 'Read-only regression sentinel: the replenisher doc-gap oracle still covers app/ + tests/ halves.';

    public function handle(): int
    {
        $verdict = app(AtlasLoopReplenisherDocGapOracleCoverageSentinel::class)->check();

        if ($this->option('json')) {
            $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('conformant: '.($verdict['conformant'] ? 'yes' : 'no'));
            $this->line('both_halves doc_gap_unresolved: '.($verdict['both_halves']['doc_gap_unresolved'] ? 'yes' : 'no'));
            $this->line('app_only doc_gap_unresolved: '.($verdict['app_only']['doc_gap_unresolved'] ? 'yes' : 'no'));
        }

        return self::SUCCESS;
    }
}
