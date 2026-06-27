<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainBriefHistogram;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCascadeRuleOutcomeAnalyzer;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCohortScopeComparator;
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
 * READ-ONLY brain state inspector. Emits a small snapshot the operator (or a future cron/dashboard) can
 * pull without triggering origination — the heavy comprehension-model build only happens inside
 * `atlas:brain:next`. Cheap, idempotent, fail-open: errors degrade to zero counts rather than crash.
 *
 * Output shape:
 *   {brain_enabled, default_scope, scope: {slug, meta_harness},
 *    done_set: {recent_count, recent_served, recent_refused},
 *    reflection: {recent_count}}
 *
 * Purpose: with L1-L22 the brain accumulates a lot of state (done-set, reflection time series, gate
 * audit history). Today the only window into it is running brain:next, which mutates state. This
 * command gives a non-mutating health snapshot — useful when babysitting, scripting a dashboard, or
 * just confirming the master switch state without burning a comprehension build.
 */
final class AtlasBrainStateCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:state {--scope= : scope slug (default: configured default_scope)} {--tail=50 : how many done-set rows to summarize} {--all : also emit a per-scope cohort summary for every configured scope} {--json} {--raw : single-line JSON (no pretty-print) for log scraping}';

    /** @var string */
    protected $description = 'READ-ONLY brain state snapshot: master switch, scope, done-set tail, reflection tail (no origination).';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scopeDef = app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt);
        $scope = (string) $scopeDef['slug'];

        $tail = max(1, (int) ($this->option('tail') ?? 50));
        $ledger = new AtlasBrainDoneSetLedger($scope, (string) config('atlas.brain.done_set_root'));
        $recent = $ledger->recentCycles($tail);
        $served = 0;
        $refused = 0;
        foreach ($recent as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === 'served' || $status === 'seeded') {
                $served++;
            } elseif (in_array($status, ['refused', 'abstain', 'already_done', 'prepare_blocked', 'forbidden_target'], true)) {
                $refused++;
            }
        }

        $reflection = app(AtlasBrainReflectionStream::class);
        $reflectionCount = count($reflection->forScope($scope));
        // Parse the newest leverage_brief reflection (L14 time series) into a {hint, rationale} snapshot —
        // shows the last recommendation without running brain:next.
        $lastBrief = null;
        $newest = $reflection->recallTexts($scope, ['signals' => ['action_hint' => '']], 1);
        if ($newest !== []) {
            $text = (string) ($newest[0]['reflection'] ?? '');
            if (str_starts_with($text, 'leverage_brief: ')) {
                $rest = substr($text, strlen('leverage_brief: '));
                $cut = strpos($rest, ' — ');
                $lastBrief = [
                    'hint' => trim($cut === false ? $rest : substr($rest, 0, $cut)),
                    'rationale' => $cut === false ? '' : trim(substr($rest, $cut + strlen(' — '))),
                ];
            }
        }

        $payload = [
            'brain_enabled' => AtlasBrainMasterSwitch::enabled(),
            'scope_signal_digest_enabled' => (bool) config('atlas.brain.scope_signal_digest_enabled', false),
            'reflection_enabled' => (bool) config('atlas.brain.reflection_enabled', false),
            'causal_selector_enabled' => (bool) config('atlas.brain.causal_selector_enabled', false),
            'default_scope' => (string) config('atlas.brain.default_scope', 'loop'),
            'scope' => [
                'slug' => $scope,
                'meta_harness' => (bool) $scopeDef['meta_harness'],
            ],
            'done_set' => [
                'recent_count' => count($recent),
                'recent_served' => $served,
                'recent_refused' => $refused,
                'recent_ratio_pct' => ($served + $refused) > 0
                    ? (int) round(($served * 100) / ($served + $refused))
                    : 0,
            ],
            'reflection' => [
                'recent_count' => $reflectionCount,
            ],
            'last_brief' => $lastBrief,
            'brief_histogram' => app(AtlasBrainBriefHistogram::class)->histogram(
                $reflection->recallTexts($scope, ['signals' => ['action_hint' => '']], 20),
            ),
            // HINT ENTROPY — Shannon-bits scalar over the histogram. Single number; 0 = perseveration,
            // log2(alphabet) = perfectly diverse. normalized = bits / log2(alphabet), so 1.0 = uniform.
            'hint_entropy' => app(AtlasBrainHintEntropy::class)->compute(
                app(AtlasBrainBriefHistogram::class)->histogram(
                    $reflection->recallTexts($scope, ['signals' => ['action_hint' => '']], 20),
                )
            ),
            // CASCADE OUTCOMES — per-action_hint served/refused/served_rate_pct joined from reflection +
            // done-set. Top 5 by served_rate so the dashboard line stays small but the operator can see
            // which cascade rules pay off vs which churn. (L75 analyzer; pétreo organ.)
            // HINT TRANSITIONS — markov top-3 over the last 50 reflections in WRITE order (chronological).
            // Surfaces cascade dynamics (coupling/self-loops) — orthogonal to histogram + outcomes.
            // RESULT-KIND HISTOGRAM — distribution of cycle outcomes (blocked/exhausted/stagnated/note/...).
            // Different axis from hint distribution: this is OUTCOMES, not RECOMMENDATIONS.
            'result_kind_histogram' => (function () use ($scope, $reflection): array {
                $h = app(AtlasBrainResultKindHistogram::class)->histogram(
                    array_slice($reflection->forScope($scope), -50)
                );

                return [
                    'total' => $h['total'],
                    'starvation_pct' => $h['starvation_pct'],
                    'by_kind' => $h['by_kind'],
                ];
            })(),
            // STARVATION TREND — split-window delta (older 25 vs newer 25). direction ∈
            // {worsening, recovering, flat, insufficient_data}.
            'starvation_trend' => app(AtlasBrainTrendAnalyzer::class)->starvation($reflection->forScope($scope)),
            'hint_transitions' => (function () use ($scope, $reflection): array {
                $tail = array_slice($reflection->forScope($scope), -50);
                $m = app(AtlasBrainHintTransitionMatrix::class)->build($tail);

                return [
                    'transitions' => $m['transitions'],
                    'self_loops' => $m['self_loop_count'],
                    'top_pairs' => array_slice($m['by_pair'], 0, 3),
                ];
            })(),
            'cascade_outcomes' => (function () use ($scope, $reflection, $ledger): array {
                $report = app(AtlasBrainCascadeRuleOutcomeAnalyzer::class)->analyze($scope, $reflection, $ledger);

                return [
                    'joined_cycles' => $report['joined_cycles'],
                    'by_hint' => array_slice($report['by_hint'], 0, 5),
                ];
            })(),
            'frontier' => [
                'count' => app(AtlasBrainFrontierSourceRegistry::class)->count($scope),
            ],
            'paths' => [
                'count' => count(app(AtlasBrainPathCatalog::class)->all()),
                'ids' => array_values(array_filter(array_map(static fn (array $e): string => (string) ($e['id'] ?? ''), app(AtlasBrainPathCatalog::class)->all()))),
            ],
            // GATE HEALTH — runtime adversarial audit hole counts + attacks_tried (coverage). zero holes
            // against N attacks = airtight; same N is a stability contract (dropping it = audit shrunk silently).
            'gate_health' => (function (): array {
                $inspector = app(AtlasBrainGateAdversarialAuditor::class)->audit(new AtlasTaskPacketQualityInspector);
                $seed = app(AtlasBrainSeedGateAdversarialAuditor::class)->audit(app(AtlasBrainSeedQualityGate::class));

                return [
                    'inspector_holes' => count($inspector['holes']),
                    'inspector_attacks_tried' => (int) ($inspector['attacks_tried'] ?? 0),
                    'seed_gate_holes' => count($seed['holes']),
                    'seed_gate_attacks_tried' => (int) ($seed['attacks_tried'] ?? 0),
                ];
            })(),
        ];

        // HEALTH SCORE — 0..100 composite over the perception suite. Computed AFTER the payload is built
        // so the weights derive from the same values surfaced upstream (no double-compute drift).
        $payload['health_score'] = app(AtlasBrainHealthScore::class)->compute(
            ($payload['gate_health']['inspector_holes'] + $payload['gate_health']['seed_gate_holes']) === 0,
            (int) $payload['done_set']['recent_ratio_pct'],
            (int) $payload['result_kind_histogram']['starvation_pct'],
            (float) $payload['hint_entropy']['normalized'],
            (string) $payload['starvation_trend']['direction'],
        );

        if ($this->option('all')) {
            $cohorts = [];
            $registry = app(AtlasBrainScopeRegistry::class);
            foreach (array_keys((array) config('atlas.brain.scopes', [])) as $slug) {
                $slug = (string) $slug;
                if ($slug === '') {
                    continue;
                }
                $def = $registry->resolve($slug);
                $cohortScope = (string) $def['slug'];
                $cohortLedger = new AtlasBrainDoneSetLedger($cohortScope, (string) config('atlas.brain.done_set_root'));
                $cohorts[] = [
                    'slug' => $cohortScope,
                    'meta_harness' => (bool) $def['meta_harness'],
                    'done_set_recent' => count($cohortLedger->recentCycles($tail)),
                    'reflection_total' => count($reflection->forScope($cohortScope)),
                    'frontier_count' => app(AtlasBrainFrontierSourceRegistry::class)->count($cohortScope),
                ];
            }
            $payload['cohorts'] = $cohorts;
            // COHORT HEALTH RANKING — comparator (L87) ranks every scope by composite health so the
            // operator sees the winner at a glance instead of eyeballing the per-cohort rows.
            $payload['cohort_health_ranking'] = app(AtlasBrainCohortScopeComparator::class)->compare(
                array_map(static fn (array $c): string => (string) $c['slug'], $cohorts),
                $reflection,
                (string) config('atlas.brain.done_set_root'),
            );
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        $totalHoles = (int) $payload['gate_health']['inspector_holes'] + (int) $payload['gate_health']['seed_gate_holes'];

        return $totalHoles === 0 ? self::SUCCESS : self::FAILURE;
    }
}
