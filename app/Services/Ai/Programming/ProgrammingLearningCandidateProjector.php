<?php

namespace App\Services\Ai\Programming;

class ProgrammingLearningCandidateProjector
{
    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    public function project(array $result): array
    {
        $evidenceRefs = array_values((array) ($result['evidence_refs'] ?? []));
        $eligible = $evidenceRefs !== [] && in_array((string) ($result['status'] ?? ''), ['passed', 'blocked', 'failed'], true);

        return [
            'schema_version' => 'atlas.programming.learning_candidate.v1',
            'status' => $eligible ? 'candidate_ready_for_review' : 'insufficient_evidence',
            'promotion_allowed' => false,
            'review_required' => true,
            'evidence_refs' => $evidenceRefs,
            'source_status' => $result['status'] ?? null,
            'candidate_hash' => hash('sha256', json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'expires_at' => now()->addDays(30)->toJSON(),
            'rollback' => [
                'available' => true,
                'strategy' => 'reject_candidate_without_memory_promotion',
            ],
            'prohibited_actions' => [
                'auto_promote_to_memory',
                'send_raw_private_code_to_provider_memory',
            ],
        ];
    }
}