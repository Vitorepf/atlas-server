<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * ESP-06 — AEMOR runtime / engineering outcome recorder adapter.
 */
final class AemorOutcomeEnvelopeAdapter implements OutcomeEnvelopeAdapter
{
    public const FIELD_EVIDENCE_REFS = 'evidence_refs';
    public const FIELD_FIELDS = 'fields';
    public const ADAPTER_KIND = 'aemor';

    /** @var list<string> */
    public const BOOL_FIELDS = [self::FIELD_VERIFIED];

    public const FIELD_VERIFIED = 'verified';

    public const FIELD_VERIFIED_BASIS = 'verified_basis';
    public const FIELD_EXECUTOR = 'executor';
    public const FIELD_SUMMARY = 'summary';
    public const FIELD_STATUS = 'status';
    public const FIELD_OUTCOME_TYPE = 'outcome_type';
    public const FIELD_METRICS = 'metrics';
    public const FIELD_BLOCKERS = 'blockers';
    public const FIELD_CONTEXT_UTILITY = 'context_utility';
    public const FIELD_PATCH_OUTCOME = 'patch_outcome';
    public const FIELD_LEARNING_CLAIM = 'learning_claim';
    public const FIELD_TASK_CATEGORY = 'task_category';
    public const FIELD_PROVIDER = 'provider';
    public const FIELD_CERTIFIED_RECEIPT_ID = 'certified_receipt_id';
    public const FIELD_EPISODE_ID = 'episode_id';
    public const FIELD_OUTCOME_CONTRACT_V2 = 'outcome_contract_v2';
    public const FIELD_EVIDENCE_REF_COUNT = 'evidence_ref_count';
    public const FIELD_OBJECTIVE = 'objective';
    public const FIELD_RUN_ID = 'run_id';
    public const FIELD_NATIVE_DIVERGENT = 'native_divergent';
    public const FIELD_SCOPE_ID = 'scope_id';
    public const FIELD_VERIFIED_SOURCE_PRESENT = 'verified_source_present';
    public const FIELD_ENGINEERING = 'engineering';
    public const FIELD_ENGINEERING_DELIVERY = 'engineering_delivery';
    public const FIELD_FORGE = 'forge';
    public const FIELD_AUTONOMOS = 'autonomos';
    public const FIELD_DEV = 'dev';

    public const STATUS_ABSENT = 'absent';

    public function origin(): string
    {
        return self::ADAPTER_KIND;
    }

    /**
     * @param  array<string,mixed>  $native  recorder input and/or closed outcome payload
     * @param  array<string,mixed>  $context
     */
    public function toEnvelope(array $native, array $context = []): OutcomeEnvelope
    {
        $executor = AiValueNormalizer::lowerTrimmedString($native[self::FIELD_EXECUTOR] ?? $context[self::FIELD_EXECUTOR] ?? self::FIELD_ENGINEERING);
        $status = OutcomeEnvelope::normalizeStatus((AiValueNormalizer::trimmedStringOrNull($native[self::FIELD_STATUS] ?? null) ?? OutcomeEnvelope::STATUS_BLOCKED));

        $contract = is_array($context[self::FIELD_OUTCOME_CONTRACT_V2] ?? null)
            ? $context[self::FIELD_OUTCOME_CONTRACT_V2]
            : AiValueNormalizer::arrayOrEmpty($native[self::FIELD_OUTCOME_CONTRACT_V2] ?? null);

        $verifiedSourcePresent = array_key_exists(self::FIELD_VERIFIED, $native)
            || array_key_exists(self::FIELD_VERIFIED_SOURCE_PRESENT, $contract);
        $verifiedBasis = AiValueNormalizer::lowerTrimmedString($contract[self::FIELD_VERIFIED_BASIS] ?? $native[self::FIELD_VERIFIED_BASIS] ?? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT);
        $verified = (AiValueNormalizer::boolOrNull($contract[self::FIELD_VERIFIED] ?? $native[self::FIELD_VERIFIED] ?? null) ?? false);
        $evidenceRefs = AiValueNormalizer::arrayOrEmpty($native[self::FIELD_EVIDENCE_REFS] ?? null);

        $episodeId = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_EPISODE_ID] ?? $native[self::FIELD_EPISODE_ID] ?? null) ?? '';
        $runId = AiValueNormalizer::trimmedStringOrNull($native[self::FIELD_RUN_ID] ?? $native[self::FIELD_SCOPE_ID] ?? null) ?? '';

        return OutcomeEnvelope::fromAdapter($this->origin(), [
            self::FIELD_EXECUTOR => in_array($executor, [self::FIELD_DEV, self::FIELD_FORGE, self::FIELD_AUTONOMOS], true) ? $executor : self::FIELD_ENGINEERING,
            self::FIELD_TASK_CATEGORY => AiValueNormalizer::trimmedStringOrNull($contract[self::FIELD_TASK_CATEGORY] ?? $native[self::FIELD_TASK_CATEGORY] ?? $executor) ?? '',
            self::FIELD_PROVIDER => AiValueNormalizer::trimmedStringOrNull($contract[self::FIELD_PROVIDER] ?? $native[self::FIELD_PROVIDER] ?? null) ?? self::STATUS_ABSENT,
            self::FIELD_STATUS => $status,
            self::FIELD_VERIFIED => $verified,
            self::FIELD_VERIFIED_BASIS => $verifiedBasis,
            self::FIELD_VERIFIED_SOURCE_PRESENT => $verifiedSourcePresent,
            self::FIELD_CERTIFIED_RECEIPT_ID => $contract[self::FIELD_CERTIFIED_RECEIPT_ID] ?? $native[self::FIELD_CERTIFIED_RECEIPT_ID] ?? null,
            self::FIELD_EVIDENCE_REF_COUNT => (int) ($contract[self::FIELD_EVIDENCE_REF_COUNT] ?? count($evidenceRefs)),
            self::FIELD_EPISODE_ID => $episodeId !== '' ? $episodeId : null,
            self::FIELD_RUN_ID => $runId !== '' ? $runId : null,
        ], [
            self::FIELD_OUTCOME_TYPE => (AiValueNormalizer::trimmedStringOrNull($native[self::FIELD_OUTCOME_TYPE] ?? null) ?? self::FIELD_ENGINEERING_DELIVERY),
            self::FIELD_SUMMARY => AiValueNormalizer::trimmedStringOrNull($native[self::FIELD_SUMMARY] ?? $native[self::FIELD_OBJECTIVE] ?? null) ?? '',
            self::FIELD_METRICS => AiValueNormalizer::arrayOrEmpty($native[self::FIELD_METRICS] ?? null),
            self::FIELD_BLOCKERS => AiValueNormalizer::arrayOrEmpty($native[self::FIELD_BLOCKERS] ?? null),
            self::FIELD_CONTEXT_UTILITY => AiValueNormalizer::arrayOrEmpty($native[self::FIELD_CONTEXT_UTILITY] ?? null),
            self::FIELD_PATCH_OUTCOME => AiValueNormalizer::arrayOrEmpty($native[self::FIELD_PATCH_OUTCOME] ?? null),
            self::FIELD_LEARNING_CLAIM => (AiValueNormalizer::trimmedStringOrNull($native[self::FIELD_LEARNING_CLAIM] ?? null) ?? ''),
        ]);
    }

    public function fromEnvelope(OutcomeEnvelope $envelope): array
    {
        $data = $envelope->toArray();
        $fields = AiValueNormalizer::arrayOrEmpty($data[self::FIELD_NATIVE_DIVERGENT][self::FIELD_FIELDS] ?? null);

        return [
            self::FIELD_EXECUTOR => (AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_EXECUTOR] ?? null) ?? self::FIELD_ENGINEERING),
            self::FIELD_STATUS => (AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_STATUS] ?? null) ?? OutcomeEnvelope::STATUS_BLOCKED),
            self::FIELD_OBJECTIVE => (AiValueNormalizer::trimmedStringOrNull($fields[self::FIELD_SUMMARY] ?? null) ?? ''),
            self::FIELD_SUMMARY => (AiValueNormalizer::trimmedStringOrNull($fields[self::FIELD_SUMMARY] ?? null) ?? ''),
            self::FIELD_OUTCOME_TYPE => (AiValueNormalizer::trimmedStringOrNull($fields[self::FIELD_OUTCOME_TYPE] ?? null) ?? self::FIELD_ENGINEERING_DELIVERY),
            self::FIELD_METRICS => AiValueNormalizer::arrayOrEmpty($fields[self::FIELD_METRICS] ?? null),
            self::FIELD_BLOCKERS => AiValueNormalizer::arrayOrEmpty($fields[self::FIELD_BLOCKERS] ?? null),
            self::FIELD_CONTEXT_UTILITY => AiValueNormalizer::arrayOrEmpty($fields[self::FIELD_CONTEXT_UTILITY] ?? null),
            self::FIELD_PATCH_OUTCOME => AiValueNormalizer::arrayOrEmpty($fields[self::FIELD_PATCH_OUTCOME] ?? null),
            self::FIELD_LEARNING_CLAIM => (AiValueNormalizer::trimmedStringOrNull($fields[self::FIELD_LEARNING_CLAIM] ?? null) ?? ''),
            self::FIELD_VERIFIED => (AiValueNormalizer::boolOrNull($data[self::FIELD_VERIFIED] ?? null) ?? false),
            self::FIELD_VERIFIED_BASIS => AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_VERIFIED_BASIS] ?? null) ?? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT,
        ];
    }
}
