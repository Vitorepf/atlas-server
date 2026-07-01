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
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainTaskGraphReleaseGate
{
    public const SCHEMA = 'atlas.external_brain.task_graph_release_gate.v1';

    public const DECISION_RELEASE = 'release';

    public const DECISION_BLOCK = 'block';

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
        $namespaceSafe = (bool) ($facts['namespace_lane_safe'] ?? true);
        $workerCapacity = (bool) ($facts['worker_capacity_available'] ?? true);

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
