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

    /** @var list<string> */
    public const BOOL_FIELDS = [self::FIELD_VERIFIED, 'learning_required', 'human_override'];

    public const FIELD_VERIFIED = 'verified';

    public const STATUS_PASSED = 'passed';

    public const STATUS_ABSENT = 'absent';

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

        $outcomeStatus = AiValueNormalizer::lowerTrimmedString($native['outcome_status'] ?? $native['status'] ?? self::STATUS_PASSED);
        $status = OutcomeEnvelope::normalizeStatus($outcomeStatus);

        $verifiedSourcePresent = array_key_exists(self::FIELD_VERIFIED, $native)
            || is_array($native['payload']['outcome_contract_v2'] ?? null);
        $contract = AiValueNormalizer::arrayOrEmpty($native['payload']['outcome_contract_v2'] ?? null);
        $verifiedBasis = AiValueNormalizer::lowerTrimmedString(
            $contract['verified_basis']
            ?? $native['verified_basis']
            ?? ($verifiedSourcePresent && ($native[self::FIELD_VERIFIED] ?? false) === true
                ? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_GATES_PASSED
                : AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT)
        );
        $verified = (AiValueNormalizer::boolOrNull($contract[self::FIELD_VERIFIED] ?? $native[self::FIELD_VERIFIED] ?? null) ?? false);
        $evidenceRefs = AiValueNormalizer::arrayOrEmpty($native['evidence_refs'] ?? null);
        $runId = AiValueNormalizer::trimmedStringOrNull($native['run_id'] ?? null) ?? '';

        return OutcomeEnvelope::fromAdapter($this->origin(), [
            'executor' => $executor,
            'task_category' => AiValueNormalizer::trimmedStringOrNull($contract['task_category'] ?? $executor) ?? '',
            'provider' => AiValueNormalizer::trimmedStringOrNull($contract['provider'] ?? $native['provider'] ?? null) ?? self::STATUS_ABSENT,
            'status' => $status,
            self::FIELD_VERIFIED => $verified,
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
            'learning_required' => (AiValueNormalizer::boolOrNull($native['learning_required'] ?? null) ?? false),
            'missed_signals' => AiValueNormalizer::arrayOrEmpty($native['missed_signals'] ?? null),
            'human_override' => (AiValueNormalizer::boolOrNull($native['human_override'] ?? null) ?? false),
        ]);
    }

    public function fromEnvelope(OutcomeEnvelope $envelope): array
    {
        $data = $envelope->toArray();
        $fields = AiValueNormalizer::arrayOrEmpty($data['native_divergent']['fields'] ?? null);

        return [
            'flow_id' => (AiValueNormalizer::trimmedStringOrNull($fields['flow_id'] ?? null) ?? 'atlas_conversation'),
            'run_id' => (AiValueNormalizer::trimmedStringOrNull($data['run_id'] ?? null) ?? ''),
            'outcome_status' => OutcomeEnvelope::toNativeStatus((AiValueNormalizer::trimmedStringOrNull($data['status'] ?? null) ?? OutcomeEnvelope::STATUS_BLOCKED), $this->origin()),
            'flow_quality' => $fields['flow_quality'] ?? null,
            'retrieval_quality' => $fields['retrieval_quality'] ?? null,
            'execution_quality' => $fields['execution_quality'] ?? null,
            'evidence_quality' => $fields['evidence_quality'] ?? null,
            'learning_required' => (AiValueNormalizer::boolOrNull($fields['learning_required'] ?? null) ?? false),
            'missed_signals' => AiValueNormalizer::arrayOrEmpty($fields['missed_signals'] ?? null),
            'human_override' => (AiValueNormalizer::boolOrNull($fields['human_override'] ?? null) ?? false),
            self::FIELD_VERIFIED => (AiValueNormalizer::boolOrNull($data[self::FIELD_VERIFIED] ?? null) ?? false),
            'verified_basis' => AiValueNormalizer::trimmedStringOrNull($data['verified_basis'] ?? null) ?? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT,
        ];
    }
}
