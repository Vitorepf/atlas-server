<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Pure compiler that compiles proof requirements showing how each proposed task
 * unlocks a downstream capability or removes a blocker.
 *
 * A task passes when it has either:
 *   - downstream_capability: a named capability it unlocks
 *   - removes_blocker: a named blocker it removes (for repair tasks)
 *
 * Tasks without downstream unlock proof fail.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasTaskGraphCapabilityUnlockProofCompiler
{
    public const SCHEMA = 'atlas.self_construction.task_graph_capability_unlock_proof_compiler.v1';

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    public function compile(array $task): array
    {
        $taskId = (string) ($task['task_id'] ?? '');
        $downstreamCapability = trim((string) ($task['downstream_capability'] ?? ''));
        $removesBlocker = trim((string) ($task['removes_blocker'] ?? ''));
        $blockerEvidence = (array) ($task['blocker_evidence'] ?? []);

        $failures = [];

        if ($downstreamCapability === '' && $removesBlocker === '') {
            $failures[] = 'no_downstream_unlock_proof';
        }

        // Repair tasks with blocker evidence pass.
        if ($removesBlocker !== '' && $blockerEvidence === []) {
            $failures[] = 'repair_task_missing_blocker_evidence';
        }

        $passed = $failures === [];

        $proofType = match (true) {
            $downstreamCapability !== '' => 'capability_unlock',
            $removesBlocker !== '' => 'blocker_removal',
            default => 'none',
        };

        return [
            'schema_version' => self::SCHEMA,
            'task_id' => $taskId,
            'passed' => $passed,
            'failures' => $failures,
            'proof_type' => $proofType,
            'downstream_capability' => $downstreamCapability,
            'removes_blocker' => $removesBlocker,
            'has_blocker_evidence' => $blockerEvidence !== [],
        ];
    }
}
