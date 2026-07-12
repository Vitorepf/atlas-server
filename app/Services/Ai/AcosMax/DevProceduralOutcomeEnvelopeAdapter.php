<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;

/**
 * ESP-06 — Dev procedural memory ({@see DevOutcomeMemoryService}) adapter.
 */
final class DevProceduralOutcomeEnvelopeAdapter implements OutcomeEnvelopeAdapter
{
    public function origin(): string
    {
        return 'dev_procedural';
    }

    /** @param array<string,mixed> $native @param array<string,mixed> $context */
    public function toEnvelope(array $native, array $context = []): OutcomeEnvelope
    {
        $status = $this->mapStatus((string) ($native['outcome_status'] ?? 'needs_review'));
        $provenReal = (bool) ($native['proven_real'] ?? false);
        $fakeGreen = (bool) ($native['fake_green'] ?? false);
        $verifiedSourcePresent = array_key_exists('proven_real', $native);
        $verifiedBasis = $this->deriveVerifiedBasis($provenReal, $fakeGreen, $verifiedSourcePresent);
        $verified = $provenReal && ! $fakeGreen && in_array($verifiedBasis, AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASES_WEIGHTED, true);
        $evidenceKinds = is_array($native['evidence_kinds'] ?? null) ? $native['evidence_kinds'] : [];

        return OutcomeEnvelope::fromArray([
            'schema_version' => OutcomeEnvelope::SCHEMA_VERSION,
            'formula_version' => OutcomeEnvelope::FORMULA_VERSION,
            'adapter_origin' => $this->origin(),
            'executor' => 'dev',
            'task_category' => (string) ($context['task_category'] ?? 'dev'),
            'provider' => strtolower(trim((string) ($context['provider'] ?? 'absent'))) ?: 'absent',
            'status' => $status,
            'verified' => $verified,
            'verified_basis' => $verifiedBasis,
            'verified_source_present' => $verifiedSourcePresent,
            'certified_receipt_id' => $context['certified_receipt_id'] ?? null,
            'evidence_ref_count' => count($evidenceKinds),
            'episode_id' => null,
            'run_id' => trim((string) ($native['run_id'] ?? '')) ?: null,
            'native_divergent' => [
                'origin' => $this->origin(),
                'fields' => [
                    'outcome_status' => (string) ($native['outcome_status'] ?? ''),
                    'proven_real' => $provenReal,
                    'fake_green' => $fakeGreen,
                    'proof_reason' => (string) ($native['proof_reason'] ?? ''),
                    'evidence_kinds' => $evidenceKinds,
                    'selected_tests' => is_array($native['selected_tests'] ?? null) ? $native['selected_tests'] : [],
                    'changed_files' => is_array($native['changed_files'] ?? null) ? $native['changed_files'] : [],
                    'learning_candidates' => is_array($native['learning_candidates'] ?? null) ? $native['learning_candidates'] : [],
                    'should_promote_to_aemor' => (bool) ($native['should_promote_to_aemor'] ?? false),
                ],
            ],
        ]);
    }

    public function fromEnvelope(OutcomeEnvelope $envelope): array
    {
        $data = $envelope->toArray();
        $fields = (array) ($data['native_divergent']['fields'] ?? []);

        return [
            'schema_version' => 'atlas.dev.outcome_memory.v1',
            'run_id' => (string) ($data['run_id'] ?? 'unknown'),
            'outcome_status' => (string) ($fields['outcome_status'] ?? $this->reverseStatus((string) ($data['status'] ?? 'blocked'))),
            'proven_real' => (bool) ($fields['proven_real'] ?? false),
            'fake_green' => (bool) ($fields['fake_green'] ?? false),
            'proof_reason' => (string) ($fields['proof_reason'] ?? ''),
            'evidence_kinds' => is_array($fields['evidence_kinds'] ?? null) ? $fields['evidence_kinds'] : [],
            'selected_tests' => is_array($fields['selected_tests'] ?? null) ? $fields['selected_tests'] : [],
            'changed_files' => is_array($fields['changed_files'] ?? null) ? $fields['changed_files'] : [],
            'learning_candidates' => is_array($fields['learning_candidates'] ?? null) ? $fields['learning_candidates'] : [],
            'should_promote_to_aemor' => (bool) ($fields['should_promote_to_aemor'] ?? false),
        ];
    }

    private function mapStatus(string $nativeStatus): string
    {
        return match ($nativeStatus) {
            'success', 'succeeded', 'passed' => 'succeeded',
            'failed', 'failure' => 'failed',
            default => 'blocked',
        };
    }

    private function reverseStatus(string $envelopeStatus): string
    {
        return match ($envelopeStatus) {
            'succeeded' => 'success',
            'failed' => 'failed',
            default => 'blocked',
        };
    }

    private function deriveVerifiedBasis(bool $provenReal, bool $fakeGreen, bool $sourcePresent): string
    {
        if (! $sourcePresent) {
            return AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT;
        }
        if ($fakeGreen) {
            return AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_CLAIMED;
        }
        if ($provenReal) {
            return AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_GATES_PASSED;
        }

        return AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT;
    }
}
