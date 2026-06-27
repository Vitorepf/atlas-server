<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainBriefHistogram;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHealthScore;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHealthScoreLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintEntropy;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainResultKindHistogram;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainTrendAnalyzer;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Illuminate\Console\Command;

/**
 * BRAIN SNAPSHOT — computes the L90 composite health score and APPENDS one row to the L99 ledger.
 * The ONLY perception-suite command that mutates; designed to be cron'd ("snapshot every 15min") so a
 * time-series accumulates. Pétreo (brain surface, but ledger is append-only by construction).
 */
final class AtlasBrainSnapshotCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:snapshot {--scope= : scope slug} {--json}';

    /** @var string */
    protected $description = 'Compute current health_score and append one row to the brain health-score ledger (cron-friendly).';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scope = (string) app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt)['slug'];

        $stream = app(AtlasBrainReflectionStream::class);
        $tail = array_slice($stream->forScope($scope), -50);
        $brief = app(AtlasBrainBriefHistogram::class)->histogram($tail);
        $entropy = app(AtlasBrainHintEntropy::class)->compute($brief);
        $kind = app(AtlasBrainResultKindHistogram::class)->histogram($tail);
        $trend = app(AtlasBrainTrendAnalyzer::class)->starvation($stream->forScope($scope));

        $ledger = new AtlasBrainDoneSetLedger($scope, (string) config('atlas.brain.done_set_root'));
        $served = 0;
        $refused = 0;
        foreach ($ledger->recentCycles(50) as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === 'served' || $status === 'seeded') {
                $served++;
            } elseif (in_array($status, ['refused', 'abstain', 'already_done', 'prepare_blocked', 'forbidden_target'], true)) {
                $refused++;
            }
        }
        $decisive = $served + $refused;
        $ratio = $decisive > 0 ? (int) round(($served * 100) / $decisive) : 0;

        $holes = count(app(AtlasBrainGateAdversarialAuditor::class)->audit(new AtlasTaskPacketQualityInspector)['holes'])
            + count(app(AtlasBrainSeedGateAdversarialAuditor::class)->audit(app(AtlasBrainSeedQualityGate::class))['holes']);

        $score = app(AtlasBrainHealthScore::class)->compute(
            $holes === 0, $ratio, (int) $kind['starvation_pct'], (float) $entropy['normalized'], (string) $trend['direction']
        )['score'];

        $row = app(AtlasBrainHealthScoreLedger::class)->append($scope, $score);

        $this->line((string) json_encode([
            'scope' => $scope, 'score' => $score, 'appended' => $row !== null,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
