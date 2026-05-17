<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiLearningCandidate;
use App\Models\AiRunOutcome;

class AtlasLearningDistiller
{
    public const SCHEMA_VERSION = 'atlas.ai.compounding.learning_candidate.v1';

    /**
     * @param  array<string,mixed>  $signals
     */
    public function distill(AiRunOutcome $outcome, array $signals = []): AiLearningCandidate
    {
        $evidenceRefs = $this->array($signals['evidence_refs'] ?? $outcome->evidence_refs ?? []);
        $claim = $this->string($signals['claim'] ?? null)
            ?? $this->defaultClaim($outcome);
        $confidence = $this->score($signals['confidence'] ?? null, $outcome->evidence_quality);
        $decision = $evidenceRefs === [] ? 'hold' : ($confidence >= 70 ? 'promote' : 'hold');

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_outcome_id' => $outcome->id,
            'status' => $decision === 'promote' ? 'ready_for_promotion' : 'held_for_evidence',
            'decision' => $decision,
            'memory_type' => $this->string($signals['memory_type'] ?? null) ?? $this->memoryTypeFor($outcome->flow_id),
            'scope' => $this->string($signals['scope'] ?? null) ?? 'global',
            'claim' => $claim,
            'confidence' => $confidence,
            'promotion_allowed' => $decision === 'promote',
            'evidence_refs' => $evidenceRefs,
            'payload' => [
                'signals' => $signals,
                'outcome_hash' => $outcome->outcome_hash,
            ],
            'decided_at' => now(),
        ];
        $payload['candidate_hash'] = CompoundingHash::make([
            'schema' => self::SCHEMA_VERSION,
            'run_outcome_id' => $outcome->id,
            'claim' => $claim,
            'evidence_refs' => $evidenceRefs,
        ]);
        $payload['receipt_hash'] = CompoundingHash::make($payload);

        return AiLearningCandidate::query()->firstOrCreate(
            ['candidate_hash' => $payload['candidate_hash']],
            $payload,
        );
    }

    private function defaultClaim(AiRunOutcome $outcome): string
    {
        return sprintf(
            'Flow %s produced %s outcome and should inform future routing, retrieval or execution when matching evidence recurs.',
            $outcome->flow_id,
            $outcome->outcome_status,
        );
    }

    private function memoryTypeFor(string $flowId): string
    {
        return match ($flowId) {
            'atlas_debug' => 'debug_memory',
            'atlas_review' => 'review_memory',
            'atlas_forge' => 'forge_handoff_memory',
            'atlas_research' => 'retrieval_memory',
            default => 'routing_memory',
        };
    }

    /**
     * @return array<int|string,mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function score(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return max(0, min(100, $default));
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
