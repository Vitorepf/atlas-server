<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainBriefHistogram;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainEvidenceFreshness;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHealthScore;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintEntropy;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainOriginationGapDetector;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainResultKindHistogram;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainTrendAnalyzer;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Illuminate\Console\Command;

/**
 * BRAIN METRICS — flat key=value export of the perception suite scalars. Cron/prometheus/textfile
 * exporter friendly. Each line is `atlas_brain_<metric>{scope="..."} <value>` (Prometheus textfile
 * format). Pétreo.
 */
final class AtlasBrainMetricsCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:metrics {--scope= : scope slug}';

    /** @var string */
    protected $description = 'Flat key=value metrics export (Prometheus textfile format).';

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
        $fresh = app(AtlasBrainEvidenceFreshness::class)->inspect($stream->forScope($scope));

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
        $gap = app(AtlasBrainOriginationGapDetector::class)->inspect($ledger)['gap'];

        $holes = count(app(AtlasBrainGateAdversarialAuditor::class)->audit(new AtlasTaskPacketQualityInspector)['holes'])
            + count(app(AtlasBrainSeedGateAdversarialAuditor::class)->audit(app(AtlasBrainSeedQualityGate::class))['holes']);

        $score = app(AtlasBrainHealthScore::class)->compute(
            $holes === 0, $ratio, (int) $kind['starvation_pct'], (float) $entropy['normalized'], (string) $trend['direction']
        )['score'];

        $label = "{scope=\"{$scope}\"}";
        $age = $fresh['has_evidence'] ? (int) $fresh['age_seconds'] : -1;
        $metrics = [
            "atlas_brain_score{$label}" => $score,
            "atlas_brain_gates_holes{$label}" => $holes,
            "atlas_brain_served_ratio_pct{$label}" => $ratio,
            "atlas_brain_decisive_cycles{$label}" => $decisive,
            "atlas_brain_starvation_pct{$label}" => (int) $kind['starvation_pct'],
            "atlas_brain_entropy_normalized{$label}" => number_format((float) $entropy['normalized'], 4),
            "atlas_brain_evidence_age_seconds{$label}" => $age,
            "atlas_brain_origination_gap_cycles{$label}" => $gap,
        ];

        foreach ($metrics as $key => $val) {
            $this->line($key.' '.$val);
        }

        return self::SUCCESS;
    }
}
