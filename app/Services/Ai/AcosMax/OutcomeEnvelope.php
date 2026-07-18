<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Support\AiValueNormalizer;
use InvalidArgumentException;

/**
 * ESP-06 — canonical outcome envelope (MULTX-03 v2 contract).
 *
 * One shared shape for Dev procedural, AEMOR and Compounding via thin adapters.
 * Divergent native fields live in {@see native_divergent} and are NEVER coerced.
 */
final class OutcomeEnvelope
{
    public const SCHEMA_VERSION = 'atlas.engineering_outcome.v2';

    public const FORMULA_VERSION = 'esp06.outcome_envelope.v1';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUSES = [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_BLOCKED];

    public const NATIVE_SUCCESS = 'success';

    public const NATIVE_PASSED = 'passed';

    public const NATIVE_FAILURE = 'failure';

    public const ORIGIN_DEV_PROCEDURAL = 'dev_procedural';

    public const ORIGIN_AEMOR = 'aemor';

    public const ORIGIN_COMPOUNDING = 'compounding';

    public const ADAPTER_ORIGINS = [self::ORIGIN_DEV_PROCEDURAL, self::ORIGIN_AEMOR, self::ORIGIN_COMPOUNDING];

    public const FIELD_VERIFIED = 'verified';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_FORMULA_VERSION = 'formula_version';
    public const FIELD_ADAPTER_ORIGIN = 'adapter_origin';
    public const FIELD_NATIVE_DIVERGENT = 'native_divergent';
    public const FIELD_ORIGIN = 'origin';
    public const FIELD_FIELDS = 'fields';
    public const FIELD_VERIFIED_SOURCE_PRESENT = 'verified_source_present';
    public const FIELD_EXECUTOR = 'executor';
    public const FIELD_TASK_CATEGORY = 'task_category';
    public const FIELD_PROVIDER = 'provider';
    public const FIELD_STATUS = 'status';
    public const FIELD_VERIFIED_BASIS = 'verified_basis';
    public const FIELD_CERTIFIED_RECEIPT_ID = 'certified_receipt_id';
    public const FIELD_EVIDENCE_REF_COUNT = 'evidence_ref_count';
    public const FIELD_EPISODE_ID = 'episode_id';
    public const FIELD_RUN_ID = 'run_id';
    public const FIELD_OUTCOME_ENVELOPE_ADAPTER_ORIGIN_INVALID = 'outcome_envelope_adapter_origin_invalid';
    public const FIELD_OUTCOME_ENVELOPE_EPISODE_ID_INVALID = 'outcome_envelope_episode_id_invalid';
    public const FIELD_OUTCOME_ENVELOPE_FORMULA_INVALID = 'outcome_envelope_formula_invalid';
    public const FIELD_OUTCOME_ENVELOPE_IDENTITY_FIELDS_REQUIRED = 'outcome_envelope_identity_fields_required';
    public const FIELD_OUTCOME_ENVELOPE_NATIVE_DIVERGENT_INVALID = 'outcome_envelope_native_divergent_invalid';
    public const FIELD_OUTCOME_ENVELOPE_SCHEMA_INVALID = 'outcome_envelope_schema_invalid';
    public const FIELD_OUTCOME_ENVELOPE_STATUS_INVALID = 'outcome_envelope_status_invalid';
    public const FIELD_OUTCOME_ENVELOPE_VERIFIED_BASIS_INVALID = 'outcome_envelope_verified_basis_invalid';
    public const FIELD_OUTCOME_ENVELOPE_VERIFIED_INVALID = 'outcome_envelope_verified_invalid';

    /**
     * Map divergent native status labels onto the shared envelope statuses.
     * Unknown / review-like values fail closed to blocked (never invent success).
     */
    public static function normalizeStatus(string $nativeStatus): string
    {
        return match (AiValueNormalizer::lowerTrimmedString($nativeStatus)) {
            self::STATUS_SUCCEEDED, self::NATIVE_SUCCESS, self::NATIVE_PASSED => self::STATUS_SUCCEEDED,
            self::STATUS_FAILED, self::NATIVE_FAILURE => self::STATUS_FAILED,
            default => self::STATUS_BLOCKED,
        };
    }

    /**
     * Project shared envelope status back to a native organ label.
     * Unknown origins keep the envelope status (AEMOR uses the shared labels).
     */
    public static function toNativeStatus(string $envelopeStatus, string $origin): string
    {
        $status = AiValueNormalizer::lowerTrimmedString($envelopeStatus);

        return match (AiValueNormalizer::lowerTrimmedString($origin)) {
            self::ORIGIN_DEV_PROCEDURAL => match ($status) {
                self::STATUS_SUCCEEDED => self::NATIVE_SUCCESS,
                self::STATUS_FAILED => self::STATUS_FAILED,
                default => self::STATUS_BLOCKED,
            },
            self::ORIGIN_COMPOUNDING => match ($status) {
                self::STATUS_SUCCEEDED => self::NATIVE_PASSED,
                self::STATUS_FAILED => self::STATUS_FAILED,
                default => self::STATUS_BLOCKED,
            },
            default => in_array($status, self::STATUSES, true) ? $status : self::STATUS_BLOCKED,
        };
    }

    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $normalized = self::validate($data);

        return new self($normalized);
    }

    /**
     * Adapter entrypoint: stamps schema/formula/origin and binds native_divergent
     * to the same origin so origin-mismatch cannot slip through construction.
     *
     * @param  array<string,mixed>  $fields  envelope fields except schema/formula/adapter_origin/native_divergent
     * @param  array<string,mixed>  $nativeFields  divergent organ fields (never coerced)
     */
    public static function fromAdapter(string $origin, array $fields, array $nativeFields): self
    {
        return self::fromArray(array_merge($fields, [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_ADAPTER_ORIGIN => $origin,
            self::FIELD_NATIVE_DIVERGENT => [
                self::FIELD_ORIGIN => $origin,
                self::FIELD_FIELDS => $nativeFields,
            ],
        ]));
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function validate(array $data): array
    {
        $schema = AiValueNormalizer::lowerTrimmedString($data[self::FIELD_SCHEMA_VERSION] ?? '');
        if ($schema !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException(self::FIELD_OUTCOME_ENVELOPE_SCHEMA_INVALID);
        }

        $formula = AiValueNormalizer::lowerTrimmedString($data[self::FIELD_FORMULA_VERSION] ?? '');
        if ($formula !== self::FORMULA_VERSION) {
            throw new InvalidArgumentException(self::FIELD_OUTCOME_ENVELOPE_FORMULA_INVALID);
        }

        $origin = AiValueNormalizer::lowerTrimmedString($data[self::FIELD_ADAPTER_ORIGIN] ?? '');
        if (! in_array($origin, self::ADAPTER_ORIGINS, true)) {
            throw new InvalidArgumentException(self::FIELD_OUTCOME_ENVELOPE_ADAPTER_ORIGIN_INVALID);
        }

        $status = AiValueNormalizer::lowerTrimmedString($data[self::FIELD_STATUS] ?? '');
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(self::FIELD_OUTCOME_ENVELOPE_STATUS_INVALID);
        }

        $basis = AiValueNormalizer::lowerTrimmedString($data[self::FIELD_VERIFIED_BASIS] ?? '');
        if (! in_array($basis, [
            AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_SERVER_VERIFIED,
            AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_GATES_PASSED,
            AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_CLAIMED,
            AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT,
        ], true)) {
            throw new InvalidArgumentException(self::FIELD_OUTCOME_ENVELOPE_VERIFIED_BASIS_INVALID);
        }

        $divergent = $data[self::FIELD_NATIVE_DIVERGENT] ?? null;
        if (! is_array($divergent)) {
            throw new InvalidArgumentException(self::FIELD_OUTCOME_ENVELOPE_NATIVE_DIVERGENT_INVALID);
        }
        $divergentOrigin = AiValueNormalizer::lowerTrimmedString($divergent[self::FIELD_ORIGIN] ?? '');
        if ($divergentOrigin !== $origin) {
            throw new InvalidArgumentException('outcome_envelope_native_divergent_origin_mismatch');
        }
        if (! is_array($divergent[self::FIELD_FIELDS] ?? null)) {
            throw new InvalidArgumentException('outcome_envelope_native_divergent_fields_invalid');
        }

        $executor = AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_EXECUTOR] ?? null) ?? '';
        $taskCategory = AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_TASK_CATEGORY] ?? null) ?? '';
        $provider = AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_PROVIDER] ?? null) ?? '';
        if ($executor === '' || $taskCategory === '' || $provider === '') {
            throw new InvalidArgumentException(self::FIELD_OUTCOME_ENVELOPE_IDENTITY_FIELDS_REQUIRED);
        }

        if (! is_bool($data[self::FIELD_VERIFIED] ?? null)) {
            throw new InvalidArgumentException(self::FIELD_OUTCOME_ENVELOPE_VERIFIED_INVALID);
        }
        if (! is_bool($data[self::FIELD_VERIFIED_SOURCE_PRESENT] ?? null)) {
            throw new InvalidArgumentException('outcome_envelope_verified_source_present_invalid');
        }

        $evidenceRefCount = $data[self::FIELD_EVIDENCE_REF_COUNT] ?? null;
        if (! is_int($evidenceRefCount) || $evidenceRefCount < 0) {
            throw new InvalidArgumentException('outcome_envelope_evidence_ref_count_invalid');
        }

        $certifiedReceiptId = $data[self::FIELD_CERTIFIED_RECEIPT_ID] ?? null;
        if ($certifiedReceiptId !== null && AiValueNormalizer::trimmedStringOrNull($certifiedReceiptId) === null) {
            throw new InvalidArgumentException('outcome_envelope_certified_receipt_id_invalid');
        }

        $episodeId = $data[self::FIELD_EPISODE_ID] ?? null;
        if ($episodeId !== null && AiValueNormalizer::trimmedStringOrNull($episodeId) === null) {
            throw new InvalidArgumentException(self::FIELD_OUTCOME_ENVELOPE_EPISODE_ID_INVALID);
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_FORMULA_VERSION => self::FORMULA_VERSION,
            self::FIELD_ADAPTER_ORIGIN => $origin,
            self::FIELD_EXECUTOR => $executor,
            self::FIELD_TASK_CATEGORY => $taskCategory,
            self::FIELD_PROVIDER => $provider,
            self::FIELD_STATUS => $status,
            self::FIELD_VERIFIED => (AiValueNormalizer::boolOrNull($data[self::FIELD_VERIFIED] ?? null) ?? false),
            self::FIELD_VERIFIED_BASIS => $basis,
            self::FIELD_VERIFIED_SOURCE_PRESENT => (AiValueNormalizer::boolOrNull($data[self::FIELD_VERIFIED_SOURCE_PRESENT] ?? null) ?? false),
            self::FIELD_CERTIFIED_RECEIPT_ID => $certifiedReceiptId !== null ? AiValueNormalizer::trimmedStringOrNull($certifiedReceiptId) ?? '' : null,
            self::FIELD_EVIDENCE_REF_COUNT => $evidenceRefCount,
            self::FIELD_EPISODE_ID => $episodeId !== null ? AiValueNormalizer::trimmedStringOrNull($episodeId) ?? '' : null,
            self::FIELD_RUN_ID => AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_RUN_ID] ?? null),
            self::FIELD_NATIVE_DIVERGENT => [
                self::FIELD_ORIGIN => $divergentOrigin,
                self::FIELD_FIELDS => $divergent[self::FIELD_FIELDS],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
