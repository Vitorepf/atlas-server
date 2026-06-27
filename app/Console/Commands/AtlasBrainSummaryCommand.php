<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainBriefHistogram;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintEntropy;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainResultKindHistogram;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainTrendAnalyzer;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * BRAIN SUMMARY — single-line plain-text rollup for terminal eyeballing / status-line widgets / Slack
 * paste. Cheap wrapper over `atlas:brain:audit` (which composes state + doctor + adversarial). No new
 * computation; just compresses the existing observable surface into ONE line.
 *
 * Output (single line, no JSON):
 *   brain[scope=<slug>, master=ON|OFF, gates=AIRTIGHT|HOLES:N, ratio=<pct>%/<decisive>, findings=<c>c/<w>w/<i>i]
 *
 * Exit code mirrors the audit: 0 when gates are airtight, 1 on regression. Pétreo (brain surface).
 */
final class AtlasBrainSummaryCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:summary {--scope= : scope slug (default: configured default_scope)}';

    /** @var string */
    protected $description = 'Single-line plain-text brain summary (gates, ratio, finding counts) — terminal/status-line friendly.';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scope = (string) app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt)['slug'];

        // Direct service calls (cheaper than Artisan::call + no shared-buffer hazard with the parent test).
        $master = AtlasBrainMasterSwitch::enabled() ? 'ON' : 'OFF';

        $inspectorHoles = count(app(AtlasBrainGateAdversarialAuditor::class)->audit(new AtlasTaskPacketQualityInspector)['holes']);
        $seedHoles = count(app(AtlasBrainSeedGateAdversarialAuditor::class)->audit(app(AtlasBrainSeedQualityGate::class))['holes']);
        $totalHoles = $inspectorHoles + $seedHoles;
        $gates = $totalHoles === 0 ? 'AIRTIGHT' : "HOLES:{$totalHoles}";

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

        // Doctor counts via Artisan with BufferedOutput, scoped so kernel->output is restored implicitly
        // when this method returns and the test reads $this->output instead.
        $buf = new BufferedOutput;
        $scopeArgs = $scopeOpt !== '' ? ['--scope' => $scopeOpt] : [];
        Artisan::call('atlas:brain:health-doctor', $scopeArgs + ['--json' => true, '--raw' => true], $buf);
        $doctor = json_decode(trim($buf->fetch()), true) ?: [];
        $counts = (array) ($doctor['severity_counts'] ?? ['critical' => 0, 'warn' => 0, 'info' => 0]);
        $c = (int) ($counts['critical'] ?? 0);
        $w = (int) ($counts['warn'] ?? 0);
        $i = (int) ($counts['info'] ?? 0);

        // Perception slice — read-only over the reflection stream.
        $stream = app(AtlasBrainReflectionStream::class);
        $tail = array_slice($stream->forScope($scope), -50);
        $kind = app(AtlasBrainResultKindHistogram::class)->histogram($tail);
        $brief = app(AtlasBrainBriefHistogram::class)->histogram($tail);
        $entropy = app(AtlasBrainHintEntropy::class)->compute($brief);
        $trend = app(AtlasBrainTrendAnalyzer::class)->starvation($stream->forScope($scope));
        $starv = (int) $kind['starvation_pct'];
        $entropyNorm = number_format((float) $entropy['normalized'], 2);
        $direction = (string) $trend['direction'];

        $this->line("brain[scope={$scope}, master={$master}, gates={$gates}, ratio={$ratio}%/{$decisive}, starv={$starv}%, entropy={$entropyNorm}, trend={$direction}, findings={$c}c/{$w}w/{$i}i]");

        return $totalHoles === 0 ? self::SUCCESS : self::FAILURE;
    }
}
