<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiCompoundingMemory;
use App\Models\AiLearningCandidate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AtlasCompoundingMemoryService
{
    public const SCHEMA_VERSION = 'atlas.ai.compounding.memory.v1';

    public function promote(AiLearningCandidate $candidate, ?string $revalidationPolicy = null): AiCompoundingMemory
    {
        $evidenceRefs = is_array($candidate->evidence_refs) ? $candidate->evidence_refs : [];
        $policy = $revalidationPolicy ?? (string) data_get($candidate->payload, 'signals.revalidation_policy', 'revalidate_on_failure_or_expiry');

        if (! $candidate->promotion_allowed || $evidenceRefs === [] || $candidate->confidence < 70 || trim($policy) === '') {
            throw new InvalidArgumentException('compounding_memory_requires_evidence_confidence_and_revalidation');
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'learning_candidate_id' => $candidate->id,
            'memory_type' => $candidate->memory_type ?? 'routing_memory',
            'scope' => $candidate->scope ?? 'global',
            'flow_id' => data_get($candidate->payload, 'signals.flow_id'),
            'status' => 'active',
            'claim' => (string) $candidate->claim,
            'confidence' => $candidate->confidence,
            'evidence_refs' => $evidenceRefs,
            'revalidation_policy' => $policy,
            'valid_until' => now()->addDays((int) data_get($candidate->payload, 'signals.valid_days', 30)),
            'last_revalidated_at' => now(),
            'payload' => [
                'candidate_hash' => $candidate->candidate_hash,
                'candidate_receipt_hash' => $candidate->receipt_hash,
            ],
        ];
        $payload['memory_hash'] = CompoundingHash::make([
            'schema' => self::SCHEMA_VERSION,
            'candidate_hash' => $candidate->candidate_hash,
            'claim' => $payload['claim'],
            'evidence_refs' => $payload['evidence_refs'],
        ]);

        return AiCompoundingMemory::query()->firstOrCreate(
            ['memory_hash' => $payload['memory_hash']],
            $payload,
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function approvedForFlow(string $flowId, int $limit = 5): array
    {
        if (! Schema::hasTable('ai_compounding_memories')) {
            return [];
        }

        return AiCompoundingMemory::query()
            ->active()
            ->where(function ($query) use ($flowId): void {
                $query->whereNull('flow_id')->orWhere('flow_id', $flowId);
            })
            ->orderByDesc('confidence')
            ->limit($limit)
            ->get()
            ->map(fn (AiCompoundingMemory $memory): array => [
                'memory_id' => $memory->id,
                'memory_type' => $memory->memory_type,
                'claim' => $memory->claim,
                'confidence' => $memory->confidence,
                'evidence_refs' => $memory->evidence_refs,
                'memory_hash' => $memory->memory_hash,
            ])
            ->all();
    }
}
