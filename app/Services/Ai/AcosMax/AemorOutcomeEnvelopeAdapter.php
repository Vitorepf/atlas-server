<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;

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
        $executor = strtolower(trim((string) ($native['executor'] ?? $context['executor'] ?? 'engineering')));
        $status = OutcomeEnvelope::normalizeStatus((string) ($native['status'] ?? 'blocked'));

        $contract = is_array($context['outcome_contract_v2'] ?? null)
            ? $context['outcome_contract_v2']
            : (is_array($native['outcome_contract_v2'] ?? null) ? $native['outcome_contract_v2'] : []);

        $verifiedSourcePresent = array_key_exists('verified', $native)
            || array_key_exists('verified_source_present', $contract);
        $verifiedBasis = strtolower(trim((string) ($contract['verified_basis'] ?? $native['verified_basis'] ?? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT)));
        $verified = (bool) ($contract['verified'] ?? $native['verified'] ?? false);
        $evidenceRefs = is_array($native['evidence_refs'] ?? null) ? $native['evidence_refs'] : [];

        $episodeId = trim((string) ($context['episode_id'] ?? $native['episode_id'] ?? ''));

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
            'run_id' => trim((string) ($native['run_id'] ?? $native['scope_id'] ?? '')) ?: null,
        ], [
            'outcome_type' => (string) ($native['outcome_type'] ?? 'engineering_delivery'),
            'summary' => (string) ($native['summary'] ?? $native['objective'] ?? ''),
            'metrics' => is_array($native['metrics'] ?? null) ? $native['metrics'] : [],
            'blockers' => is_array($native['blockers'] ?? null) ? $native['blockers'] : [],
            'context_utility' => is_array($native['context_utility'] ?? null) ? $native['context_utility'] : [],
            'patch_outcome' => is_array($native['patch_outcome'] ?? null) ? $native['patch_outcome'] : [],
            'learning_claim' => (string) ($native['learning_claim'] ?? ''),
        ]);
    }

    public function fromEnvelope(OutcomeEnvelope $envelope): array
    {
        $data = $envelope->toArray();
        $fields = (array) ($data['native_divergent']['fields'] ?? []);

        return [
            'executor' => (string) ($data['executor'] ?? 'engineering'),
            'status' => (string) ($data['status'] ?? 'blocked'),
            'objective' => (string) ($fields['summary'] ?? ''),
            'summary' => (string) ($fields['summary'] ?? ''),
            'outcome_type' => (string) ($fields['outcome_type'] ?? 'engineering_delivery'),
            'metrics' => is_array($fields['metrics'] ?? null) ? $fields['metrics'] : [],
            'blockers' => is_array($fields['blockers'] ?? null) ? $fields['blockers'] : [],
            'context_utility' => is_array($fields['context_utility'] ?? null) ? $fields['context_utility'] : [],
            'patch_outcome' => is_array($fields['patch_outcome'] ?? null) ? $fields['patch_outcome'] : [],
            'learning_claim' => (string) ($fields['learning_claim'] ?? ''),
            'verified' => (bool) ($data['verified'] ?? false),
            'verified_basis' => (string) ($data['verified_basis'] ?? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT),
        ];
    }
}
