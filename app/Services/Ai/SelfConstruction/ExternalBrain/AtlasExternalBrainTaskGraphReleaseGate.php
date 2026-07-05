<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure gate that decides whether a task chain node can be released to the queue.
 *
 * Prevents orphan chain packets by blocking dependent work until the prerequisite
 * has runnable proof (not just documentation), namespace lane safety is confirmed,
 * and worker capacity exists.
 *
 * Also provides batch-level evaluateBatch() for critical-path, roadmap-gap,
 * blocker-repair, novelty-score coherence checks.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainTaskGraphReleaseGate
{
    public const SCHEMA = 'atlas.external_brain.task_graph_release_gate.v1';

    public const DECISION_RELEASE = 'release';

    public const DECISION_BLOCK = 'block';

    private const LOW_NOVELTY_THRESHOLD = 0.3;

    /**
     * @param  array{
     *   task_id?:string,
     *   prerequisite_task_ids?:list<string>,
     *   prerequisite_evidence?:array<string,array{implemented:bool,proof_passed:bool,quarantined:bool}>,
     *   namespace_lane_safe?:bool,
     *   worker_capacity_available?:bool,
     * }  $facts
     * @return array{
     *   schema:string,
     *   decision:string,
     *   reason:string,
     *   required_prerequisite_ids:list<string>,
     *   next_safe_chain_step:string,
     * }
     */
    public function evaluate(array $facts): array
    {
        $taskId = (string) ($facts['task_id'] ?? 'unknown');
        $prereqIds = array_values(array_filter(
            array_map('strval', (array) ($facts['prerequisite_task_ids'] ?? []))
        ));
        $evidence = $facts['prerequisite_evidence'] ?? [];
        $namespaceSafe = (bool) ($facts['namespace_lane_safe'] ?? false);
        $workerCapacity = (bool) ($facts['worker_capacity_available'] ?? false);

        // No prerequisites → always release (root node).
        if ($prereqIds === []) {
            return $this->envelope(
                self::DECISION_RELEASE,
                "no prerequisites — root node free to release",
                [],
                "release task {$taskId}"
            );
        }

        // Check each prerequisite.
        $unmetPrereqs = [];
        foreach ($prereqIds as $prereqId) {
            $ev = $evidence[$prereqId] ?? null;
            if (! is_array($ev)) {
                // Missing evidence entirely.
                $unmetPrereqs[$prereqId] = 'missing_evidence';
                continue;
            }
            $quarantined = (bool) ($ev['quarantined'] ?? false);
            $implemented = (bool) ($ev['implemented'] ?? false);
            $proofPassed = (bool) ($ev['proof_passed'] ?? false);

            if ($quarantined) {
                $unmetPrereqs[$prereqId] = 'prerequisite_quarantined';
            } elseif (! $implemented) {
                $unmetPrereqs[$prereqId] = 'prerequisite_not_implemented';
            } elseif (! $proofPassed) {
                $unmetPrereqs[$prereqId] = 'prerequisite_proof_not_passed';
            }
        }

        if ($unmetPrereqs !== []) {
            $blockReasons = [];
            foreach ($unmetPrereqs as $pid => $reason) {
                $blockReasons[] = "{$pid}:{$reason}";
            }
            sort($blockReasons, SORT_STRING);
            $firstUnmet = array_key_first($unmetPrereqs);

            return $this->envelope(
                self::DECISION_BLOCK,
                'blocked: ' . implode(', ', $blockReasons),
                $prereqIds,
                "resolve prerequisite {$firstUnmet} before releasing {$taskId}"
            );
        }

        // Prerequisites met — check namespace and worker capacity.
        if (! $namespaceSafe) {
            return $this->envelope(
                self::DECISION_BLOCK,
                'blocked: namespace_lane_unsafe',
                $prereqIds,
                "wait for namespace lane to become safe before releasing {$taskId}"
            );
        }

        if (! $workerCapacity) {
            return $this->envelope(
                self::DECISION_BLOCK,
                'blocked: worker_capacity_unavailable',
                $prereqIds,
                "wait for worker capacity before releasing {$taskId}"
            );
        }

        return $this->envelope(
            self::DECISION_RELEASE,
            "all prerequisites met with runnable proof, namespace safe, capacity available",
            $prereqIds,
            "release task {$taskId}"
        );
    }

    /**
     * Batch-level evaluation. Accepts a list of candidates with critical_path_task_ids,
     * roadmap_gap, blocker_repair, and novelty_score signals. Returns release_allowed,
     * blocked_task_ids, batch_coherence status, and required_rewrites for low-novelty
     * off-path leaves.
     *
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function evaluateBatch(array $facts): array
    {
        $candidates = array_values(array_filter(
            (array) ($facts['candidates'] ?? []),
            static fn ($c): bool => is_array($c) && isset($c['task_id']),
        ));
        $criticalPathIds = array_flip(array_map('strval', (array) ($facts['critical_path_task_ids'] ?? [])));
        $criticalPathUnresolved = $criticalPathIds !== [];

        $blockedTaskIds = [];
        $blockReasons = [];
        $requiredRewrites = [];
        $batchCoherenceFailed = false;

        foreach ($candidates as $c) {
            $taskId = (string) $c['task_id'];
            $novelty = (float) ($c['novelty_score'] ?? 1.0);
            $onCriticalPath = (bool) ($c['on_critical_path'] ?? false);
            $coversRoadmapGap = (bool) ($c['covers_roadmap_gap'] ?? false);
            $repairsBlocker = (bool) ($c['repairs_blocker'] ?? false);

            // AC4: Low-novelty candidates (possible duplicates / padding) are blocked
            // even if on critical path, with rewrite guidance.
            if ($novelty < self::LOW_NOVELTY_THRESHOLD) {
                $blockedTaskIds[] = $taskId;
                $blockReasons[] = "{$taskId}:low_novelty_duplicate_risk";
                $requiredRewrites[] = [
                    'task_id' => $taskId,
                    'reason' => 'low_novelty_duplicate_risk',
                    'novelty_score' => $novelty,
                    'guidance' => "Rewrite {$taskId} to deliver distinct mechanism value or consolidate with existing work.",
                ];
                continue;
            }

            // AC3: roadmap-gap and blocker-repair tasks are always admitted,
            // even when critical_path is unresolved.
            if ($coversRoadmapGap || $repairsBlocker) {
                continue; // admitted regardless of critical path
            }

            // AC2: off-path leaf work is blocked when critical path is unresolved.
            if ($criticalPathUnresolved && ! $onCriticalPath) {
                $blockedTaskIds[] = $taskId;
                $blockReasons[] = "{$taskId}:off_path_while_critical_path_unresolved";
                $requiredRewrites[] = [
                    'task_id' => $taskId,
                    'reason' => 'off_path_while_critical_path_unresolved',
                    'guidance' => "Rewrite {$taskId} as a critical-path, roadmap-gap or blocker-repair task.",
                ];
                continue;
            }

            // Admitted.
        }

        $releaseAllowed = $blockedTaskIds === [];

        // AC2: mixed batches with unrelated off-path leaves → batch_coherence_failed.
        if ($blockedTaskIds !== [] && count($candidates) > 1) {
            $batchCoherenceFailed = true;
        }

        $result = [
            'schema' => self::SCHEMA,
            'release_allowed' => $releaseAllowed,
            'blocked_task_ids' => $blockedTaskIds,
            'release_reasons' => $blockReasons,
            'required_rewrites' => $requiredRewrites,
            'critical_path_unresolved' => $criticalPathUnresolved,
            'batch_coherence_failed' => $batchCoherenceFailed,
        ];

        // Passthrough extra facts (e.g. backlog_depth) for diagnostics.
        foreach (['backlog_depth', 'worker_count'] as $passthrough) {
            if (array_key_exists($passthrough, $facts)) {
                $result[$passthrough] = $facts[$passthrough];
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $requiredPrereqIds
     * @return array{schema:string,decision:string,reason:string,required_prerequisite_ids:list<string>,next_safe_chain_step:string}
     */
    private function envelope(string $decision, string $reason, array $requiredPrereqIds, string $nextStep): array
    {
        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'reason' => $reason,
            'required_prerequisite_ids' => $requiredPrereqIds,
            'next_safe_chain_step' => $nextStep,
        ];
    }
}
