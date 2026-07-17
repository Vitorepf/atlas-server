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
    public function origin(): string
    {
        return 'aemor';
    }

    /**
     * @param  array<string,mixed>  $native  recorder input and/or closed outcome payload
     * @param  array<string,mixed>  $context
     */
    public function toEnvelope(array $native, array $context = []): OutcomeEnvelope
    {
        $executor = AiValueNormalizer::lowerTrimmedString($native['executor'] ?? $context['executor'] ?? 'engineering');
        $status = OutcomeEnvelope::normalizeStatus((string) ($native['status'] ?? 'blocked'));

        $contract = is_array($context['outcome_contract_v2'] ?? null)
            ? $context['outcome_contract_v2']
            : AiValueNormalizer::arrayOrEmpty($native['outcome_contract_v2'] ?? null);

        $verifiedSourcePresent = array_key_exists('verified', $native)
            || array_key_exists('verified_source_present', $contract);
        $verifiedBasis = AiValueNormalizer::lowerTrimmedString($contract['verified_basis'] ?? $native['verified_basis'] ?? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT);
        $verified = (bool) ($contract['verified'] ?? $native['verified'] ?? false);
        $evidenceRefs = AiValueNormalizer::arrayOrEmpty($native['evidence_refs'] ?? null);

        $episodeId = AiValueNormalizer::trimmedStringOrNull($context['episode_id'] ?? $native['episode_id'] ?? null) ?? '';
        $runId = AiValueNormalizer::trimmedStringOrNull($native['run_id'] ?? $native['scope_id'] ?? null) ?? '';

        return OutcomeEnvelope::fromAdapter($this->origin(), [
            'executor' => in_array($executor, ['dev', 'forge', 'autonomos'], true) ? $executor : 'engineering',
            'task_category' => (string) ($contract['task_category'] ?? $native['task_category'] ?? $executor),
            'provider' => (string) ($contract['provider'] ?? $native['provider'] ?? 'absent'),
            'status' => $status,
            'verified' => $verified,
            'verified_basis' => $verifiedBasis,
            'verified_source_present' => $verifiedSourcePresent,
            'certified_receipt_id' => $contract['certified_receipt_id'] ?? $native['certified_receipt_id'] ?? null,
            'evidence_ref_count' => (int) ($contract['evidence_ref_count'] ?? count($evidenceRefs)),
            'episode_id' => $episodeId !== '' ? $episodeId : null,
            'run_id' => $runId !== '' ? $runId : null,
        ], [
            'outcome_type' => (string) ($native['outcome_type'] ?? 'engineering_delivery'),
            'summary' => (string) ($native['summary'] ?? $native['objective'] ?? ''),
            'metrics' => AiValueNormalizer::arrayOrEmpty($native['metrics'] ?? null),
            'blockers' => AiValueNormalizer::arrayOrEmpty($native['blockers'] ?? null),
            'context_utility' => AiValueNormalizer::arrayOrEmpty($native['context_utility'] ?? null),
            'patch_outcome' => AiValueNormalizer::arrayOrEmpty($native['patch_outcome'] ?? null),
            'learning_claim' => (string) ($native['learning_claim'] ?? ''),
        ]);
    }

    public function fromEnvelope(OutcomeEnvelope $envelope): array
    {
        $data = $envelope->toArray();
        $fields = AiValueNormalizer::arrayOrEmpty($data['native_divergent']['fields'] ?? null);

        return [
            'executor' => (string) ($data['executor'] ?? 'engineering'),
            'status' => (string) ($data['status'] ?? 'blocked'),
            'objective' => (string) ($fields['summary'] ?? ''),
            'summary' => (string) ($fields['summary'] ?? ''),
            'outcome_type' => (string) ($fields['outcome_type'] ?? 'engineering_delivery'),
            'metrics' => AiValueNormalizer::arrayOrEmpty($fields['metrics'] ?? null),
            'blockers' => AiValueNormalizer::arrayOrEmpty($fields['blockers'] ?? null),
            'context_utility' => AiValueNormalizer::arrayOrEmpty($fields['context_utility'] ?? null),
            'patch_outcome' => AiValueNormalizer::arrayOrEmpty($fields['patch_outcome'] ?? null),
            'learning_claim' => (string) ($fields['learning_claim'] ?? ''),
            'verified' => (bool) ($data['verified'] ?? false),
            'verified_basis' => (string) ($data['verified_basis'] ?? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT),
        ];
    }
}
