<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * ESP-06 — Dev procedural memory ({@see DevOutcomeMemoryService}) adapter.
 */
final class DevProceduralOutcomeEnvelopeAdapter implements OutcomeEnvelopeAdapter
{
    public const ADAPTER_KIND = 'dev_procedural';

    public const NATIVE_SCHEMA_VERSION = 'atlas.dev.outcome_memory.v1';

    public function origin(): string
    {
        return self::ADAPTER_KIND;
    }

    /** @param array<string,mixed> $native @param array<string,mixed> $context */
    public function toEnvelope(array $native, array $context = []): OutcomeEnvelope
    {
        $status = OutcomeEnvelope::normalizeStatus((AiValueNormalizer::trimmedStringOrNull($native['outcome_status'] ?? null) ?? 'needs_review'));
        $provenReal = (bool) ($native['proven_real'] ?? false);
        $fakeGreen = (bool) ($native['fake_green'] ?? false);
        $verifiedSourcePresent = array_key_exists('proven_real', $native);
        $verifiedBasis = $this->deriveVerifiedBasis($provenReal, $fakeGreen, $verifiedSourcePresent);
        $verified = $provenReal && ! $fakeGreen && in_array($verifiedBasis, AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASES_WEIGHTED, true);
        $evidenceKinds = AiValueNormalizer::arrayOrEmpty($native['evidence_kinds'] ?? null);
        $runId = AiValueNormalizer::trimmedStringOrNull($native['run_id'] ?? null) ?? '';

        return OutcomeEnvelope::fromAdapter($this->origin(), [
            'executor' => 'dev',
            'task_category' => (AiValueNormalizer::trimmedStringOrNull($context['task_category'] ?? null) ?? 'dev'),
            'provider' => AiValueNormalizer::lowerTrimmedString($context['provider'] ?? 'absent') ?: 'absent',
            'status' => $status,
            'verified' => $verified,
            'verified_basis' => $verifiedBasis,
            'verified_source_present' => $verifiedSourcePresent,
            'certified_receipt_id' => $context['certified_receipt_id'] ?? null,
            'evidence_ref_count' => count($evidenceKinds),
            'episode_id' => null,
            'run_id' => $runId !== '' ? $runId : null,
        ], [
            'outcome_status' => (AiValueNormalizer::trimmedStringOrNull($native['outcome_status'] ?? null) ?? ''),
            'proven_real' => $provenReal,
            'fake_green' => $fakeGreen,
            'proof_reason' => (AiValueNormalizer::trimmedStringOrNull($native['proof_reason'] ?? null) ?? ''),
            'evidence_kinds' => $evidenceKinds,
            'selected_tests' => AiValueNormalizer::arrayOrEmpty($native['selected_tests'] ?? null),
            'changed_files' => AiValueNormalizer::arrayOrEmpty($native['changed_files'] ?? null),
            'learning_candidates' => AiValueNormalizer::arrayOrEmpty($native['learning_candidates'] ?? null),
            'should_promote_to_aemor' => (bool) ($native['should_promote_to_aemor'] ?? false),
        ]);
    }

    public function fromEnvelope(OutcomeEnvelope $envelope): array
    {
        $data = $envelope->toArray();
        $fields = AiValueNormalizer::arrayOrEmpty($data['native_divergent']['fields'] ?? null);

        return [
            'schema_version' => self::NATIVE_SCHEMA_VERSION,
            'run_id' => (AiValueNormalizer::trimmedStringOrNull($data['run_id'] ?? null) ?? 'unknown'),
            'outcome_status' => (string) ($fields['outcome_status'] ?? OutcomeEnvelope::toNativeStatus((AiValueNormalizer::trimmedStringOrNull($data['status'] ?? null) ?? 'blocked'), $this->origin())),
            'proven_real' => (bool) ($fields['proven_real'] ?? false),
            'fake_green' => (bool) ($fields['fake_green'] ?? false),
            'proof_reason' => (AiValueNormalizer::trimmedStringOrNull($fields['proof_reason'] ?? null) ?? ''),
            'evidence_kinds' => AiValueNormalizer::arrayOrEmpty($fields['evidence_kinds'] ?? null),
            'selected_tests' => AiValueNormalizer::arrayOrEmpty($fields['selected_tests'] ?? null),
            'changed_files' => AiValueNormalizer::arrayOrEmpty($fields['changed_files'] ?? null),
            'learning_candidates' => AiValueNormalizer::arrayOrEmpty($fields['learning_candidates'] ?? null),
            'should_promote_to_aemor' => (bool) ($fields['should_promote_to_aemor'] ?? false),
        ];
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
