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
    public const BOOL_FIELDS = [self::FIELD_VERIFIED, self::FIELD_LEARNING_REQUIRED, self::FIELD_HUMAN_OVERRIDE];

    public const FIELD_VERIFIED = 'verified';

    public const FIELD_LEARNING_REQUIRED = 'learning_required';

    public const FIELD_HUMAN_OVERRIDE = 'human_override';

    public const FIELD_VERIFIED_BASIS = 'verified_basis';

    public const STATUS_PASSED = 'passed';

    public const STATUS_ABSENT = 'absent';
    public const FIELD_RUN_ID = 'run_id';
    public const FIELD_RETRIEVAL_QUALITY = 'retrieval_quality';
    public const FIELD_MISSED_SIGNALS = 'missed_signals';
    public const FIELD_FLOW_QUALITY = 'flow_quality';
    public const FIELD_FLOW_ID = 'flow_id';
    public const FIELD_EXECUTION_QUALITY = 'execution_quality';
    public const FIELD_EVIDENCE_QUALITY = 'evidence_quality';
    public const FIELD_STATUS = 'status';
    public const FIELD_PROVIDER = 'provider';
    public const FIELD_TASK_CATEGORY = 'task_category';
    public const FIELD_CERTIFIED_RECEIPT_ID = 'certified_receipt_id';
    public const FIELD_OUTCOME_STATUS = 'outcome_status';
    public const FIELD_PAYLOAD = 'payload';
    public const FIELD_OUTCOME_CONTRACT_V2 = 'outcome_contract_v2';
    public const FIELD_EPISODE_ID = 'episode_id';

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
        $flowId = AiValueNormalizer::lowerTrimmedString($native[self::FIELD_FLOW_ID] ?? 'atlas_conversation');
        $executor = match (true) {
            str_contains($flowId, 'forge') => 'forge',
            str_contains($flowId, 'autonomos') => 'autonomos',
            str_contains($flowId, 'dev') => 'dev',
            default => 'engineering',
        };

        $outcomeStatus = AiValueNormalizer::lowerTrimmedString($native[self::FIELD_OUTCOME_STATUS] ?? $native[self::FIELD_STATUS] ?? self::STATUS_PASSED);
        $status = OutcomeEnvelope::normalizeStatus($outcomeStatus);

        $verifiedSourcePresent = array_key_exists(self::FIELD_VERIFIED, $native)
            || is_array($native[self::FIELD_PAYLOAD][self::FIELD_OUTCOME_CONTRACT_V2] ?? null);
        $contract = AiValueNormalizer::arrayOrEmpty($native[self::FIELD_PAYLOAD][self::FIELD_OUTCOME_CONTRACT_V2] ?? null);
        $verifiedBasis = AiValueNormalizer::lowerTrimmedString(
            $contract[self::FIELD_VERIFIED_BASIS]
            ?? $native[self::FIELD_VERIFIED_BASIS]
            ?? ($verifiedSourcePresent && ($native[self::FIELD_VERIFIED] ?? false) === true
                ? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_GATES_PASSED
                : AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT)
        );
        $verified = (AiValueNormalizer::boolOrNull($contract[self::FIELD_VERIFIED] ?? $native[self::FIELD_VERIFIED] ?? null) ?? false);
        $evidenceRefs = AiValueNormalizer::arrayOrEmpty($native['evidence_refs'] ?? null);
        $runId = AiValueNormalizer::trimmedStringOrNull($native[self::FIELD_RUN_ID] ?? null) ?? '';

        return OutcomeEnvelope::fromAdapter($this->origin(), [
            'executor' => $executor,
            self::FIELD_TASK_CATEGORY => AiValueNormalizer::trimmedStringOrNull($contract[self::FIELD_TASK_CATEGORY] ?? $executor) ?? '',
            self::FIELD_PROVIDER => AiValueNormalizer::trimmedStringOrNull($contract[self::FIELD_PROVIDER] ?? $native[self::FIELD_PROVIDER] ?? null) ?? self::STATUS_ABSENT,
            self::FIELD_STATUS => $status,
            self::FIELD_VERIFIED => $verified,
            self::FIELD_VERIFIED_BASIS => $verifiedBasis,
            'verified_source_present' => $verifiedSourcePresent,
            self::FIELD_CERTIFIED_RECEIPT_ID => $contract[self::FIELD_CERTIFIED_RECEIPT_ID] ?? null,
            'evidence_ref_count' => count($evidenceRefs),
            self::FIELD_EPISODE_ID => null,
            self::FIELD_RUN_ID => $runId !== '' ? $runId : null,
        ], [
            self::FIELD_FLOW_ID => $flowId,
            self::FIELD_FLOW_QUALITY => $native[self::FIELD_FLOW_QUALITY] ?? null,
            self::FIELD_RETRIEVAL_QUALITY => $native[self::FIELD_RETRIEVAL_QUALITY] ?? null,
            self::FIELD_EXECUTION_QUALITY => $native[self::FIELD_EXECUTION_QUALITY] ?? null,
            self::FIELD_EVIDENCE_QUALITY => $native[self::FIELD_EVIDENCE_QUALITY] ?? null,
            self::FIELD_LEARNING_REQUIRED => (AiValueNormalizer::boolOrNull($native[self::FIELD_LEARNING_REQUIRED] ?? null) ?? false),
            self::FIELD_MISSED_SIGNALS => AiValueNormalizer::arrayOrEmpty($native[self::FIELD_MISSED_SIGNALS] ?? null),
            self::FIELD_HUMAN_OVERRIDE => (AiValueNormalizer::boolOrNull($native[self::FIELD_HUMAN_OVERRIDE] ?? null) ?? false),
        ]);
    }

    public function fromEnvelope(OutcomeEnvelope $envelope): array
    {
        $data = $envelope->toArray();
        $fields = AiValueNormalizer::arrayOrEmpty($data['native_divergent']['fields'] ?? null);

        return [
            self::FIELD_FLOW_ID => (AiValueNormalizer::trimmedStringOrNull($fields[self::FIELD_FLOW_ID] ?? null) ?? 'atlas_conversation'),
            self::FIELD_RUN_ID => (AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_RUN_ID] ?? null) ?? ''),
            self::FIELD_OUTCOME_STATUS => OutcomeEnvelope::toNativeStatus((AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_STATUS] ?? null) ?? OutcomeEnvelope::STATUS_BLOCKED), $this->origin()),
            self::FIELD_FLOW_QUALITY => $fields[self::FIELD_FLOW_QUALITY] ?? null,
            self::FIELD_RETRIEVAL_QUALITY => $fields[self::FIELD_RETRIEVAL_QUALITY] ?? null,
            self::FIELD_EXECUTION_QUALITY => $fields[self::FIELD_EXECUTION_QUALITY] ?? null,
            self::FIELD_EVIDENCE_QUALITY => $fields[self::FIELD_EVIDENCE_QUALITY] ?? null,
            self::FIELD_LEARNING_REQUIRED => (AiValueNormalizer::boolOrNull($fields[self::FIELD_LEARNING_REQUIRED] ?? null) ?? false),
            self::FIELD_MISSED_SIGNALS => AiValueNormalizer::arrayOrEmpty($fields[self::FIELD_MISSED_SIGNALS] ?? null),
            self::FIELD_HUMAN_OVERRIDE => (AiValueNormalizer::boolOrNull($fields[self::FIELD_HUMAN_OVERRIDE] ?? null) ?? false),
            self::FIELD_VERIFIED => (AiValueNormalizer::boolOrNull($data[self::FIELD_VERIFIED] ?? null) ?? false),
            self::FIELD_VERIFIED_BASIS => AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_VERIFIED_BASIS] ?? null) ?? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT,
        ];
    }
}
