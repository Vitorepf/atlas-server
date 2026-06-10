<?php

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingLearningCandidate;
use App\Services\Ai\Support\DatabaseTableAvailability;

class ProgrammingLearningCandidateStore
{
    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function enqueue(array $candidate, int $ttlDays = 30): array
    {
        if (! $this->storageAvailable()) {
            return array_merge($candidate, [
                'review_queue' => [
                    'queued' => false,
                    'reason' => 'atlas_programming_learning_candidates_table_missing',
                ],
            ]);
        }

        $hash = (string) ($candidate['candidate_hash'] ?? hash('sha256', json_encode($candidate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''));
        $model = AtlasProgrammingLearningCandidate::query()->updateOrCreate(
            ['candidate_hash' => $hash],
            [
                'status' => (string) ($candidate['status'] ?? 'insufficient_evidence'),
                'source_status' => is_string($candidate['source_status'] ?? null) ? $candidate['source_status'] : null,
                'promotion_allowed' => (bool) ($candidate['promotion_allowed'] ?? false),
                'review_required' => (bool) ($candidate['review_required'] ?? true),
                'evidence_refs_json' => array_values(array_filter((array) ($candidate['evidence_refs'] ?? []), 'is_string')),
                'payload_json' => $candidate,
                'promotion_gate_json' => [],
                'rollback_json' => [
                    'available' => true,
                    'strategy' => 'reject_candidate_without_memory_promotion',
                    'candidate_hash' => $hash,
                ],
                'expires_at' => now()->addDays(max(1, $ttlDays)),
            ],
        );

        return array_merge($candidate, [
            'review_queue' => [
                'queued' => true,
                'candidate_id' => $model->id,
                'status' => $model->status,
                'expires_at' => optional($model->expires_at)->toJSON(),
                'dedupe_key' => $hash,
                'rollback_available' => true,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $promotionGate
     * @return array<string,mixed>
     */
    public function recordPromotionGate(string $candidateHash, array $promotionGate): array
    {
        if (! $this->storageAvailable()) {
            return [
                'recorded' => false,
                'reason' => 'atlas_programming_learning_candidates_table_missing',
            ];
        }

        $model = AtlasProgrammingLearningCandidate::query()
            ->where('candidate_hash', $candidateHash)
            ->first();

        if (! $model instanceof AtlasProgrammingLearningCandidate) {
            return [
                'recorded' => false,
                'reason' => 'candidate_not_found',
            ];
        }

        $allowed = (bool) ($promotionGate['promotion_allowed'] ?? false);
        $model->forceFill([
            'promotion_allowed' => $allowed,
            'status' => $allowed ? 'promotion_approved' : 'review_blocked',
            'promotion_gate_json' => $promotionGate,
            'reviewed_at' => now(),
        ])->save();

        return [
            'recorded' => true,
            'candidate_id' => $model->id,
            'status' => $model->status,
            'promotion_allowed' => $model->promotion_allowed,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function pending(int $limit = 50): array
    {
        if (! $this->storageAvailable()) {
            return [];
        }

        return AtlasProgrammingLearningCandidate::query()
            ->whereIn('status', ['candidate_ready_for_review', 'insufficient_evidence', 'review_blocked'])
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->limit(max(1, min($limit, 200)))
            ->get()
            ->map(fn (AtlasProgrammingLearningCandidate $candidate): array => [
                'candidate_hash' => $candidate->candidate_hash,
                'status' => $candidate->status,
                'source_status' => $candidate->source_status,
                'promotion_allowed' => $candidate->promotion_allowed,
                'review_required' => $candidate->review_required,
                'evidence_refs' => $candidate->evidence_refs_json ?? [],
                'expires_at' => optional($candidate->expires_at)->toJSON(),
                'rollback' => $candidate->rollback_json ?? [],
            ])
            ->values()
            ->all();
    }

    private function storageAvailable(): bool
    {
        return DatabaseTableAvailability::has('atlas_programming_learning_candidates');
    }
}
