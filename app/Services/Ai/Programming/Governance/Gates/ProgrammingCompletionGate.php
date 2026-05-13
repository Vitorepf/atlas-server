<?php

namespace App\Services\Ai\Programming\Governance\Gates;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\Governance\ProgrammingScopeMode;

/**
 * Final gate: passes only when every blocking prior gate is green AND there is
 * an approved review AND there is at least one verifiable evidence receipt.
 * On pass, marks the work item as closed and stamps closed_at.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md (Definition Of Done Canonico)
 */
class ProgrammingCompletionGate implements ProgrammingGateContract
{
    public function name(): string
    {
        return 'completion';
    }

    public function evaluate(AtlasProgrammingWorkItem $workItem): ProgrammingGateOutcome
    {
        if ((array) $workItem->evidence_refs_json === []) {
            return ProgrammingGateOutcome::failed('completion_blocked_no_evidence');
        }

        $latestReview = $workItem->latestReview();
        if ($latestReview === null) {
            return ProgrammingGateOutcome::failed('completion_blocked_no_review');
        }
        if ($latestReview->result !== 'approved') {
            return ProgrammingGateOutcome::failed(
                'completion_blocked_review_not_approved',
                ['review_result' => $latestReview->result],
            );
        }

        $mode = ProgrammingScopeMode::from($workItem->scope_mode);
        $blockingGates = array_values(array_filter(
            $mode->requiredGates(),
            static fn (string $name): bool => $name !== 'completion' && $mode->isBlocking($name),
        ));
        $unmet = [];
        foreach ($blockingGates as $name) {
            $latest = $workItem->latestGate($name);
            if ($latest === null || $latest->status !== 'passed') {
                $unmet[] = ['gate' => $name, 'latest_status' => $latest?->status];
            }
        }
        if ($unmet !== []) {
            return ProgrammingGateOutcome::failed(
                'completion_blocked_unmet_gates',
                ['unmet' => $unmet],
            );
        }

        $workItem->forceFill([
            'status' => 'closed',
            'current_stage' => 'completion',
            'closed_at' => now(),
        ])->save();

        return ProgrammingGateOutcome::passed([
            'closed_at' => now()->toJSON(),
            'review_id' => $latestReview->id,
            'evidence_count' => count((array) $workItem->evidence_refs_json),
        ]);
    }
}
