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

    public const FALLBACK_RUN_ID = 'unknown';

    public const NATIVE_NEEDS_REVIEW = 'needs_review';

    /** @var list<string> */
    public const BOOL_FIELDS = ['proven_real', 'fake_green', 'should_promote_to_aemor'];

    public function origin(): string
    {
        return self::ADAPTER_KIND;
    }

    /** @param array<string,mixed> $native @param array<string,mixed> $context */
    public function toEnvelope(array $native, array $context = []): OutcomeEnvelope
    {
        $status = OutcomeEnvelope::normalizeStatus((AiValueNormalizer::trimmedStringOrNull($native['outcome_status'] ?? null) ?? self::NATIVE_NEEDS_REVIEW));
        $provenReal = (AiValueNormalizer::boolOrNull($native['proven_real'] ?? null) ?? false);
        $fakeGreen = (AiValueNormalizer::boolOrNull($native['fake_green'] ?? null) ?? false);
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
            'should_promote_to_aemor' => (AiValueNormalizer::boolOrNull($native['should_promote_to_aemor'] ?? null) ?? false),
        ]);
    }

    public function fromEnvelope(OutcomeEnvelope $envelope): array
    {
        $data = $envelope->toArray();
        $fields = AiValueNormalizer::arrayOrEmpty($data['native_divergent']['fields'] ?? null);

        return [
            'schema_version' => self::NATIVE_SCHEMA_VERSION,
            'run_id' => (AiValueNormalizer::trimmedStringOrNull($data['run_id'] ?? null) ?? self::FALLBACK_RUN_ID),
            'outcome_status' => AiValueNormalizer::trimmedStringOrNull($fields['outcome_status'] ?? null) ?? OutcomeEnvelope::toNativeStatus((AiValueNormalizer::trimmedStringOrNull($data['status'] ?? null) ?? OutcomeEnvelope::STATUS_BLOCKED), $this->origin()),
            'proven_real' => (AiValueNormalizer::boolOrNull($fields['proven_real'] ?? null) ?? false),
            'fake_green' => (AiValueNormalizer::boolOrNull($fields['fake_green'] ?? null) ?? false),
            'proof_reason' => (AiValueNormalizer::trimmedStringOrNull($fields['proof_reason'] ?? null) ?? ''),
            'evidence_kinds' => AiValueNormalizer::arrayOrEmpty($fields['evidence_kinds'] ?? null),
            'selected_tests' => AiValueNormalizer::arrayOrEmpty($fields['selected_tests'] ?? null),
            'changed_files' => AiValueNormalizer::arrayOrEmpty($fields['changed_files'] ?? null),
            'learning_candidates' => AiValueNormalizer::arrayOrEmpty($fields['learning_candidates'] ?? null),
            'should_promote_to_aemor' => (AiValueNormalizer::boolOrNull($fields['should_promote_to_aemor'] ?? null) ?? false),
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
