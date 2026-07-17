<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * ESP-06 — Compounding evaluator ({@see AtlasCompoundingOutcomeEvaluator}) adapter.
 */
final class CompoundingOutcomeEnvelopeAdapter implements OutcomeEnvelopeAdapter
{
    public const ADAPTER_KIND = 'compounding';

    public function origin(): string
    {
        return self::ADAPTER_KIND;
    }

    /**
     * @param  array<string,mixed>  $native  evaluator input and/or persisted outcome row
     * @param  array<string,mixed>  $context
     */
    public function toEnvelope(array $native, array $context = []): OutcomeEnvelope
    {
        $flowId = AiValueNormalizer::lowerTrimmedString($native['flow_id'] ?? 'atlas_conversation');
        $executor = match (true) {
            str_contains($flowId, 'forge') => 'forge',
            str_contains($flowId, 'autonomos') => 'autonomos',
            str_contains($flowId, 'dev') => 'dev',
            default => 'engineering',
        };

        $outcomeStatus = AiValueNormalizer::lowerTrimmedString($native['outcome_status'] ?? $native['status'] ?? 'passed');
        $status = OutcomeEnvelope::normalizeStatus($outcomeStatus);

        $verifiedSourcePresent = array_key_exists('verified', $native)
            || is_array($native['payload']['outcome_contract_v2'] ?? null);
        $contract = AiValueNormalizer::arrayOrEmpty($native['payload']['outcome_contract_v2'] ?? null);
        $verifiedBasis = AiValueNormalizer::lowerTrimmedString(
            $contract['verified_basis']
            ?? $native['verified_basis']
            ?? ($verifiedSourcePresent && ($native['verified'] ?? false) === true
                ? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_GATES_PASSED
                : AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT)
        );
        $verified = (bool) ($contract['verified'] ?? $native['verified'] ?? false);
        $evidenceRefs = AiValueNormalizer::arrayOrEmpty($native['evidence_refs'] ?? null);
        $runId = AiValueNormalizer::trimmedStringOrNull($native['run_id'] ?? null) ?? '';

        return OutcomeEnvelope::fromAdapter($this->origin(), [
            'executor' => $executor,
            'task_category' => AiValueNormalizer::trimmedStringOrNull($contract['task_category'] ?? $executor) ?? '',
            'provider' => AiValueNormalizer::trimmedStringOrNull($contract['provider'] ?? $native['provider'] ?? null) ?? 'absent',
            'status' => $status,
            'verified' => $verified,
            'verified_basis' => $verifiedBasis,
            'verified_source_present' => $verifiedSourcePresent,
            'certified_receipt_id' => $contract['certified_receipt_id'] ?? null,
            'evidence_ref_count' => count($evidenceRefs),
            'episode_id' => null,
            'run_id' => $runId !== '' ? $runId : null,
        ], [
            'flow_id' => $flowId,
            'flow_quality' => $native['flow_quality'] ?? null,
            'retrieval_quality' => $native['retrieval_quality'] ?? null,
            'execution_quality' => $native['execution_quality'] ?? null,
            'evidence_quality' => $native['evidence_quality'] ?? null,
            'learning_required' => (bool) ($native['learning_required'] ?? false),
            'missed_signals' => AiValueNormalizer::arrayOrEmpty($native['missed_signals'] ?? null),
            'human_override' => (bool) ($native['human_override'] ?? false),
        ]);
    }

    public function fromEnvelope(OutcomeEnvelope $envelope): array
    {
        $data = $envelope->toArray();
        $fields = AiValueNormalizer::arrayOrEmpty($data['native_divergent']['fields'] ?? null);

        return [
            'flow_id' => (AiValueNormalizer::trimmedStringOrNull($fields['flow_id'] ?? null) ?? 'atlas_conversation'),
            'run_id' => (AiValueNormalizer::trimmedStringOrNull($data['run_id'] ?? null) ?? ''),
            'outcome_status' => OutcomeEnvelope::toNativeStatus((AiValueNormalizer::trimmedStringOrNull($data['status'] ?? null) ?? 'blocked'), $this->origin()),
            'flow_quality' => $fields['flow_quality'] ?? null,
            'retrieval_quality' => $fields['retrieval_quality'] ?? null,
            'execution_quality' => $fields['execution_quality'] ?? null,
            'evidence_quality' => $fields['evidence_quality'] ?? null,
            'learning_required' => (bool) ($fields['learning_required'] ?? false),
            'missed_signals' => AiValueNormalizer::arrayOrEmpty($fields['missed_signals'] ?? null),
            'human_override' => (bool) ($fields['human_override'] ?? false),
            'verified' => (bool) ($data['verified'] ?? false),
            'verified_basis' => AiValueNormalizer::trimmedStringOrNull($data['verified_basis'] ?? null) ?? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT,
        ];
    }
}
