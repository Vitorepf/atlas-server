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
        $executor = AiValueNormalizer::lowerTrimmedString($native[self::FIELD_EXECUTOR] ?? $context[self::FIELD_EXECUTOR] ?? 'engineering');
        $status = OutcomeEnvelope::normalizeStatus((AiValueNormalizer::trimmedStringOrNull($native[self::FIELD_STATUS] ?? null) ?? OutcomeEnvelope::STATUS_BLOCKED));

        $contract = is_array($context['outcome_contract_v2'] ?? null)
            ? $context['outcome_contract_v2']
            : AiValueNormalizer::arrayOrEmpty($native['outcome_contract_v2'] ?? null);

        $verifiedSourcePresent = array_key_exists(self::FIELD_VERIFIED, $native)
            || array_key_exists('verified_source_present', $contract);
        $verifiedBasis = AiValueNormalizer::lowerTrimmedString($contract[self::FIELD_VERIFIED_BASIS] ?? $native[self::FIELD_VERIFIED_BASIS] ?? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT);
        $verified = (AiValueNormalizer::boolOrNull($contract[self::FIELD_VERIFIED] ?? $native[self::FIELD_VERIFIED] ?? null) ?? false);
        $evidenceRefs = AiValueNormalizer::arrayOrEmpty($native['evidence_refs'] ?? null);

        $episodeId = AiValueNormalizer::trimmedStringOrNull($context['episode_id'] ?? $native['episode_id'] ?? null) ?? '';
        $runId = AiValueNormalizer::trimmedStringOrNull($native['run_id'] ?? $native['scope_id'] ?? null) ?? '';

        return OutcomeEnvelope::fromAdapter($this->origin(), [
            self::FIELD_EXECUTOR => in_array($executor, ['dev', 'forge', 'autonomos'], true) ? $executor : 'engineering',
            'task_category' => AiValueNormalizer::trimmedStringOrNull($contract['task_category'] ?? $native['task_category'] ?? $executor) ?? '',
            'provider' => AiValueNormalizer::trimmedStringOrNull($contract['provider'] ?? $native['provider'] ?? null) ?? self::STATUS_ABSENT,
            self::FIELD_STATUS => $status,
            self::FIELD_VERIFIED => $verified,
            self::FIELD_VERIFIED_BASIS => $verifiedBasis,
            'verified_source_present' => $verifiedSourcePresent,
            'certified_receipt_id' => $contract['certified_receipt_id'] ?? $native['certified_receipt_id'] ?? null,
            'evidence_ref_count' => (int) ($contract['evidence_ref_count'] ?? count($evidenceRefs)),
            'episode_id' => $episodeId !== '' ? $episodeId : null,
            'run_id' => $runId !== '' ? $runId : null,
        ], [
            self::FIELD_OUTCOME_TYPE => (AiValueNormalizer::trimmedStringOrNull($native[self::FIELD_OUTCOME_TYPE] ?? null) ?? 'engineering_delivery'),
            self::FIELD_SUMMARY => AiValueNormalizer::trimmedStringOrNull($native[self::FIELD_SUMMARY] ?? $native['objective'] ?? null) ?? '',
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
        $fields = AiValueNormalizer::arrayOrEmpty($data['native_divergent']['fields'] ?? null);

        return [
            self::FIELD_EXECUTOR => (AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_EXECUTOR] ?? null) ?? 'engineering'),
            self::FIELD_STATUS => (AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_STATUS] ?? null) ?? OutcomeEnvelope::STATUS_BLOCKED),
            'objective' => (AiValueNormalizer::trimmedStringOrNull($fields[self::FIELD_SUMMARY] ?? null) ?? ''),
            self::FIELD_SUMMARY => (AiValueNormalizer::trimmedStringOrNull($fields[self::FIELD_SUMMARY] ?? null) ?? ''),
            self::FIELD_OUTCOME_TYPE => (AiValueNormalizer::trimmedStringOrNull($fields[self::FIELD_OUTCOME_TYPE] ?? null) ?? 'engineering_delivery'),
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
