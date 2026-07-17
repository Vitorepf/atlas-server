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
        $executor = AiValueNormalizer::lowerTrimmedString($native['executor'] ?? $context['executor'] ?? 'engineering');
        $status = OutcomeEnvelope::normalizeStatus((AiValueNormalizer::trimmedStringOrNull($native['status'] ?? null) ?? OutcomeEnvelope::STATUS_BLOCKED));

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
            'executor' => in_array($executor, ['dev', 'forge', 'autonomos'], true) ? $executor : 'engineering',
            'task_category' => AiValueNormalizer::trimmedStringOrNull($contract['task_category'] ?? $native['task_category'] ?? $executor) ?? '',
            'provider' => AiValueNormalizer::trimmedStringOrNull($contract['provider'] ?? $native['provider'] ?? null) ?? self::STATUS_ABSENT,
            'status' => $status,
            self::FIELD_VERIFIED => $verified,
            self::FIELD_VERIFIED_BASIS => $verifiedBasis,
            'verified_source_present' => $verifiedSourcePresent,
            'certified_receipt_id' => $contract['certified_receipt_id'] ?? $native['certified_receipt_id'] ?? null,
            'evidence_ref_count' => (int) ($contract['evidence_ref_count'] ?? count($evidenceRefs)),
            'episode_id' => $episodeId !== '' ? $episodeId : null,
            'run_id' => $runId !== '' ? $runId : null,
        ], [
            'outcome_type' => (AiValueNormalizer::trimmedStringOrNull($native['outcome_type'] ?? null) ?? 'engineering_delivery'),
            'summary' => AiValueNormalizer::trimmedStringOrNull($native['summary'] ?? $native['objective'] ?? null) ?? '',
            'metrics' => AiValueNormalizer::arrayOrEmpty($native['metrics'] ?? null),
            'blockers' => AiValueNormalizer::arrayOrEmpty($native['blockers'] ?? null),
            'context_utility' => AiValueNormalizer::arrayOrEmpty($native['context_utility'] ?? null),
            'patch_outcome' => AiValueNormalizer::arrayOrEmpty($native['patch_outcome'] ?? null),
            'learning_claim' => (AiValueNormalizer::trimmedStringOrNull($native['learning_claim'] ?? null) ?? ''),
        ]);
    }

    public function fromEnvelope(OutcomeEnvelope $envelope): array
    {
        $data = $envelope->toArray();
        $fields = AiValueNormalizer::arrayOrEmpty($data['native_divergent']['fields'] ?? null);

        return [
            'executor' => (AiValueNormalizer::trimmedStringOrNull($data['executor'] ?? null) ?? 'engineering'),
            'status' => (AiValueNormalizer::trimmedStringOrNull($data['status'] ?? null) ?? OutcomeEnvelope::STATUS_BLOCKED),
            'objective' => (AiValueNormalizer::trimmedStringOrNull($fields['summary'] ?? null) ?? ''),
            'summary' => (AiValueNormalizer::trimmedStringOrNull($fields['summary'] ?? null) ?? ''),
            'outcome_type' => (AiValueNormalizer::trimmedStringOrNull($fields['outcome_type'] ?? null) ?? 'engineering_delivery'),
            'metrics' => AiValueNormalizer::arrayOrEmpty($fields['metrics'] ?? null),
            'blockers' => AiValueNormalizer::arrayOrEmpty($fields['blockers'] ?? null),
            'context_utility' => AiValueNormalizer::arrayOrEmpty($fields['context_utility'] ?? null),
            'patch_outcome' => AiValueNormalizer::arrayOrEmpty($fields['patch_outcome'] ?? null),
            'learning_claim' => (AiValueNormalizer::trimmedStringOrNull($fields['learning_claim'] ?? null) ?? ''),
            self::FIELD_VERIFIED => (AiValueNormalizer::boolOrNull($data[self::FIELD_VERIFIED] ?? null) ?? false),
            self::FIELD_VERIFIED_BASIS => AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_VERIFIED_BASIS] ?? null) ?? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT,
        ];
    }
}
