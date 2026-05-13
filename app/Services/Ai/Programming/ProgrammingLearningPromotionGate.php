<?php

namespace App\Services\Ai\Programming;

class ProgrammingLearningPromotionGate
{
    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function evaluate(array $candidate, bool $humanReviewed = false): array
    {
        $evidence = (array) ($candidate['evidence_refs'] ?? []);
        $allowed = $humanReviewed && $evidence !== [] && ($candidate['status'] ?? null) === 'candidate_ready_for_review';

        return [
            'schema_version' => 'atlas.programming.learning_promotion_gate.v1',
            'promotion_allowed' => $allowed,
            'status' => $allowed ? 'passed' : 'blocked',
            'blocking_reasons' => $allowed ? [] : array_values(array_filter([
                $humanReviewed ? null : 'human_review_required',
                $evidence === [] ? 'evidence_refs_required' : null,
            ])),
        ];
    }
}
