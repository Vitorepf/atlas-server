<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;

/**
 * ESP-06 — Compounding evaluator ({@see AtlasCompoundingOutcomeEvaluator}) adapter.
 */
final class CompoundingOutcomeEnvelopeAdapter implements OutcomeEnvelopeAdapter
{
    public function origin(): string
    {
        return 'compounding';
    }

    /**
     * @param  array<string,mixed>  $native  evaluator input and/or persisted outcome row
     * @param  array<string,mixed>  $context
     */
    public function toEnvelope(array $native, array $context = []): OutcomeEnvelope
    {
        $flowId = strtolower(trim((string) ($native['flow_id'] ?? 'atlas_conversation')));
        $executor = match (true) {
            str_contains($flowId, 'forge') => 'forge',
            str_contains($flowId, 'autonomos') => 'autonomos',
            str_contains($flowId, 'dev') => 'dev',
            default => 'engineering',
        };

        $outcomeStatus = strtolower(trim((string) ($native['outcome_status'] ?? $native['status'] ?? 'passed')));
        $status = OutcomeEnvelope::normalizeStatus($outcomeStatus);

        $verifiedSourcePresent = array_key_exists('verified', $native)
            || is_array($native['payload']['outcome_contract_v2'] ?? null);
        $contract = is_array($native['payload']['outcome_contract_v2'] ?? null)
            ? $native['payload']['outcome_contract_v2']
            : [];
        $verifiedBasis = strtolower(trim((string) (
            $contract['verified_basis']
            ?? $native['verified_basis']
            ?? ($verifiedSourcePresent && ($native['verified'] ?? false) === true
                ? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_GATES_PASSED
                : AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT)
        )));
        $verified = (bool) ($contract['verified'] ?? $native['verified'] ?? false);
        $evidenceRefs = is_array($native['evidence_refs'] ?? null) ? $native['evidence_refs'] : [];

        return OutcomeEnvelope::fromArray([
            'schema_version' => OutcomeEnvelope::SCHEMA_VERSION,
            'formula_version' => OutcomeEnvelope::FORMULA_VERSION,
            'adapter_origin' => $this->origin(),
            'executor' => $executor,
            'task_category' => (string) ($contract['task_category'] ?? $executor),
            'provider' => (string) ($contract['provider'] ?? $native['provider'] ?? 'absent'),
            'status' => $status,
            'verified' => $verified,
            'verified_basis' => $verifiedBasis,
            'verified_source_present' => $verifiedSourcePresent,
            'certified_receipt_id' => $contract['certified_receipt_id'] ?? null,
            'evidence_ref_count' => count($evidenceRefs),
            'episode_id' => null,
            'run_id' => trim((string) ($native['run_id'] ?? '')) ?: null,
            'native_divergent' => [
                'origin' => $this->origin(),
                'fields' => [
                    'flow_id' => $flowId,
                    'flow_quality' => $native['flow_quality'] ?? null,
                    'retrieval_quality' => $native['retrieval_quality'] ?? null,
                    'execution_quality' => $native['execution_quality'] ?? null,
                    'evidence_quality' => $native['evidence_quality'] ?? null,
                    'learning_required' => (bool) ($native['learning_required'] ?? false),
                    'missed_signals' => is_array($native['missed_signals'] ?? null) ? $native['missed_signals'] : [],
                    'human_override' => (bool) ($native['human_override'] ?? false),
                ],
            ],
        ]);
    }

    public function fromEnvelope(OutcomeEnvelope $envelope): array
    {
        $data = $envelope->toArray();
        $fields = (array) ($data['native_divergent']['fields'] ?? []);

        return [
            'flow_id' => (string) ($fields['flow_id'] ?? 'atlas_conversation'),
            'run_id' => (string) ($data['run_id'] ?? ''),
            'outcome_status' => match ((string) ($data['status'] ?? 'blocked')) {
                'succeeded' => 'passed',
                'failed' => 'failed',
                default => 'blocked',
            },
            'flow_quality' => $fields['flow_quality'] ?? null,
            'retrieval_quality' => $fields['retrieval_quality'] ?? null,
            'execution_quality' => $fields['execution_quality'] ?? null,
            'evidence_quality' => $fields['evidence_quality'] ?? null,
            'learning_required' => (bool) ($fields['learning_required'] ?? false),
            'missed_signals' => is_array($fields['missed_signals'] ?? null) ? $fields['missed_signals'] : [],
            'human_override' => (bool) ($fields['human_override'] ?? false),
            'verified' => (bool) ($data['verified'] ?? false),
            'verified_basis' => (string) ($data['verified_basis'] ?? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT),
        ];
    }
}
