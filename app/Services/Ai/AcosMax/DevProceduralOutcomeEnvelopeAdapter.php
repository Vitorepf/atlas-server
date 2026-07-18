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

    public const STATUS_ABSENT = 'absent';

    public const FIELD_VERIFIED = 'verified';

    public const FIELD_PROVEN_REAL = 'proven_real';

    public const FIELD_FAKE_GREEN = 'fake_green';

    public const FIELD_SHOULD_PROMOTE_TO_AEMOR = 'should_promote_to_aemor';
    public const FIELD_OUTCOME_STATUS = 'outcome_status';
    public const FIELD_SELECTED_TESTS = 'selected_tests';
    public const FIELD_RUN_ID = 'run_id';
    public const FIELD_PROOF_REASON = 'proof_reason';
    public const FIELD_LEARNING_CANDIDATES = 'learning_candidates';
    public const FIELD_EVIDENCE_KINDS = 'evidence_kinds';
    public const FIELD_CHANGED_FILES = 'changed_files';
    public const FIELD_STATUS = 'status';
    public const FIELD_CERTIFIED_RECEIPT_ID = 'certified_receipt_id';
    public const FIELD_PROVIDER = 'provider';
    public const FIELD_TASK_CATEGORY = 'task_category';
    public const FIELD_EXECUTOR = 'executor';
    public const FIELD_VERIFIED_BASIS = 'verified_basis';

    /** @var list<string> */
    public const BOOL_FIELDS = [self::FIELD_PROVEN_REAL, self::FIELD_FAKE_GREEN, self::FIELD_SHOULD_PROMOTE_TO_AEMOR];

    public function origin(): string
    {
        return self::ADAPTER_KIND;
    }

    /** @param array<string,mixed> $native @param array<string,mixed> $context */
    public function toEnvelope(array $native, array $context = []): OutcomeEnvelope
    {
        $status = OutcomeEnvelope::normalizeStatus((AiValueNormalizer::trimmedStringOrNull($native[self::FIELD_OUTCOME_STATUS] ?? null) ?? self::NATIVE_NEEDS_REVIEW));
        $provenReal = (AiValueNormalizer::boolOrNull($native[self::FIELD_PROVEN_REAL] ?? null) ?? false);
        $fakeGreen = (AiValueNormalizer::boolOrNull($native[self::FIELD_FAKE_GREEN] ?? null) ?? false);
        $verifiedSourcePresent = array_key_exists(self::FIELD_PROVEN_REAL, $native);
        $verifiedBasis = $this->deriveVerifiedBasis($provenReal, $fakeGreen, $verifiedSourcePresent);
        $verified = $provenReal && ! $fakeGreen && in_array($verifiedBasis, AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASES_WEIGHTED, true);
        $evidenceKinds = AiValueNormalizer::arrayOrEmpty($native[self::FIELD_EVIDENCE_KINDS] ?? null);
        $runId = AiValueNormalizer::trimmedStringOrNull($native[self::FIELD_RUN_ID] ?? null) ?? '';

        return OutcomeEnvelope::fromAdapter($this->origin(), [
            self::FIELD_EXECUTOR => 'dev',
            self::FIELD_TASK_CATEGORY => (AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_TASK_CATEGORY] ?? null) ?? 'dev'),
            self::FIELD_PROVIDER => AiValueNormalizer::lowerTrimmedString($context[self::FIELD_PROVIDER] ?? self::STATUS_ABSENT) ?: self::STATUS_ABSENT,
            self::FIELD_STATUS => $status,
            self::FIELD_VERIFIED => $verified,
            self::FIELD_VERIFIED_BASIS => $verifiedBasis,
            'verified_source_present' => $verifiedSourcePresent,
            self::FIELD_CERTIFIED_RECEIPT_ID => $context[self::FIELD_CERTIFIED_RECEIPT_ID] ?? null,
            'evidence_ref_count' => count($evidenceKinds),
            'episode_id' => null,
            self::FIELD_RUN_ID => $runId !== '' ? $runId : null,
        ], [
            self::FIELD_OUTCOME_STATUS => (AiValueNormalizer::trimmedStringOrNull($native[self::FIELD_OUTCOME_STATUS] ?? null) ?? ''),
            self::FIELD_PROVEN_REAL => $provenReal,
            self::FIELD_FAKE_GREEN => $fakeGreen,
            self::FIELD_PROOF_REASON => (AiValueNormalizer::trimmedStringOrNull($native[self::FIELD_PROOF_REASON] ?? null) ?? ''),
            self::FIELD_EVIDENCE_KINDS => $evidenceKinds,
            self::FIELD_SELECTED_TESTS => AiValueNormalizer::arrayOrEmpty($native[self::FIELD_SELECTED_TESTS] ?? null),
            self::FIELD_CHANGED_FILES => AiValueNormalizer::arrayOrEmpty($native[self::FIELD_CHANGED_FILES] ?? null),
            self::FIELD_LEARNING_CANDIDATES => AiValueNormalizer::arrayOrEmpty($native[self::FIELD_LEARNING_CANDIDATES] ?? null),
            self::FIELD_SHOULD_PROMOTE_TO_AEMOR => (AiValueNormalizer::boolOrNull($native[self::FIELD_SHOULD_PROMOTE_TO_AEMOR] ?? null) ?? false),
        ]);
    }

    public function fromEnvelope(OutcomeEnvelope $envelope): array
    {
        $data = $envelope->toArray();
        $fields = AiValueNormalizer::arrayOrEmpty($data['native_divergent']['fields'] ?? null);

        return [
            'schema_version' => self::NATIVE_SCHEMA_VERSION,
            self::FIELD_RUN_ID => (AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_RUN_ID] ?? null) ?? self::FALLBACK_RUN_ID),
            self::FIELD_OUTCOME_STATUS => AiValueNormalizer::trimmedStringOrNull($fields[self::FIELD_OUTCOME_STATUS] ?? null) ?? OutcomeEnvelope::toNativeStatus((AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_STATUS] ?? null) ?? OutcomeEnvelope::STATUS_BLOCKED), $this->origin()),
            self::FIELD_PROVEN_REAL => (AiValueNormalizer::boolOrNull($fields[self::FIELD_PROVEN_REAL] ?? null) ?? false),
            self::FIELD_FAKE_GREEN => (AiValueNormalizer::boolOrNull($fields[self::FIELD_FAKE_GREEN] ?? null) ?? false),
            self::FIELD_PROOF_REASON => (AiValueNormalizer::trimmedStringOrNull($fields[self::FIELD_PROOF_REASON] ?? null) ?? ''),
            self::FIELD_EVIDENCE_KINDS => AiValueNormalizer::arrayOrEmpty($fields[self::FIELD_EVIDENCE_KINDS] ?? null),
            self::FIELD_SELECTED_TESTS => AiValueNormalizer::arrayOrEmpty($fields[self::FIELD_SELECTED_TESTS] ?? null),
            self::FIELD_CHANGED_FILES => AiValueNormalizer::arrayOrEmpty($fields[self::FIELD_CHANGED_FILES] ?? null),
            self::FIELD_LEARNING_CANDIDATES => AiValueNormalizer::arrayOrEmpty($fields[self::FIELD_LEARNING_CANDIDATES] ?? null),
            self::FIELD_SHOULD_PROMOTE_TO_AEMOR => (AiValueNormalizer::boolOrNull($fields[self::FIELD_SHOULD_PROMOTE_TO_AEMOR] ?? null) ?? false),
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
