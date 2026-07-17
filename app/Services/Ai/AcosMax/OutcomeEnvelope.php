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

    public const ADAPTER_ORIGINS = ['dev_procedural', 'aemor', 'compounding'];

    public const STATUSES = ['succeeded', 'failed', 'blocked'];

    /**
     * Map divergent native status labels onto the shared envelope statuses.
     * Unknown / review-like values fail closed to blocked (never invent success).
     */
    public static function normalizeStatus(string $nativeStatus): string
    {
        return match (AiValueNormalizer::lowerTrimmedString($nativeStatus)) {
            'succeeded', 'success', 'passed' => 'succeeded',
            'failed', 'failure' => 'failed',
            default => 'blocked',
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
            'dev_procedural' => match ($status) {
                'succeeded' => 'success',
                'failed' => 'failed',
                default => 'blocked',
            },
            'compounding' => match ($status) {
                'succeeded' => 'passed',
                'failed' => 'failed',
                default => 'blocked',
            },
            default => in_array($status, self::STATUSES, true) ? $status : 'blocked',
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
            'schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'adapter_origin' => $origin,
            'native_divergent' => [
                'origin' => $origin,
                'fields' => $nativeFields,
            ],
        ]));
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function validate(array $data): array
    {
        $schema = AiValueNormalizer::lowerTrimmedString($data['schema_version'] ?? '');
        if ($schema !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('outcome_envelope_schema_invalid');
        }

        $formula = AiValueNormalizer::lowerTrimmedString($data['formula_version'] ?? '');
        if ($formula !== self::FORMULA_VERSION) {
            throw new InvalidArgumentException('outcome_envelope_formula_invalid');
        }

        $origin = AiValueNormalizer::lowerTrimmedString($data['adapter_origin'] ?? '');
        if (! in_array($origin, self::ADAPTER_ORIGINS, true)) {
            throw new InvalidArgumentException('outcome_envelope_adapter_origin_invalid');
        }

        $status = AiValueNormalizer::lowerTrimmedString($data['status'] ?? '');
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('outcome_envelope_status_invalid');
        }

        $basis = AiValueNormalizer::lowerTrimmedString($data['verified_basis'] ?? '');
        if (! in_array($basis, [
            AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_SERVER_VERIFIED,
            AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_GATES_PASSED,
            AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_CLAIMED,
            AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_ABSENT,
        ], true)) {
            throw new InvalidArgumentException('outcome_envelope_verified_basis_invalid');
        }

        $divergent = $data['native_divergent'] ?? null;
        if (! is_array($divergent)) {
            throw new InvalidArgumentException('outcome_envelope_native_divergent_invalid');
        }
        $divergentOrigin = AiValueNormalizer::lowerTrimmedString($divergent['origin'] ?? '');
        if ($divergentOrigin !== $origin) {
            throw new InvalidArgumentException('outcome_envelope_native_divergent_origin_mismatch');
        }
        if (! is_array($divergent['fields'] ?? null)) {
            throw new InvalidArgumentException('outcome_envelope_native_divergent_fields_invalid');
        }

        $executor = AiValueNormalizer::trimmedString($data['executor'] ?? '');
        $taskCategory = AiValueNormalizer::trimmedString($data['task_category'] ?? '');
        $provider = AiValueNormalizer::trimmedString($data['provider'] ?? '');
        if ($executor === '' || $taskCategory === '' || $provider === '') {
            throw new InvalidArgumentException('outcome_envelope_identity_fields_required');
        }

        if (! is_bool($data['verified'] ?? null)) {
            throw new InvalidArgumentException('outcome_envelope_verified_invalid');
        }
        if (! is_bool($data['verified_source_present'] ?? null)) {
            throw new InvalidArgumentException('outcome_envelope_verified_source_present_invalid');
        }

        $evidenceRefCount = $data['evidence_ref_count'] ?? null;
        if (! is_int($evidenceRefCount) || $evidenceRefCount < 0) {
            throw new InvalidArgumentException('outcome_envelope_evidence_ref_count_invalid');
        }

        $certifiedReceiptId = $data['certified_receipt_id'] ?? null;
        if ($certifiedReceiptId !== null && (! is_string($certifiedReceiptId) || trim($certifiedReceiptId) === '')) {
            throw new InvalidArgumentException('outcome_envelope_certified_receipt_id_invalid');
        }

        $episodeId = $data['episode_id'] ?? null;
        if ($episodeId !== null && (! is_string($episodeId) || trim($episodeId) === '')) {
            throw new InvalidArgumentException('outcome_envelope_episode_id_invalid');
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'adapter_origin' => $origin,
            'executor' => $executor,
            'task_category' => $taskCategory,
            'provider' => $provider,
            'status' => $status,
            'verified' => (bool) $data['verified'],
            'verified_basis' => $basis,
            'verified_source_present' => (bool) $data['verified_source_present'],
            'certified_receipt_id' => $certifiedReceiptId !== null ? trim((string) $certifiedReceiptId) : null,
            'evidence_ref_count' => $evidenceRefCount,
            'episode_id' => $episodeId !== null ? trim((string) $episodeId) : null,
            'run_id' => isset($data['run_id']) ? trim((string) $data['run_id']) : null,
            'native_divergent' => [
                'origin' => $divergentOrigin,
                'fields' => $divergent['fields'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
