<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHealthScoreLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use Illuminate\Console\Command;

/**
 * BRAIN TREND — reads the L99 health-score ledger tail and emits a compact summary: first/last score,
 * delta, min, max, count. Lets the operator see "score moved 75 → 60 over last 20 snapshots" without
 * loading the full NDJSON into a notebook.
 *
 * Read-only; complements brain:snapshot (writer). Pétreo (brain surface).
 */
final class AtlasBrainTrendCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:trend {--scope= : scope slug} {--tail=30 : recent snapshots to summarize} {--json} {--raw}';

    /** @var string */
    protected $description = 'Summarize the brain health-score ledger tail: first/last/min/max/delta.';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scope = (string) app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt)['slug'];
        $tail = max(1, (int) ($this->option('tail') ?? 30));

        $rows = app(AtlasBrainHealthScoreLedger::class)->tail($scope, $tail);
        $scores = array_map(static fn (array $r): int => (int) ($r['score'] ?? 0), $rows);

        $summary = [
            'scope' => $scope,
            'count' => count($scores),
            'first' => $scores === [] ? null : $scores[0],
            'last' => $scores === [] ? null : end($scores),
            'min' => $scores === [] ? null : min($scores),
            'max' => $scores === [] ? null : max($scores),
            'delta' => count($scores) >= 2 ? (end($scores) - $scores[0]) : 0,
        ];

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($summary, $flags));

        return self::SUCCESS;
    }
}
