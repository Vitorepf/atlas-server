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
    protected $signature = 'atlas:brain:metrics {--scope= : scope slug} {--format=textfile : textfile|json}';

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

        $scoreReport = app(AtlasBrainHealthScore::class)->compute(
            $holes === 0, $ratio, (int) $kind['starvation_pct'], (float) $entropy['normalized'], (string) $trend['direction']
        );
        $score = $scoreReport['score'];
        $br = $scoreReport['breakdown'];

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
            "atlas_brain_score_breakdown_gates{$label}" => (int) $br['gates'],
            "atlas_brain_score_breakdown_ratio{$label}" => (int) $br['ratio'],
            "atlas_brain_score_breakdown_starvation{$label}" => (int) $br['starvation'],
            "atlas_brain_score_breakdown_entropy{$label}" => (int) $br['entropy'],
            "atlas_brain_score_breakdown_trend{$label}" => (int) $br['trend'],
        ];

        if ((string) $this->option('format') === 'json') {
            $json = ['scope' => $scope, 'metrics' => []];
            foreach ($metrics as $key => $val) {
                $base = (string) strstr($key, '{', true) ?: $key;
                $json['metrics'][substr($base, strlen('atlas_brain_'))] = is_numeric($val) ? +$val : $val;
            }
            $this->line((string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        // HELP/TYPE comments — proper Prometheus textfile format. Static dict so the help text stays
        // identical across runs (scrapers cache type hints).
        $help = [
            'atlas_brain_score' => ['gauge', 'Composite health score 0..100'],
            'atlas_brain_gates_holes' => ['gauge', 'Adversarial gate hole count (inspector + seed)'],
            'atlas_brain_served_ratio_pct' => ['gauge', 'served/decisive ratio over last 50 done-set rows'],
            'atlas_brain_decisive_cycles' => ['gauge', 'served + refused over last 50 done-set rows'],
            'atlas_brain_starvation_pct' => ['gauge', 'Starvation outcome share over last 50 reflections'],
            'atlas_brain_entropy_normalized' => ['gauge', 'Normalized Shannon entropy over hint distribution [0..1]'],
            'atlas_brain_evidence_age_seconds' => ['gauge', 'Seconds since newest reflection (-1 if none)'],
            'atlas_brain_origination_gap_cycles' => ['gauge', 'Cycles since last served|seeded done-set row'],
            'atlas_brain_score_breakdown_gates' => ['gauge', 'Health score gates component (0|40)'],
            'atlas_brain_score_breakdown_ratio' => ['gauge', 'Health score served_ratio component (0..20)'],
            'atlas_brain_score_breakdown_starvation' => ['gauge', 'Health score starvation component (0..20)'],
            'atlas_brain_score_breakdown_entropy' => ['gauge', 'Health score entropy component (0..10)'],
            'atlas_brain_score_breakdown_trend' => ['gauge', 'Health score trend component (0|5|10)'],
        ];
        $emitted = [];
        foreach ($metrics as $key => $val) {
            $base = (string) strstr($key, '{', true) ?: $key;
            if (! isset($emitted[$base])) {
                $hint = $help[$base] ?? ['gauge', ''];
                $this->line("# HELP {$base} {$hint[1]}");
                $this->line("# TYPE {$base} {$hint[0]}");
                $emitted[$base] = true;
            }
            $this->line($key.' '.$val);
        }

        return self::SUCCESS;
    }
}
