<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\EngineeringKernel\Repair\RepairDiagnosisStage;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Forge\ForgeWorkPacketExecutionCycleCanon;

final class ForgeFailureIntelligenceService
{
    public const SCHEMA_VERSION = 'atlas.forge.failure_intelligence.v1';

    /**
     * MESMA RAIZ (repair-taxonomy): o Forge consulta o cérebro de diagnóstico canônico do
     * EngineeringKernel para falar a linguagem da FailureTaxonomy — advisory, o rótulo próprio
     * do Forge (failure_class/repair_hint) fica intacto. Default-instanciado para não quebrar
     * callers e para diagnóstico puro (sem advisor/corpus).
     */
    public function __construct(
        private readonly RepairDiagnosisStage $diagnosis = new RepairDiagnosisStage,
    ) {}

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

        // MESMA RAIZ: classe/estratégia canônica da FailureTaxonomy via o cérebro de diagnóstico
        // compartilhado (advisory — o rótulo próprio do Forge acima continua a autoridade). Sem
        // sinal explícito de spec congelada, JAMAIS resolve test_wrong (guard pétreo herdado).
        $canonical = $this->diagnosis->diagnose(['failure_output' => $failureReason]);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'cycle_uuid' => $cycle->uuid,
            'packet_id' => $cycle->work_packet_canonical_id,
            'failure_class' => $failureClass,
            'failure_excerpt' => mb_substr($failureReason, 0, 500),
            'repair_hint' => $repairHint,
            'canonical_class' => $canonical['class'],
            'canonical_strategy' => $canonical['strategy'],
            'partial_evidence_count' => count($partialEvidence),
            'next_repair_packet_needed' => in_array($failureClass, ['scope_failure', 'operator_decision_required'], true),
        ];
        $payload['failure_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
