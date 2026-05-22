<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleCanon;

final class ForgeFailureIntelligenceService
{
    public const SCHEMA_VERSION = 'atlas.forge.failure_intelligence.v1';

    /**
     * @param  array<int,array<string,mixed>>  $partialEvidence
     * @return array<string,mixed>
     */
    public function capsule(
        AiForgeWorkPacketExecutionCycle $cycle,
        string $failureReason,
        array $partialEvidence = [],
    ): array {
        $reason = strtolower($failureReason);
        $failureClass = match (true) {
            str_contains($reason, 'context') || str_contains($reason, 'rag') => 'context_failure',
            str_contains($reason, 'test') || str_contains($reason, 'assert') => 'verification_failure',
            str_contains($reason, 'scope') || str_contains($reason, 'file') => 'scope_failure',
            str_contains($reason, 'provider') || str_contains($reason, 'timeout') => 'provider_runtime_failure',
            str_contains($reason, 'operator') || str_contains($reason, 'decision') => 'operator_decision_required',
            default => 'execution_failure',
        };
        $repairHint = match ($failureClass) {
            'context_failure' => ForgeWorkPacketExecutionCycleCanon::REPAIR_HINT_RETRY_WITH_FRESH_CONTEXT,
            'verification_failure' => ForgeWorkPacketExecutionCycleCanon::REPAIR_HINT_ADD_TESTS,
            'scope_failure' => ForgeWorkPacketExecutionCycleCanon::REPAIR_HINT_NARROW_SCOPE,
            'operator_decision_required' => ForgeWorkPacketExecutionCycleCanon::REPAIR_HINT_REQUEST_OPERATOR_INPUT,
            default => ForgeWorkPacketExecutionCycleCanon::REPAIR_HINT_RETRY_WITH_FRESH_CONTEXT,
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'cycle_uuid' => $cycle->uuid,
            'packet_id' => $cycle->work_packet_canonical_id,
            'failure_class' => $failureClass,
            'failure_excerpt' => mb_substr($failureReason, 0, 500),
            'repair_hint' => $repairHint,
            'partial_evidence_count' => count($partialEvidence),
            'next_repair_packet_needed' => in_array($failureClass, ['scope_failure', 'operator_decision_required'], true),
        ];
        $payload['failure_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
