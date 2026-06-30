<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Deterministic scheduler planner for multiple project-lane runtime daemon instances.
 *
 * Pure: no I/O, no process, no provider, no git, no queue/scheduler side-effect. Reuses
 * shared-main scope locks (no parallel orchestration stack).
 *
 * Ordering for "ready" lanes:
 *   1. higher urgency first
 *   2. older last_heartbeat_at (more stale → schedule sooner — until policy threshold)
 *   3. lexical lane_id
 */
final class AtlasProjectLaneRuntimeInstanceScheduler
{
    public const SCHEMA = 'atlas.project_lane.runtime_instance_scheduler.v1';

    public const HOLD_HUMAN_DEPENDENCY = 'human_or_operator_dependency';

    public const HOLD_EXTERNAL_PROVIDER = 'external_provider_dependency';

    public const HOLD_STALE_HEARTBEAT = 'stale_heartbeat';

    public const HOLD_SAFETY_STOP = 'safety_stop';

    public const HOLD_QUEUE_NAMESPACE_CONFLICT = 'queue_namespace_conflict';

    public const HOLD_WRITE_ROOT_LEAK = 'cross_project_write_root_leak';

    public const HOLD_MISSING_ISOLATION_EVIDENCE = 'missing_isolation_evidence';

    public const HOLD_STALE_KNOWLEDGE_SYNC = 'stale_knowledge_sync';

    public const HOLD_BUDGET_EXHAUSTED = 'budget_exhausted';

    public const HOLD_MAX_PARALLEL_REACHED = 'max_parallel_lanes_reached';

    public const HOLD_STALE_CONTEXT = 'stale_context';

    public const STARVATION_COUNT_THRESHOLD = 3;

    public const STARVATION_TICK_AGE_THRESHOLD_SECONDS = 300;

    /**
     * @param  list<array<string,mixed>>  $instances output of {@see AtlasProjectLaneRuntimeInstanceRegistry::build}.instances
     * @param  array<string,mixed>  $facts {max_parallel_lanes?, lane_health?, budget?}
     * @return array<string,mixed>
     */
    public function plan(array $instances, array $facts = []): array
    {
        $maxParallel = max(0, (int) ($facts['max_parallel_lanes'] ?? 1));
        $laneHealth = is_array($facts['lane_health'] ?? null) ? $facts['lane_health'] : [];
        $budget = is_array($facts['budget'] ?? null) ? $facts['budget'] : [];
        $stalenessSeconds = max(1, (int) ($facts['heartbeat_staleness_seconds'] ?? 180));

        $ready = [];
        $held = [];
        $blocked = [];

        $namespaceSeen = [];
        $writeRootClaims = [];

        foreach ($instances as $instance) {
            if (! is_array($instance)) {
                continue;
            }
            $laneId = (string) ($instance['lane_id'] ?? '');
            $projectId = (string) ($instance['project_id'] ?? '');
            $namespace = (string) ($instance['queue_namespace'] ?? '');
            $allowedRoots = array_values((array) ($instance['allowed_roots'] ?? []));
            $isolationRefs = array_values((array) ($instance['isolation_evidence_refs'] ?? []));
            $laneFacts = is_array($laneHealth[$laneId] ?? null) ? $laneHealth[$laneId] : [];

            $reasons = [];
            if ((bool) ($laneFacts['safety_stop'] ?? false)) {
                $reasons[] = self::HOLD_SAFETY_STOP;
            }
            if (($laneFacts['heartbeat_age_seconds'] ?? null) !== null && (int) $laneFacts['heartbeat_age_seconds'] > $stalenessSeconds) {
                $reasons[] = self::HOLD_STALE_HEARTBEAT;
            }
            if ((bool) ($laneFacts['stale_knowledge_sync'] ?? false)) {
                $reasons[] = self::HOLD_STALE_KNOWLEDGE_SYNC;
            }
            if ((bool) ($laneFacts['context_freshness_stale'] ?? false)) {
                $reasons[] = self::HOLD_STALE_CONTEXT;
            }
            foreach ((array) ($laneFacts['steady_state_dependencies'] ?? []) as $dep) {
                $depStr = (string) $dep;
                if (in_array($depStr, ['operator', 'human'], true)) {
                    $reasons[] = self::HOLD_HUMAN_DEPENDENCY;
                }
                if (in_array($depStr, ['external_provider', 'claude_code', 'codex', 'cursor'], true)) {
                    $reasons[] = self::HOLD_EXTERNAL_PROVIDER;
                }
            }
            if ($isolationRefs === []) {
                $reasons[] = self::HOLD_MISSING_ISOLATION_EVIDENCE;
            }
            if (isset($namespaceSeen[$namespace]) && $namespace !== '') {
                $reasons[] = self::HOLD_QUEUE_NAMESPACE_CONFLICT;
            }
            foreach ($allowedRoots as $root) {
                if (isset($writeRootClaims[$root]) && $writeRootClaims[$root] !== $projectId) {
                    $reasons[] = self::HOLD_WRITE_ROOT_LEAK;
                    break;
                }
            }

            if ($reasons !== []) {
                $held[] = [
                    'lane_id' => $laneId,
                    'project_id' => $projectId,
                    'reasons' => array_values(array_unique($reasons)),
                ];

                continue;
            }

            if ($namespace !== '') {
                $namespaceSeen[$namespace] = true;
            }
            foreach ($allowedRoots as $root) {
                $writeRootClaims[$root] = $projectId;
            }

            $starvationCount = (int) ($laneFacts['starvation_count'] ?? 0);
            $timeSinceTick   = (int) ($laneFacts['time_since_last_tick_seconds'] ?? 0);
            $starvationBoosted = $starvationCount >= self::STARVATION_COUNT_THRESHOLD
                || $timeSinceTick >= self::STARVATION_TICK_AGE_THRESHOLD_SECONDS;

            $ready[] = [
                'instance' => $instance,
                'urgency' => (int) ($laneFacts['urgency'] ?? 0),
                'heartbeat_age_seconds' => (int) ($laneFacts['heartbeat_age_seconds'] ?? 0),
                'lane_id' => $laneId,
                'starvation_boosted' => $starvationBoosted,
                'starvation_count' => $starvationCount,
                'time_since_last_tick' => $timeSinceTick,
            ];
        }

        usort($ready, static function (array $a, array $b): int {
            return [
                -$a['urgency'],
                $a['starvation_boosted'] ? 0 : 1,
                -$a['heartbeat_age_seconds'],
                $a['lane_id'],
            ] <=> [
                -$b['urgency'],
                $b['starvation_boosted'] ? 0 : 1,
                -$b['heartbeat_age_seconds'],
                $b['lane_id'],
            ];
        });

        $remainingBudget = isset($budget['max_ticks']) ? max(0, (int) $budget['max_ticks']) : PHP_INT_MAX;
        $tickNow = [];
        $tickNowFairnessReasons = [];
        $rank = 0;
        foreach ($ready as $r) {
            if (count($tickNow) >= $maxParallel) {
                $blocked[] = [
                    'lane_id' => $r['lane_id'],
                    'project_id' => (string) ($r['instance']['project_id'] ?? ''),
                    'reasons' => [self::HOLD_MAX_PARALLEL_REACHED],
                ];

                continue;
            }
            if ($remainingBudget <= 0) {
                $blocked[] = [
                    'lane_id' => $r['lane_id'],
                    'project_id' => (string) ($r['instance']['project_id'] ?? ''),
                    'reasons' => [self::HOLD_BUDGET_EXHAUSTED],
                ];

                continue;
            }
            $rank++;
            $laneReasons = ['urgency:'.$r['urgency'], 'rank:'.$rank];
            if ($r['starvation_boosted']) {
                if ($r['starvation_count'] >= self::STARVATION_COUNT_THRESHOLD) {
                    $laneReasons[] = 'starvation_count:'.$r['starvation_count'];
                }
                if ($r['time_since_last_tick'] >= self::STARVATION_TICK_AGE_THRESHOLD_SECONDS) {
                    $laneReasons[] = 'tick_age:'.$r['time_since_last_tick'].'s';
                }
            }
            $tickNowFairnessReasons[$r['lane_id']] = $laneReasons;
            $tickNow[] = $r['instance'];
            $remainingBudget--;
        }

        // ── fairness / starvation facts ──────────────────────────────────────
        $perProjectTicks = [];
        foreach ($tickNow as $inst) {
            $pid = (string) ($inst['project_id'] ?? '');
            $perProjectTicks[$pid] = ($perProjectTicks[$pid] ?? 0) + 1;
        }
        ksort($perProjectTicks);

        $starvationRiskLanes = [];
        foreach ($blocked as $b) {
            if (array_intersect($b['reasons'], [self::HOLD_MAX_PARALLEL_REACHED, self::HOLD_BUDGET_EXHAUSTED]) !== []) {
                $starvationRiskLanes[] = $b['lane_id'];
            }
        }

        $heldDurationHints = [];
        foreach ($held as $h) {
            $heldDurationHints[$h['lane_id']] = $this->heldDurationHint($h['reasons']);
        }

        $tickCount = count($tickNow);
        $readyCount = count($ready); // includes capped lanes
        $fairnessReason = "{$tickCount} lane(s) scheduled from {$readyCount} ready; "
            . count($held) . ' held by isolation/safety holds; '
            . count($starvationRiskLanes) . ' capacity-blocked (starvation risk).';

        $payload = [
            'schema_version' => self::SCHEMA,
            'status' => 'ok',
            'tick_now' => $tickNow,
            'held_lanes' => $held,
            'blocked_lanes' => $blocked,
            'budget_facts' => [
                'max_parallel_lanes' => $maxParallel,
                'budget_remaining' => $remainingBudget === PHP_INT_MAX ? null : $remainingBudget,
                'heartbeat_staleness_seconds' => $stalenessSeconds,
            ],
            'fairness_facts' => [
                'per_project_tick_allocation' => $perProjectTicks,
                'held_duration_hint'          => $heldDurationHints,
                'starvation_risk_lanes'       => $starvationRiskLanes,
                'fairness_reason'             => $fairnessReason,
                'next_lane_to_unblock'        => $starvationRiskLanes[0] ?? null,
                'tick_now_fairness_reasons'   => $tickNowFairnessReasons,
            ],
        ];
        $payload['scheduler_hash'] = $this->hash($payload);

        return $payload;
    }

    /** @param list<string> $reasons */
    private function heldDurationHint(array $reasons): string
    {
        $persistent = [
            self::HOLD_SAFETY_STOP,
            self::HOLD_HUMAN_DEPENDENCY,
            self::HOLD_EXTERNAL_PROVIDER,
            self::HOLD_MISSING_ISOLATION_EVIDENCE,
        ];
        foreach ($reasons as $r) {
            if (in_array($r, $persistent, true)) {
                return 'persistent_until_resolved';
            }
        }

        return 'transient_resolves_when_condition_clears';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        $copy = $payload;
        unset($copy['scheduler_hash']);
        ksort($copy);

        return hash('sha256', (string) json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
