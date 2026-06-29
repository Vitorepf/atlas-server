<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainBriefHistogram;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCascadeRuleOutcomeAnalyzer;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCohortScopeComparator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainEvidenceFreshness;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainFrontierSourceRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHealthScore;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHeartbeatLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintEntropy;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintTransitionMatrix;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainNextPathSuggester;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainOriginationGapDetector;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathCatalog;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathStarvationDetector;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainProvenanceAttributionAnalyzer;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainProvenanceLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainResultKindHistogram;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeCatalogSnapshot;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainStaleScopeDetector;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainTopChurnHintDetector;
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
    protected $signature = 'atlas:brain:state
        {--scope= : scope slug (default: configured default_scope)}
        {--tail=50 : how many done-set rows to summarize}
        {--target-seeds=0 : optional external-brain valid-seed quota for read-only progress audit}
        {--baseline-seeded=0 : seeded count at the start of the external-brain quota run}
        {--actor= : optional external-brain actor/client id for actor-scoped quota}
        {--watch-actors= : comma-separated actor:target:baseline entries for multi external-brain supervision}
        {--stale-after=900 : seconds without an actor seed before quota supervisor marks it stale}
        {--all : also emit a per-scope cohort summary for every configured scope}
        {--json}
        {--raw : single-line JSON (no pretty-print) for log scraping}';

    /** @var string */
    protected $description = 'READ-ONLY brain state snapshot: master switch, scope, done-set tail, reflection tail (no origination).';

    public function handle(): int
    {
        $scopeOpt = trim((string) ($this->option('scope') ?? ''));
        $scopeDef = app(AtlasBrainScopeRegistry::class)->resolve($scopeOpt);
        $scope = (string) $scopeDef['slug'];
        $actor = trim((string) ($this->option('actor') ?? ''));

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
            // ORIGINATION GAP — L135 cycle-distance since last served. Cron-paused-aware companion
            // to evidence_freshness (wall-clock).
            'origination_gap' => app(AtlasBrainOriginationGapDetector::class)->inspect($ledger),
            // EVIDENCE FRESHNESS — age of newest reflection (L94). Detects "brain silenced" state where
            // gates+ratio are fine but origination has stopped writing entirely.
            'evidence_freshness' => app(AtlasBrainEvidenceFreshness::class)->inspect($reflection->forScope($scope)),
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
                $top = array_slice($report['by_hint'], 0, 5);

                return [
                    'joined_cycles' => $report['joined_cycles'],
                    'by_hint' => $top,
                    // L121: each row also attributed to its portfolio path so operator sees per-PATH win-rate.
                    'by_path' => app(AtlasBrainHintToPathTranslator::class)->attribute($top),
                    // PATH ROLLUP — aggregate served/refused across hints sharing the same path so
                    // operator sees per-PATH win-rate (paths can have multiple hints, e.g. comprehension-
                    // deepening covers both rotate_path AND originate_fresh).
                    'top_churn' => app(AtlasBrainTopChurnHintDetector::class)->detect($report),
                    'path_rollup' => (function () use ($report): array {
                        $tr = app(AtlasBrainHintToPathTranslator::class);
                        $agg = [];
                        foreach ($report['by_hint'] as $row) {
                            $path = $tr->pathFor((string) ($row['hint'] ?? ''));
                            if ($path === null) {
                                continue;
                            }
                            $agg[$path] ??= ['path' => $path, 'served' => 0, 'refused' => 0, 'total' => 0];
                            $agg[$path]['served'] += (int) ($row['served'] ?? 0);
                            $agg[$path]['refused'] += (int) ($row['refused'] ?? 0);
                            $agg[$path]['total'] += (int) ($row['total'] ?? 0);
                        }
                        foreach ($agg as &$r) {
                            $r['served_rate_pct'] = $r['total'] > 0 ? (int) round(($r['served'] * 100) / $r['total']) : 0;
                        }
                        unset($r);
                        $rows = array_values($agg);
                        usort($rows, static fn (array $a, array $b): int => [$b['served_rate_pct'], $b['total']] <=> [$a['served_rate_pct'], $a['total']]);

                        return $rows;
                    })(),
                ];
            })(),
            // PATH STARVATION — L125 detector over brief histogram. {hit, starved} per canonical path.
            'path_starvation' => app(AtlasBrainPathStarvationDetector::class)->detect(
                app(AtlasBrainBriefHistogram::class)->histogram(array_slice($reflection->forScope($scope), -50)),
                app(AtlasBrainHintToPathTranslator::class)
            ),
            'frontier' => (function () use ($scope): array {
                $reg = app(AtlasBrainFrontierSourceRegistry::class);
                $top = $reg->topK($scope, 3);

                return [
                    'count' => $reg->count($scope),
                    'top_titles' => array_values(array_map(static fn (array $c): string => (string) ($c['title'] ?? ''), $top)),
                ];
            })(),
            // SCOPE CATALOG — L119 enumeration of configured scopes + default. Operator sees the cohort
            // shape without grepping config/atlas.php.
            'scope_catalog' => app(AtlasBrainScopeCatalogSnapshot::class)->snapshot(),
            // PROVENANCE — L112 ledger size + L139 top-3 source_finding attribution.
            'provenance' => (function () use ($scope): array {
                $rows = app(AtlasBrainProvenanceLedger::class)->tail($scope, PHP_INT_MAX);
                $attr = app(AtlasBrainProvenanceAttributionAnalyzer::class)->analyze($rows);

                return [
                    'count' => count($rows),
                    'top_findings' => array_slice($attr['by_finding'], 0, 3),
                    'top_actors' => array_slice((array) ($attr['by_actor'] ?? []), 0, 3),
                ];
            })(),
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

        // NEXT-PATH SUGGESTION — L130 over starvation + rollup already in payload.
        $payload['suggested_next_path'] = app(AtlasBrainNextPathSuggester::class)->suggest(
            (array) ($payload['path_starvation']['starved'] ?? []),
            (array) $payload['cascade_outcomes']['path_rollup'],
        );

        // HEALTH SCORE — 0..100 composite over the perception suite. Computed AFTER the payload is built
        // so the weights derive from the same values surfaced upstream (no double-compute drift).
        $payload['health_score'] = app(AtlasBrainHealthScore::class)->compute(
            ($payload['gate_health']['inspector_holes'] + $payload['gate_health']['seed_gate_holes']) === 0,
            (int) $payload['done_set']['recent_ratio_pct'],
            (int) $payload['result_kind_histogram']['starvation_pct'],
            (float) $payload['hint_entropy']['normalized'],
            (string) $payload['starvation_trend']['direction'],
        );

        $targetSeeds = max(0, (int) ($this->option('target-seeds') ?? 0));
        if ($targetSeeds > 0) {
            $baselineSeeded = max(0, (int) ($this->option('baseline-seeded') ?? 0));
            $currentSeeded = $actor !== '' ? $this->seededCountForActor($scope, $actor) : $this->seededCount($ledger);
            $validSeeds = max(0, $currentSeeded - $baselineSeeded);
            $remaining = max(0, $targetSeeds - $validSeeds);
            $watchdog = $this->quotaWatchdog($remaining, $actor);
            $status = $remaining === 0 ? 'quota_met' : $watchdog['status'];
            $payload['quota'] = [
                'schema' => 'atlas.brain.quota_progress.v1',
                'credit_policy' => 'credited_valid_seeds_only',
                'target_seeds' => $targetSeeds,
                'baseline_seeded' => $baselineSeeded,
                'current_seeded' => $currentSeeded,
                'current_credited_seeded' => $currentSeeded,
                'valid_seeds' => $validSeeds,
                'credited_valid_seeds' => $validSeeds,
                'remaining' => $remaining,
                'unattributed_seeded' => $this->unattributedSeeded($scope),
                'status' => $status,
                'watchdog' => $watchdog,
            ];
            if ($actor !== '') {
                $payload['quota']['actor'] = $actor;
                $note = $this->actorQuotaHistoryNote($scope, $actor, $currentSeeded);
                if ($note !== null) {
                    $payload['quota']['actor_history_note'] = $note;
                }
            }
            if ($status === 'stalled_before_quota') {
                $actorRecoveryHint = null;
                if ($actor !== '') {
                    $actorSupervisor = $this->quotaSupervisor($scope, [[
                        'actor' => $actor,
                        'target' => $targetSeeds,
                        'baseline' => $baselineSeeded,
                    ]], max(1, (int) ($this->option('stale-after') ?? 900)));
                    $actorState = $actorSupervisor['actors'][0] ?? [];
                    if (is_array($actorSupervisor['external_engine'] ?? null)) {
                        $payload['quota']['external_engine'] = $actorSupervisor['external_engine'];
                    }
                    foreach (['stall_reason', 'stall_evidence', 'temp_spec'] as $key) {
                        if (array_key_exists($key, $actorState)) {
                            $payload['quota'][$key] = $actorState[$key];
                        }
                    }
                    if (is_array($actorState['recovery_hint'] ?? null)) {
                        $actorRecoveryHint = $actorState['recovery_hint'];
                    }
                }
                $payload['quota']['recovery_hint'] = $actorRecoveryHint ?? $this->quotaRecoveryHint($scope, $actor, $targetSeeds, $baselineSeeded);
                $payload['quota']['next_command'] = (string) ($payload['quota']['recovery_hint']['command'] ?? '');
                $payload['quota']['must_run_now'] = $payload['quota']['next_command'];
                $payload['quota']['first_action'] = self::firstAction($payload['quota']['must_run_now']);
            }
        }

        $watchActors = $this->watchActorsOption();
        if ($watchActors !== []) {
            $payload['quota_supervisor'] = $this->quotaSupervisor($scope, $watchActors, max(1, (int) ($this->option('stale-after') ?? 900)));
        }

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
            // COHORT FRESHNESS — L116 bucketing of stale/silent/fresh scopes over the same cohort.
            $payload['cohort_freshness'] = app(AtlasBrainStaleScopeDetector::class)->detect(
                array_map(static fn (array $c): string => (string) $c['slug'], $cohorts),
                $reflection,
                3600,
            );
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

    private function seededCount(AtlasBrainDoneSetLedger $ledger): int
    {
        return count(array_filter(
            $ledger->recentCycles(PHP_INT_MAX),
            static fn (array $row): bool => (string) ($row['status'] ?? '') === 'seeded'
        ));
    }

    private function seededCountForActor(string $scope, string $actor): int
    {
        return count(array_filter(
            app(AtlasBrainProvenanceLedger::class)->tail($scope, PHP_INT_MAX),
            fn (array $row): bool => (string) ($row['actor'] ?? '') === $actor && $this->rowCountsAsCredited($row)
        ));
    }

    /** @return array<int, array{actor:string,target:int,baseline:int}> */
    private function watchActorsOption(): array
    {
        $raw = trim((string) ($this->option('watch-actors') ?? ''));
        if ($raw === '') {
            return [];
        }

        $actors = [];
        foreach (explode(',', $raw) as $entry) {
            $parts = array_map('trim', explode(':', $entry));
            $actor = (string) ($parts[0] ?? '');
            $target = max(0, (int) ($parts[1] ?? 0));
            if ($actor === '' || $target <= 0) {
                continue;
            }
            $actors[] = [
                'actor' => $actor,
                'target' => $target,
                'baseline' => max(0, (int) ($parts[2] ?? 0)),
            ];
        }

        return $actors;
    }

    /**
     * @param  array<int, array{actor:string,target:int,baseline:int}>  $watchActors
     * @return array{schema:string,status:string,active_brain_commands:int,actors:array<int,array<string,mixed>>}
     */
    private function quotaSupervisor(string $scope, array $watchActors, int $staleAfterSeconds): array
    {
        $rows = app(AtlasBrainProvenanceLedger::class)->tail($scope, PHP_INT_MAX);
        $heartbeatRows = app(AtlasBrainHeartbeatLedger::class)->tail($scope, PHP_INT_MAX);
        $activeLines = $this->activeBrainCommandLines();
        $active = count($activeLines);
        $now = time();
        $actors = [];
        $doneSet = new AtlasBrainDoneSetLedger($scope, (string) config('atlas.brain.done_set_root'));
        $unattributed = $this->unattributedSeeded($scope);

        foreach ($watchActors as $watch) {
            $actorRows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (string) ($row['actor'] ?? '') === $watch['actor']
            ));
            $actorHeartbeats = array_values(array_filter(
                $heartbeatRows,
                static fn (array $row): bool => (string) ($row['actor'] ?? '') === $watch['actor']
            ));
            $current = count($actorRows);
            $currentRaw = $current;
            $current = count(array_filter($actorRows, fn (array $row): bool => $this->rowCountsAsCredited($row)));
            $valid = max(0, $current - $watch['baseline']);
            $remaining = max(0, $watch['target'] - $valid);
            $lastSeed = $this->lastSeed($actorRows);
            $age = $lastSeed === null ? null : max(0, $now - (int) $lastSeed['recorded_at']);
            $lastHeartbeat = $this->lastHeartbeat($actorHeartbeats);
            $heartbeatAge = $lastHeartbeat === null ? null : max(0, $now - (int) $lastHeartbeat['recorded_at']);
            $activityAge = $heartbeatAge ?? $age;
            $tempSpec = $this->tempSpecForActor($watch['actor'], $doneSet);
            $unseededTempSpec = $this->unseededTempSpec($tempSpec, $lastSeed);
            $actorActive = count(array_filter(
                $activeLines,
                static fn (string $line): bool => self::isActiveBrainCommandLineForActor($line, $watch['actor'])
            ));
            $status = $remaining === 0 ? 'quota_met' : ($actorActive > 0 ? 'active_under_quota' : 'stalled_before_quota');

            $row = [
                'actor' => $watch['actor'],
                'target_seeds' => $watch['target'],
                'baseline_seeded' => $watch['baseline'],
                'current_seeded' => $current,
                'current_seeded_raw' => $currentRaw,
                'current_credited_seeded' => $current,
                'valid_seeds' => $valid,
                'credited_valid_seeds' => $valid,
                'remaining' => $remaining,
                'status' => $status,
                'last_seed' => $lastSeed,
                'last_seed_age_seconds' => $age,
                'last_heartbeat' => $lastHeartbeat,
                'last_heartbeat_age_seconds' => $heartbeatAge,
                'active_brain_commands' => $actorActive,
                'stale_after_seconds' => $staleAfterSeconds,
                'activity_status' => $actorActive > 0 ? 'active' : ($activityAge === null ? 'none' : ($activityAge > $staleAfterSeconds ? 'stale' : 'fresh')),
                'temp_spec' => $tempSpec,
            ];
            $historyNote = $this->actorQuotaHistoryNote($scope, $watch['actor'], $current);
            if ($historyNote !== null) {
                $row['actor_history_note'] = $historyNote;
            }

            if ($status === 'stalled_before_quota') {
                $row += $this->stallExplanation($lastSeed, $lastHeartbeat, $age, $heartbeatAge, $staleAfterSeconds, $tempSpec, $unseededTempSpec);
                $recoveryTempSpec = ($tempSpec['done_set_hit'] ?? false) === true ? $tempSpec : $unseededTempSpec;
                $row['recovery_hint'] = $this->quotaRecoveryHint($scope, $watch['actor'], $watch['target'], $watch['baseline'], $recoveryTempSpec);
                $row['next_command'] = (string) ($row['recovery_hint']['command'] ?? '');
                $row['must_run_now'] = $row['next_command'];
                $row['first_action'] = self::firstAction($row['must_run_now']);
            }

            $actors[] = $row;
        }

        $statuses = array_column($actors, 'status');
        $stalledActors = array_values(array_filter(
            $actors,
            static fn (array $row): bool => ($row['status'] ?? '') === 'stalled_before_quota'
        ));
        $activeActorCount = count(array_filter($statuses, static fn (string $status): bool => $status === 'active_under_quota'));
        $stalledActorCount = count($stalledActors);
        $quotaMetActorCount = count(array_filter($statuses, static fn (string $status): bool => $status === 'quota_met'));
        $externalEngine = $this->externalEngineStatus($active, $stalledActors);
        $nextCommands = array_values(array_map(
            static fn (array $row): array => [
                'actor' => (string) $row['actor'],
                'command' => (string) $row['next_command'],
                'first_action' => self::firstAction((string) $row['next_command']),
            ],
            array_filter(
                $actors,
                static fn (array $row): bool => (string) ($row['next_command'] ?? '') !== ''
            )
        ));
        $targetSeeds = (int) array_sum(array_column($actors, 'target_seeds'));
        $validSeeds = (int) array_sum(array_column($actors, 'valid_seeds'));
        $stallReasons = array_count_values(array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['stall_reason'] ?? ''),
            $actors
        ))));

        $payload = [
            'schema' => 'atlas.brain.quota_supervisor.v1',
            'status' => $activeActorCount > 0 && $stalledActorCount > 0
                ? 'partial_active_with_stalls'
                : ($activeActorCount > 0
                ? 'active_under_quota'
                : ($quotaMetActorCount === count($statuses) && $statuses !== [] ? 'quota_met' : 'stalled_before_quota')),
            'progress' => [
                'credit_policy' => 'credited_valid_seeds_only',
                'target_seeds' => $targetSeeds,
                'valid_seeds' => $validSeeds,
                'credited_valid_seeds' => $validSeeds,
                'remaining' => max(0, $targetSeeds - $validSeeds),
                'unattributed_seeded' => $unattributed,
            ],
            'stall_reasons' => $stallReasons,
            'active_actor_count' => $activeActorCount,
            'stalled_actor_count' => $stalledActorCount,
            'active_brain_commands' => $active,
            'external_engine' => $externalEngine,
            'must_run_now' => $nextCommands,
            'next_commands' => $nextCommands,
            'unattributed_seeded' => $unattributed,
            'actors' => $actors,
        ];

        if ($nextCommands !== []) {
            $payload['first_action'] = self::firstAction((string) $nextCommands[0]['command']);
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>|null  $lastSeed
     * @param  array<string,mixed>|null  $lastHeartbeat
     * @return array{stall_reason:string,stall_evidence:string}
     */
    private function stallExplanation(?array $lastSeed, ?array $lastHeartbeat, ?int $seedAge, ?int $heartbeatAge, int $staleAfterSeconds, ?array $tempSpec = null, ?array $unseededTempSpec = null): array
    {
        if (($tempSpec['done_set_hit'] ?? false) === true) {
            return [
                'stall_reason' => 'temp_spec_already_done',
                'stall_evidence' => 'temp spec target is already in the done-set; resume step 1 instead of reseeding it',
            ];
        }

        if (($unseededTempSpec['exists'] ?? false) === true) {
            return [
                'stall_reason' => 'temp_spec_unseeded',
                'stall_evidence' => $lastSeed === null
                    ? 'temp spec exists but no seed or heartbeat was recorded'
                    : 'temp spec exists after the last seed and was not seeded',
            ];
        }

        if ($lastSeed === null && $lastHeartbeat === null) {
            return ['stall_reason' => 'never_started', 'stall_evidence' => 'no seed and no heartbeat for this actor'];
        }
        if ($lastHeartbeat === null) {
            $seedEvidence = ($seedAge ?? 0) > $staleAfterSeconds ? 'last seed is stale' : 'last seed exists';

            return [
                'stall_reason' => 'seeded_then_silent',
                'stall_evidence' => $seedEvidence.' and no heartbeat was recorded',
            ];
        }
        if (($heartbeatAge ?? 0) > $staleAfterSeconds) {
            return [
                'stall_reason' => 'heartbeat_stale',
                'stall_evidence' => 'last heartbeat is older than stale_after_seconds',
            ];
        }

        return ['stall_reason' => 'no_active_brain_command', 'stall_evidence' => 'recent heartbeat exists but no brain command is active'];
    }

    /** @return array{path:string,exists:bool,age_seconds:int|null,task_packet_id:string,target_path:string,done_set_hit:bool}|null */
    private function tempSpecForActor(string $actor, AtlasBrainDoneSetLedger $doneSet): ?array
    {
        if ($actor === '') {
            return null;
        }

        $path = '/tmp/brain-'.$actor.'.json';
        if (! is_file($path)) {
            return null;
        }

        $packetId = '';
        $targetPath = '';
        $contents = @file_get_contents($path);
        $decoded = json_decode(is_string($contents) ? $contents : '', true);
        if (is_array($decoded)) {
            $packet = is_array($decoded['packets'][0] ?? null) ? $decoded['packets'][0] : [];
            $packetId = (string) ($packet['task_packet_id'] ?? '');
            $targetPath = ltrim((string) (((array) ($packet['allowed_files'] ?? []))[0] ?? ''), '/');
        }

        $modifiedAt = @filemtime($path);

        return [
            'path' => $path,
            'exists' => true,
            'age_seconds' => is_int($modifiedAt) ? max(0, time() - $modifiedAt) : null,
            'task_packet_id' => $packetId,
            'target_path' => $targetPath,
            'done_set_hit' => $targetPath !== '' && $doneSet->isDone($targetPath),
        ];
    }

    /** @param array<string,mixed>|null $tempSpec @param array<string,mixed>|null $lastSeed @return array<string,mixed>|null */
    private function unseededTempSpec(?array $tempSpec, ?array $lastSeed): ?array
    {
        if (($tempSpec['exists'] ?? false) !== true) {
            return null;
        }
        if (($tempSpec['done_set_hit'] ?? false) === true) {
            return null;
        }

        $tempId = (string) ($tempSpec['task_packet_id'] ?? '');
        $lastId = (string) ($lastSeed['task_packet_id'] ?? '');

        return $tempId === '' || $tempId !== $lastId ? $tempSpec : null;
    }

    /** @param array<int, array<string,mixed>> $rows */
    private function lastSeed(array $rows): ?array
    {
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return $last === null ? null : [
            'cycle_id' => (string) ($last['cycle_id'] ?? ''),
            'task_packet_id' => (string) ($last['task_packet_id'] ?? ''),
            'target_path' => (string) ($last['target_path'] ?? ''),
            'recorded_at' => (int) ($last['recorded_at'] ?? 0),
        ];
    }

    /** @param array<int, array<string,mixed>> $rows */
    private function lastHeartbeat(array $rows): ?array
    {
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return $last === null ? null : [
            'actor' => (string) ($last['actor'] ?? ''),
            'command' => (string) ($last['command'] ?? ''),
            'status' => (string) ($last['status'] ?? ''),
            'dry_run' => array_key_exists('dry_run', $last) ? (bool) $last['dry_run'] : null,
            'recorded_at' => (int) ($last['recorded_at'] ?? 0),
        ];
    }

    /** @return array{schema:string,status:string,unknown_seeded:int,note:string}|null */
    private function actorQuotaHistoryNote(string $scope, string $actor, int $currentSeeded): ?array
    {
        if ($actor === '' || $currentSeeded > 0) {
            return null;
        }
        $unknown = $this->unattributedSeeded($scope);

        return $unknown > 0 ? [
            'schema' => 'atlas.brain.actor_quota_history_note.v1',
            'status' => 'pre_actor_history_present',
            'unknown_seeded' => $unknown,
            'note' => 'older_provenance_rows_have_no_actor',
        ] : null;
    }

    private function unattributedSeeded(string $scope): int
    {
        return count(array_filter(
            app(AtlasBrainProvenanceLedger::class)->tail($scope, PHP_INT_MAX),
            static fn (array $row): bool => trim((string) ($row['actor'] ?? '')) === ''
        ));
    }

    /** @param array<string,mixed> $row */
    private function rowCountsAsCredited(array $row): bool
    {
        $status = trim((string) ($row['credit_status'] ?? 'legacy_unscored'));

        return $status === '' || $status === 'legacy_unscored' || $status === 'credited';
    }

    /** @return array{schema:string,type:string,command:string,execute_before_analysis:bool,operator_input_required:bool,analysis_before_execution_is_contract_violation:bool} */
    private static function firstAction(string $command): array
    {
        return [
            'schema' => 'atlas.brain.first_action.v1',
            'type' => 'must_run_now',
            'command' => $command,
            'execute_before_analysis' => true,
            'operator_input_required' => false,
            'analysis_before_execution_is_contract_violation' => true,
        ];
    }

    /**
     * @return array{schema:string, active_brain_commands:int, status:string}
     */
    private function quotaWatchdog(int $remaining, string $actor = ''): array
    {
        $active = $this->activeBrainCommands($actor);

        return [
            'schema' => 'atlas.brain.quota_watchdog.v1',
            'active_brain_commands' => $active,
            'status' => $remaining <= 0
                ? 'quota_met'
                : ($active > 0 ? 'active_under_quota' : 'stalled_before_quota'),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function quotaRecoveryHint(string $scope, string $actor = '', int $targetSeeds = 0, int $baselineSeeded = 0, ?array $tempSpec = null): array
    {
        $scopeArg = escapeshellarg($scope);
        $actorArg = escapeshellarg($actor);
        $command = '/opt/homebrew/bin/php -d memory_limit=4096M -d pcov.enabled=0 artisan atlas:brain:next '.$scopeArg.' --scope-signals';
        if ($actor !== '' && $targetSeeds > 0) {
            $command .= ' --actor='.$actorArg;
        }

        $hint = [
            'schema' => 'atlas.brain.quota_recovery_hint.v1',
            'action' => 'resume_external_brain_step_1',
            'command' => $command.' --json',
            'note' => 'external_actor_must_run_command_atlas_does_not_auto_start',
            'external_actor_must_execute' => true,
            'operator_input_required' => false,
            'atlas_auto_started' => false,
        ];

        if ($actor !== '' && $targetSeeds > 0) {
            $hint['worker_prompt_command'] = '/opt/homebrew/bin/php artisan atlas:brain:worker-prompt --scope='.$scopeArg.' --client='.$actorArg.' --baseline-seeded='.$baselineSeeded.' --target-seeds='.$targetSeeds;
        }

        $specPath = (string) ($tempSpec['path'] ?? '');
        if (($tempSpec['done_set_hit'] ?? false) === true) {
            $hint['never_reseed_done_set_spec'] = true;
            $hint['auto_recovery_required'] = true;
            $hint['analysis_allowed_before_recovery'] = false;
            $hint['existing_spec_action'] = 'discard_done_set_spec_and_pull_next';
            $hint['existing_spec_path'] = $specPath;
            $hint['discard_existing_spec_command'] = 'rm -f '.escapeshellarg($specPath);
            $hint['command'] = $hint['discard_existing_spec_command'].' && '.$command.' --json';
        }

        if ($actor !== '' && $specPath !== '' && ($tempSpec['done_set_hit'] ?? false) !== true) {
            $seedCommand = '/opt/homebrew/bin/php -d memory_limit=4096M -d pcov.enabled=0 artisan atlas:brain:seed --specs='.escapeshellarg($specPath).' --scope='.$scopeArg.' --actor='.$actorArg.' --require-actor';
            $hint['dry_run_existing_spec_command'] = $seedCommand.' --dry-run --json';
            $hint['seed_existing_spec_command'] = $seedCommand.' --cleanup-specs --json';
            $hint['action'] = 'seed_existing_spec_first';
            $hint['command'] = $hint['dry_run_existing_spec_command'];
        }

        return $hint;
    }

    private function activeBrainCommands(string $actor = ''): int
    {
        $lines = $this->activeBrainCommandLines();
        if (trim($actor) === '') {
            return count($lines);
        }

        return count(array_filter(
            $lines,
            static fn (string $line): bool => self::isActiveBrainCommandLineForActor($line, $actor)
        ));
    }

    /**
     * @param  list<array<string,mixed>>  $stalledActors
     * @return array{schema:string,processes:int,status:string,diagnosis?:string,next_action?:string,stalled_actor_count?:int,stalled_actors?:list<array{actor:string,remaining:int,stall_reason:string,recovery_action:string,recovery_command:string,must_run_now:string,external_actor_must_execute:bool,operator_input_required:bool}>}
     */
    private function externalEngineStatus(int $activeBrainCommands, array $stalledActors = []): array
    {
        $out = [];
        @exec('ps -axo command=', $out);

        return self::externalEngineStatusFromLines($out, $activeBrainCommands, $stalledActors);
    }

    /**
     * @param  list<string>  $lines
     * @param  list<array<string,mixed>>  $stalledActors
     * @return array{schema:string,processes:int,status:string,diagnosis?:string,next_action?:string,stalled_actor_count?:int,stalled_actors?:list<array{actor:string,remaining:int,stall_reason:string,recovery_action:string,recovery_command:string,external_actor_must_execute:bool,operator_input_required:bool}>}
     */
    private static function externalEngineStatusFromLines(array $lines, int $activeBrainCommands, array $stalledActors = []): array
    {
        $processes = count(array_filter($lines, static fn (string $line): bool => self::isExternalEngineProcessLine($line)));

        $status = $activeBrainCommands > 0
            ? 'brain_command_active'
            : ($processes > 0 ? 'engine_open_loop_idle' : 'no_external_engine_process');
        $row = [
            'schema' => 'atlas.brain.external_engine_status.v1',
            'processes' => $processes,
            'status' => $status,
        ];

        if ($status === 'engine_open_loop_idle' && $stalledActors !== []) {
            $row['diagnosis'] = 'external_engine_open_but_brain_loop_idle';
            $row['obedience_failure'] = true;
            $row['obedience_failure_reason'] = 'must_run_now_unexecuted';
            $row['next_action'] = 'resume_external_brain_goal_or_run_actor_recovery_hint';
            $row['stalled_actor_count'] = count($stalledActors);
            $row['stalled_actors'] = array_map(static function (array $actor): array {
                $hint = is_array($actor['recovery_hint'] ?? null) ? $actor['recovery_hint'] : [];

                $mustRunNow = (string) ($actor['must_run_now'] ?? $hint['command'] ?? '');

                return [
                    'actor' => (string) ($actor['actor'] ?? ''),
                    'remaining' => (int) ($actor['remaining'] ?? 0),
                    'stall_reason' => (string) ($actor['stall_reason'] ?? ''),
                    'recovery_action' => (string) ($hint['existing_spec_action'] ?? $hint['action'] ?? ''),
                    'recovery_command' => (string) ($hint['command'] ?? ''),
                    'must_run_now' => $mustRunNow,
                    'first_action' => self::firstAction($mustRunNow),
                    'external_actor_must_execute' => (bool) ($hint['external_actor_must_execute'] ?? false),
                    'operator_input_required' => (bool) ($hint['operator_input_required'] ?? true),
                    'auto_recovery_required' => (bool) ($hint['auto_recovery_required'] ?? false),
                    'analysis_allowed_before_recovery' => (bool) ($hint['analysis_allowed_before_recovery'] ?? true),
                    'obedience_failure' => true,
                    'obedience_failure_reason' => 'must_run_now_unexecuted',
                ];
            }, $stalledActors);
        }

        return $row;
    }

    /** @return list<string> */
    private function activeBrainCommandLines(): array
    {
        $out = [];
        @exec('ps -axo command=', $out);

        return array_values(array_filter($out, static function (string $line): bool {
            return self::isActiveBrainCommandLine($line);
        }));
    }

    private static function isActiveBrainCommandLine(string $line): bool
    {
        return str_contains($line, 'artisan atlas:brain:next')
            || str_contains($line, 'artisan atlas:brain:seed')
            || str_contains($line, 'artisan atlas:aobg:guard /tmp/brain-');
    }

    private static function isActiveBrainCommandLineForActor(string $line, string $actor): bool
    {
        $actor = trim($actor);
        if ($actor === '' || ! self::isActiveBrainCommandLine($line)) {
            return false;
        }

        if (str_contains($line, '/tmp/brain-'.$actor.'.json')) {
            return true;
        }

        return preg_match('/--actor=(?:'.preg_quote(escapeshellarg($actor), '/').'|'.preg_quote($actor, '/').')(?=\s|$)/', $line) === 1;
    }

    private static function isExternalEngineProcessLine(string $line): bool
    {
        $lower = strtolower($line);
        if (str_contains($lower, 'artisan atlas:brain:state')) {
            return false;
        }

        return str_contains($lower, '/applications/claude.app/')
            || str_contains($lower, '/claude-code/')
            || str_contains($lower, '/contents/macos/claude');
    }
}
