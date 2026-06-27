<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainBriefHistogram;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCascadeRuleOutcomeAnalyzer;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainFrontierSourceRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHealthScore;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintEntropy;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintTransitionMatrix;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathCatalog;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainResultKindHistogram;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainTrendAnalyzer;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Illuminate\Console\Command;

/**
 * BRAIN HEALTH DOCTOR — first-aid checks for common stuck states. Read-only. Emits a list of
 * `{severity, code, advice}` findings. severity ∈ {critical, warn, info}. Empty findings list = healthy.
 *
 * The findings are derived from the same observable surface as brain:state, but framed as ACTIONABLE
 * diagnoses instead of raw counts. Useful when babysitting: 'critical: gate has 2 holes — originate a
 * fix' vs reading the state JSON and figuring it out yourself.
 *
 * Read-only by construction; no mutation, no origination, no comprehension build.
 */
final class AtlasBrainHealthDoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:health-doctor {--scope= : scope slug (default: configured default_scope)} {--all : also enumerate findings per configured scope} {--severity= : filter findings to one severity (critical|warn|info)} {--json} {--raw : single-line JSON (no pretty-print) for log scraping}';

    /** @var string */
    protected $description = 'First-aid checks over brain state — emits actionable findings (gate holes, dormant flags, dead memory, etc).';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scope = (string) app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt)['slug'];

        $findings = [];

        // CRITICAL: gate regression.
        $inspectorHoles = count(app(AtlasBrainGateAdversarialAuditor::class)->audit(new AtlasTaskPacketQualityInspector)['holes']);
        $seedHoles = count(app(AtlasBrainSeedGateAdversarialAuditor::class)->audit(app(AtlasBrainSeedQualityGate::class))['holes']);
        if ($inspectorHoles > 0 || $seedHoles > 0) {
            $findings[] = ['severity' => 'critical', 'code' => 'gate_regression', 'advice' => "inspector holes={$inspectorHoles} / seed_gate holes={$seedHoles} — originate a fix for the failing audit attack(s) BEFORE any other leap"];
        }

        // CRITICAL: master switch off (brain is fully inert).
        if (! AtlasBrainMasterSwitch::enabled()) {
            $findings[] = ['severity' => 'warn', 'code' => 'master_switch_off', 'advice' => 'ATLAS_BRAIN_MASTER_ENABLED=false — brain:next/seed are no-ops; flip the env to arm'];
        }

        // WARN: rich payload flag dormant.
        $digestEnabled = (bool) config('atlas.brain.scope_signal_digest_enabled', false);
        if (! $digestEnabled) {
            $findings[] = ['severity' => 'info', 'code' => 'scope_signal_digest_dormant', 'advice' => 'atlas.brain.scope_signal_digest_enabled=false — the L1-L37 payload is not surfacing; arm to give the brain its full perception layer'];
        }

        // WARN: reflection enabled but stream empty (means recording is wired but stream is fresh).
        $reflectionEnabled = (bool) config('atlas.brain.reflection_enabled', false);
        if ($reflectionEnabled) {
            $reflectionCount = count(app(AtlasBrainReflectionStream::class)->forScope($scope));
            if ($reflectionCount === 0) {
                $findings[] = ['severity' => 'info', 'code' => 'reflection_empty', 'advice' => "reflection_enabled=true but scope '{$scope}' has 0 stored reflections — run brain:next at least once to populate the time series"];
            }
        }

        // INFO: frontier source has no curated candidates for this scope (the frontier-harvest path has no fuel).
        if (app(AtlasBrainFrontierSourceRegistry::class)->count($scope) === 0) {
            $findings[] = ['severity' => 'info', 'code' => 'frontier_empty', 'advice' => "scope '{$scope}' has 0 curated frontier candidates — append entries via the registry to give the frontier-harvest path material"];
        }

        // WARN: portfolio path count diverges from the canonical 7. Indicates config drift / abridgment.
        $catalog = app(AtlasBrainPathCatalog::class);
        $allPaths = $catalog->all();
        $pathsCount = count($allPaths);
        if ($pathsCount !== 7) {
            $findings[] = ['severity' => 'warn', 'code' => 'portfolio_paths_unexpected_count', 'advice' => "atlas.brain.paths has {$pathsCount} entries (expected 7 — frontier-harvest, metrics-optimization, pattern-design, simulation-twin, comprehension-deepening, adversarial-critique, compounding); rotation will be incomplete"];
        }

        // WARN: any path with a missing/empty executor_organ — the router would recommend it but the catalog
        // can't resolve a concrete executor (leverage_brief.evidence would drop the cue).
        $missing = [];
        foreach ($allPaths as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($catalog->executorOrganFor($id) === null) {
                $missing[] = $id;
            }
        }
        if ($missing !== []) {
            $findings[] = ['severity' => 'warn', 'code' => 'portfolio_path_missing_executor', 'advice' => 'paths without executor_organ: '.implode(', ', $missing).' — leverage_brief can\'t cite the concrete organ for these paths'];
        }

        // WARN: any of the 7 canonical ids missing. Detects "7 entries but wrong ones" (different drift mode
        // than the count check).
        $canonical = ['frontier-harvest', 'metrics-optimization', 'pattern-design', 'simulation-twin', 'comprehension-deepening', 'adversarial-critique', 'compounding'];
        $present = array_filter(array_map(static fn (array $e): string => (string) ($e['id'] ?? ''), $allPaths));
        $missingCanonical = array_values(array_diff($canonical, $present));
        if ($missingCanonical !== []) {
            $findings[] = ['severity' => 'warn', 'code' => 'portfolio_canonical_paths_missing', 'advice' => 'missing canonical path ids: '.implode(', ', $missingCanonical).' — these paths are part of the portfolio rotation and the brain cannot recommend them'];
        }

        // WARN: served_ratio < 50% over ≥10 decisive cycles. The brain is losing more than winning over a
        // significant window (under 10 is too noisy to act on).
        $ledger = new AtlasBrainDoneSetLedger($scope, (string) config('atlas.brain.done_set_root'));
        $rows = $ledger->recentCycles(50);
        $served = 0;
        $refused = 0;
        foreach ($rows as $row) {
            $status = trim((string) ($row['status'] ?? ''));
            if ($status === 'served' || $status === 'seeded') {
                $served++;
            } elseif (in_array($status, ['refused', 'abstain', 'already_done', 'prepare_blocked', 'forbidden_target'], true)) {
                $refused++;
            }
        }
        $decisive = $served + $refused;
        if ($decisive >= 10) {
            $ratio = (int) round(($served * 100) / $decisive);
            if ($ratio < 50) {
                $findings[] = ['severity' => 'warn', 'code' => 'served_ratio_low', 'advice' => "served_ratio={$ratio}% over {$decisive} decisive cycles — brain is losing more than winning; consider switching scope or originating against the failing path"];
            }
        }

        // INFO: brief histogram skew. When ≥5 prior briefs land and a single action_hint dominates >70%, the
        // brain has collapsed onto a single rule of the 7-rule cascade — perspective-diversity nudge.
        if ($reflectionEnabled) {
            $priors = app(AtlasBrainReflectionStream::class)->forScope($scope);
            $hist = app(AtlasBrainBriefHistogram::class)->histogram($priors);
            if ($hist['total'] >= 5 && $hist['by_hint'] !== [] && $hist['by_hint'][0]['pct'] > 70) {
                $top = $hist['by_hint'][0];
                $findings[] = ['severity' => 'info', 'code' => 'brief_histogram_skewed', 'advice' => "action_hint '{$top['hint']}' dominates {$top['pct']}% of {$hist['total']} recent briefs — cascade has collapsed onto one rule; rotate path or originate against a fresh signal"];
            }
        }

        // INFO: cascade rule low-yield. For any action_hint with ≥5 joined cycles AND served_rate<30%,
        // emit a per-hint nudge ("rule X churns; reroute"). Joins reflection+done-set via L75 analyzer.
        if ($reflectionEnabled) {
            $stream = app(AtlasBrainReflectionStream::class);
            $report = app(AtlasBrainCascadeRuleOutcomeAnalyzer::class)->analyze($scope, $stream, $ledger);
            foreach ($report['by_hint'] as $row) {
                if ($row['total'] >= 5 && $row['served_rate_pct'] < 30) {
                    $findings[] = ['severity' => 'info', 'code' => 'cascade_rule_low_yield', 'advice' => "action_hint '{$row['hint']}' serves {$row['served_rate_pct']}% over {$row['total']} cycles — low-yield rule; the brief cascade should weight it down or reroute via a different path"];
                }
            }
        }

        // INFO: hint transition self-loop dominance. When ≥5 transitions and >50% are self-loops, the
        // cascade is repeating its OWN hint cycle-after-cycle — perseveration via DYNAMICS (orthogonal
        // to the histogram skew which is just distribution). Uses L78 matrix over last 50 reflections.
        if ($reflectionEnabled) {
            $stream = app(AtlasBrainReflectionStream::class);
            $tail = array_slice($stream->forScope($scope), -50);
            $matrix = app(AtlasBrainHintTransitionMatrix::class)->build($tail);
            if ($matrix['transitions'] >= 5) {
                $selfPct = (int) round(($matrix['self_loop_count'] * 100) / $matrix['transitions']);
                if ($selfPct > 50) {
                    $findings[] = ['severity' => 'info', 'code' => 'hint_self_loop_dominant', 'advice' => "{$selfPct}% of the last {$matrix['transitions']} hint transitions are self-loops — cascade is stuck on its own previous hint; rotate path or originate fresh"];
                }
            }
        }

        // INFO: result-kind starvation. When ≥10 reflections in tail and starvation_pct >70 (blocked +
        // exhausted + stagnated + clean_no_op share), the queue is dry-by-walls — origination keeps
        // failing or abstaining. Uses L83 histogram over last 50 reflections.
        if ($reflectionEnabled) {
            $stream = app(AtlasBrainReflectionStream::class);
            $tail = array_slice($stream->forScope($scope), -50);
            $kindHist = app(AtlasBrainResultKindHistogram::class)->histogram($tail);
            if ($kindHist['total'] >= 10 && $kindHist['starvation_pct'] > 70) {
                $findings[] = ['severity' => 'info', 'code' => 'result_kind_starvation', 'advice' => "{$kindHist['starvation_pct']}% of the last {$kindHist['total']} cycles are blocked/exhausted/stagnated/clean_no_op — queue is dry-by-walls; check sources, rotate scope, or originate against a fresh signal"];
            }
        }

        // INFO: starvation trend worsening — split-window delta says queue health is degrading. Distinct
        // from the snapshot starvation finding (which can fire when state is bad but stable). Uses L85
        // trend analyzer over the reflection stream.
        if ($reflectionEnabled) {
            $stream = app(AtlasBrainReflectionStream::class);
            $trend = app(AtlasBrainTrendAnalyzer::class)->starvation($stream->forScope($scope));
            if ($trend['direction'] === 'worsening') {
                $findings[] = ['severity' => 'info', 'code' => 'starvation_trend_worsening', 'advice' => "starvation_pct moved {$trend['older_starvation_pct']}% → {$trend['newer_starvation_pct']}% (Δ +{$trend['delta_pct']}) over the last {$trend['window']}+{$trend['window']} cycles — queue health degrading; act now (rotate path, harvest frontier, or originate fresh)"];
            }
        }

        // INFO: composite health score below the 50/100 floor (weighted: gates40+ratio20+starv20+entropy10+trend10).
        // One single number to act on; the rich findings tell you WHICH dimension caused it.
        if ($reflectionEnabled) {
            $stream = app(AtlasBrainReflectionStream::class);
            $tail = array_slice($stream->forScope($scope), -50);
            $brief = app(AtlasBrainBriefHistogram::class)->histogram($tail);
            $entropy = app(AtlasBrainHintEntropy::class)->compute($brief);
            $trend = app(AtlasBrainTrendAnalyzer::class)->starvation($stream->forScope($scope));
            $starvPct = app(AtlasBrainResultKindHistogram::class)->histogram($tail)['starvation_pct'];
            $airtight = ($inspectorHoles + $seedHoles) === 0;
            $ratioPct = ($served + $refused) > 0 ? (int) round(($served * 100) / ($served + $refused)) : 0;
            $score = app(AtlasBrainHealthScore::class)->compute($airtight, $ratioPct, $starvPct, (float) $entropy['normalized'], (string) $trend['direction'])['score'];
            if ($score < 50) {
                $findings[] = ['severity' => 'info', 'code' => 'health_score_low', 'advice' => "composite health_score={$score}/100 below the 50 floor — review the per-finding causes (gates/ratio/starvation/entropy/trend) and act on the heaviest deficit"];
            }
        }

        // Severity counts BEFORE filter — operator sees the global picture even when narrowing the list.
        $severityCounts = ['critical' => 0, 'warn' => 0, 'info' => 0];
        foreach ($findings as $f) {
            $severityCounts[(string) $f['severity']]++;
        }

        // Optional severity filter — narrows the surfaced findings without affecting the global counts.
        $severityFilter = trim((string) ($this->option('severity') ?? ''));
        $surfaced = $findings;
        if ($severityFilter !== '' && in_array($severityFilter, ['critical', 'warn', 'info'], true)) {
            $surfaced = array_values(array_filter($findings, static fn (array $f): bool => (string) $f['severity'] === $severityFilter));
        }

        // 'healthy' = no critical/warn (info findings are tolerated; they're suggestions, not problems).
        // When a severity filter is set, status/exit reflect ONLY findings of that severity (cron-friendly).
        $blocking = $severityFilter !== ''
            ? $surfaced
            : array_values(array_filter($findings, static fn (array $f): bool => in_array((string) $f['severity'], ['critical', 'warn'], true)));

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $payload = [
            'scope' => $scope,
            'findings' => $surfaced,
            'severity_counts' => $severityCounts,
            'severity_filter' => $severityFilter !== '' ? $severityFilter : null,
            'status' => $blocking === [] ? 'healthy' : 'has_findings',
        ];

        if ($this->option('all')) {
            // Per-scope cohort: enumerate every configured scope and report its per-scope findings (a thin
            // count summary — full findings would explode the payload). Useful for dashboards that watch
            // every cohort at once.
            $registry = app(AtlasBrainScopeRegistry::class);
            $cohorts = [];
            foreach (array_keys((array) config('atlas.brain.scopes', [])) as $slug) {
                $slug = (string) $slug;
                if ($slug === '' || $slug === $scope) {
                    continue;
                }
                $other = (string) $registry->resolve($slug)['slug'];
                $reflectionCount = (int) ($reflectionEnabled ? count(app(AtlasBrainReflectionStream::class)->forScope($other)) : 0);
                $cohorts[] = [
                    'slug' => $other,
                    'frontier_empty' => app(AtlasBrainFrontierSourceRegistry::class)->count($other) === 0,
                    'reflection_empty' => $reflectionEnabled && $reflectionCount === 0,
                ];
            }
            $payload['cohorts'] = $cohorts;
        }

        $this->line((string) json_encode($payload, $flags));

        // Exit code mirrors status — cron-friendly: `health-doctor || alert`.
        return $blocking === [] ? self::SUCCESS : self::FAILURE;
    }
}
