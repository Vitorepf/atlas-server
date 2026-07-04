<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Pure, advisory pre-serve gate. Classifies a task packet and routes it before
 * a worker claims it — no queue mutations, no lease changes, no file writes.
 *
 * Input facts:
 *   packet                — {id, objective, allowed_files[], acceptance_criteria[],
 *                           test_only_has_contract, dependencies[]}.
 *   resolved_dependencies — IDs already satisfied (default []).
 *   scope_rules           — {max_files?, forbidden_targets[]?}.
 *
 * AC2 — classification (first match wins):
 *   dependency_wait — has unmet dependencies (AC3: takes priority over other issues).
 *   respec          — scope violation: allowed_files > max_files (default 5).
 *   respec          — forbidden-target: any allowed_file in forbidden_targets.
 *   respec          — empty acceptance_criteria.
 *   respec          — empty objective.
 *   respec          — missing_test_path: has an implementation file but no test path.
 *   respec          — test_only_packet: allowed_files contains only test paths.
 *   serve           — packet is healthy.
 *
 * AC3 — unmet dependencies → dependency_wait, not respec/poison.
 *
 * AC4 — pure, advisory; returns classification + reasons + repair_hints.
 */
final class AtlasTaskQueuePreServeSelfHealingGate
{
    public const SCHEMA = 'atlas.task_quality.pre_serve_self_healing_gate.v1';

    private const DEFAULT_MAX_FILES = 5;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function classify(array $facts): array
    {
        $packet   = is_array($facts['packet'] ?? null)    ? $facts['packet']    : [];
        $resolved = is_array($facts['resolved_dependencies'] ?? null)
            ? array_map('strval', $facts['resolved_dependencies'])
            : [];
        $rules    = is_array($facts['scope_rules'] ?? null) ? $facts['scope_rules'] : [];

        $id           = (string) ($packet['id']        ?? '');
        $objective    = (string) ($packet['objective'] ?? '');
        $allowedFiles = is_array($packet['allowed_files']        ?? null) ? array_map('strval', $packet['allowed_files'])        : [];
        $acceptance   = is_array($packet['acceptance_criteria']  ?? null) ? $packet['acceptance_criteria']  : [];
        $dependencies = is_array($packet['dependencies']         ?? null) ? array_map('strval', $packet['dependencies'])         : [];

        $maxFiles        = (int) ($rules['max_files']         ?? self::DEFAULT_MAX_FILES);
        $forbiddenTargets = is_array($rules['forbidden_targets'] ?? null)
            ? array_map('strval', $rules['forbidden_targets'])
            : [];

        // AC3: dependency_wait first.
        $unmetDeps = array_values(array_filter($dependencies, fn($d) => ! in_array($d, $resolved, true)));
        if (! empty($unmetDeps)) {
            return $this->result('dependency_wait', ['unmet_dependencies'], $unmetDeps, [
                'resolve_dependencies_first' => $unmetDeps,
            ]);
        }

        // Respec checks.
        $reasons      = [];
        $repairHints  = [];

        if (count($allowedFiles) > $maxFiles) {
            $reasons[]                   = 'scope_too_many_files';
            $repairHints['max_files']    = $maxFiles;
        }

        $hitForbidden = array_values(array_intersect($allowedFiles, $forbiddenTargets));
        if (! empty($hitForbidden)) {
            $reasons[]                       = 'forbidden_target';
            $repairHints['forbidden_files']  = $hitForbidden;
        }

        if (empty($acceptance)) {
            $reasons[]                           = 'empty_acceptance_criteria';
            $repairHints['add_acceptance_criteria'] = true;
        }

        if (trim($objective) === '') {
            $reasons[]                    = 'empty_objective';
            $repairHints['add_objective'] = true;
        }

        if ($allowedFiles !== []) {
            $testFiles = array_values(array_filter($allowedFiles, [$this, 'isTestPath']));
            $implFiles = array_values(array_diff($allowedFiles, $testFiles));

            if ($implFiles !== [] && $testFiles === []) {
                $reasons[]                          = 'missing_test_path';
                $repairHints['add_test_file']       = true;
            } elseif ($implFiles === [] && $testFiles !== []) {
                $reasons[]                              = 'test_only_packet';
                $repairHints['add_implementation_file'] = true;
            }
        }

        if (! empty($reasons)) {
            return $this->result('respec', $reasons, [], $repairHints);
        }

        return $this->result('serve', [], [], []);
    }

    private const WORKER_FEED_FLOOR = 2.0;

    /** Poison ratio at/above which packets must be quarantined before serve resumes. */
    private const POISON_RATIO_FLOOR = 0.20;

    /** Stale-claimable ratio at/above which the claimable supply must be replenished before serve. */
    private const STALE_CLAIMABLE_RATIO_FLOOR = 0.50;

    /**
     * queue_action (AC2) maps each internal recommendation onto the canonical 5-state vocabulary:
     * serve, repair_first, reshape_first, quarantine_first, replenish_first.
     */
    private const QUEUE_ACTION_MAP = [
        'repair_malformed_before_serve' => 'repair_first',
        'resolve_collisions_before_serve' => 'repair_first',
        'unblock_before_serve' => 'reshape_first',
        'quarantine_poison_before_serve' => 'quarantine_first',
        'replenish_stale_claimables_before_serve' => 'replenish_first',
        'rescue_stale_claimable_before_serve' => 'replenish_first',
        'top_up_before_serve_starvation' => 'replenish_first',
        'serve_clean' => 'serve',
    ];

    /**
     * action_plan (AC4): concrete, safe next steps per recommendation — never a mutation, always
     * advisory text for a caller to act on.
     */
    private const ACTION_PLAN_MAP = [
        'repair_malformed_before_serve' => ['repair or discard malformed packets', 'do not serve until malformed_count returns to 0'],
        'resolve_collisions_before_serve' => ['deduplicate colliding packet_id values before resuming serve'],
        'unblock_before_serve' => ['resolve the blocking scope/dependency issue on blocked packets before resuming serve'],
        'quarantine_poison_before_serve' => ['quarantine packets flagged as poison before resuming serve'],
        'replenish_stale_claimables_before_serve' => ['originate fresh claimable tasks; stale claimables are not real supply'],
        'rescue_stale_claimable_before_serve' => ['rescue or reprioritize stale claimable packets before serving fresh workers'],
        'top_up_before_serve_starvation' => ['originate more claimable tasks before active workers starve'],
        'serve_clean' => ['serve normally; queue health is within all floors'],
    ];

    /**
     * Pure, READ-ONLY queue-level pre-serve check (no packet writes, no lease
     * changes): when the queue is about to starve workers, it must emit a
     * repair/top-up recommendation BEFORE serving falls through to
     * no_claimable_task — never after.
     *
     * Precedence (first match wins): malformed packets, poison ratio, blocked packets, packet-id
     * collisions and stale claimable ratio are queue corruption/decay — worse than impending
     * starvation — and keep precedence over the worker-floor top-up recommendation.
     *
     * A confirmed-fresh `sufficient_depth` signal (opt-in) overrides the
     * worker-floor starvation check: a caller that has already verified the
     * queue has enough depth right now must not be forced into an
     * unnecessary top-up just because `claimable_per_active_worker` looks
     * thin from a stale angle.
     *
     * @param  array<string,mixed>  $facts  { malformed_count?: int,
     *   blocked_count?: int, collision_count?: int,
     *   claimable_per_active_worker?: float,
     *   poison_ratio?: float, stale_claimable_ratio?: float,
     *   sufficient_depth?: array{value?: bool, fresh?: bool} }
     * @return array<string,mixed>
     */
    public function evaluateQueueHealth(array $facts): array
    {
        $malformedCount = max(0, (int) ($facts['malformed_count'] ?? 0));
        $blockedCount = max(0, (int) ($facts['blocked_count'] ?? 0));
        $collisionCount = max(0, (int) ($facts['collision_count'] ?? 0));
        $claimablePerActiveWorker = $facts['claimable_per_active_worker'] ?? null;
        $poisonRatio = (float) ($facts['poison_ratio'] ?? 0.0);
        $staleClaimableRatio = (float) ($facts['stale_claimable_ratio'] ?? 0.0);
        $staleClaimableCount = max(0, (int) ($facts['stale_claimable_count'] ?? 0));
        $sufficientDepth = is_array($facts['sufficient_depth'] ?? null) ? $facts['sufficient_depth'] : [];
        $sufficientDepthConfirmedFresh = (bool) ($sufficientDepth['value'] ?? false) && (bool) ($sufficientDepth['fresh'] ?? false);

        if ($malformedCount > 0) {
            return $this->queueHealthResult('repair_malformed_before_serve', ['malformed_packets_present:'.$malformedCount]);
        }

        if ($poisonRatio >= self::POISON_RATIO_FLOOR) {
            return $this->queueHealthResult('quarantine_poison_before_serve', ['poison_ratio_at_or_above_floor:'.$poisonRatio]);
        }

        if ($blockedCount > 0) {
            return $this->queueHealthResult('unblock_before_serve', ['blocked_packets_present:'.$blockedCount]);
        }

        if ($collisionCount > 0) {
            return $this->queueHealthResult('resolve_collisions_before_serve', ['packet_id_collisions_present:'.$collisionCount]);
        }

        if ($staleClaimableCount > 0) {
            return $this->queueHealthResult('rescue_stale_claimable_before_serve', ['stale_claimable_count:'.$staleClaimableCount]);
        }

        if ($staleClaimableRatio >= self::STALE_CLAIMABLE_RATIO_FLOOR) {
            return $this->queueHealthResult('replenish_stale_claimables_before_serve', ['stale_claimable_ratio_at_or_above_floor:'.$staleClaimableRatio]);
        }

        if (! $sufficientDepthConfirmedFresh
            && $claimablePerActiveWorker !== null
            && (float) $claimablePerActiveWorker <= self::WORKER_FEED_FLOOR) {
            return $this->queueHealthResult('top_up_before_serve_starvation', [
                'claimable_per_active_worker_at_or_below_floor:'.$claimablePerActiveWorker,
            ]);
        }

        return $this->queueHealthResult('serve_clean', []);
    }

    private const PROOF_REQUIRED_RECOMMENDATIONS = [
        'repair_malformed_before_serve',
        'unblock_before_serve',
        'resolve_collisions_before_serve',
        'top_up_before_serve_starvation',
        'quarantine_poison_before_serve',
        'replenish_stale_claimables_before_serve',
        'rescue_stale_claimable_before_serve',
    ];

    /**
     * @param  list<string>  $reasons
     * @return array<string,mixed>
     */
    private function queueHealthResult(string $recommendation, array $reasons): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'recommendation' => $recommendation,
            'reasons' => $reasons,
            'proof_required' => in_array($recommendation, self::PROOF_REQUIRED_RECOMMENDATIONS, true),
            'queue_action' => self::QUEUE_ACTION_MAP[$recommendation] ?? 'serve',
            'action_plan' => self::ACTION_PLAN_MAP[$recommendation] ?? [],
            'mutates_queue_state' => false,
        ];
    }

    private function isTestPath(string $path): bool
    {
        $norm = ltrim(str_replace('\\', '/', trim($path)), '/');

        return str_starts_with($norm, 'tests/') || str_contains($norm, '/tests/') || str_ends_with($norm, 'Test.php');
    }

    private function result(
        string $classification, array $reasons, array $unmetDeps, array $repairHints,
    ): array {
        return [
            'schema_version'      => self::SCHEMA,
            'classification'      => $classification,
            'reasons'             => $reasons,
            'unmet_dependencies'  => $unmetDeps,
            'repair_hints'        => $repairHints,
        ];
    }
}
