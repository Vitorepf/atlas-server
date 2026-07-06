<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Requires learning-loop evidence before final readiness claims so
 * completed work changes future originator decisions.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasSelfConstructionCompletionLearningLoopEvidenceGate
{
    public const SCHEMA = 'atlas.self_construction.completion_learning_loop_evidence_gate.v1';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $outcomeToNextTask = (bool) ($input['outcome_to_next_task_feedback'] ?? false);
        $learningTransferred = (bool) ($input['learning_transferred'] ?? false);
        $originatorAdjusted = (bool) ($input['originator_adjusted'] ?? false);

        $blockers = [];

        if (! $outcomeToNextTask) {
            $blockers[] = 'missing_outcome_to_next_task_feedback';
        }
        if (! $learningTransferred) {
            $blockers[] = 'missing_learning_transfer';
        }
        if (! $originatorAdjusted) {
            $blockers[] = 'missing_originator_adjustment';
        }

        $ready = $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'ready' => $ready,
            'blockers' => $blockers,
            'outcome_to_next_task_feedback' => $outcomeToNextTask,
            'learning_transferred' => $learningTransferred,
            'originator_adjusted' => $originatorAdjusted,
        ];
    }
}
