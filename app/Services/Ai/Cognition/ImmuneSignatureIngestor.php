<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * MAXI-05 — ingest confirmed/reverted poison incidents into the signature store.
 */
final class ImmuneSignatureIngestor
{
    public const FIELD_BLOCKING_GATE_IDS = 'blocking_gate_ids';
    public const FIELD_ID = 'id';
    public const STATUS_BLOCKED = 'blocked';

    public const WRITER_UNKNOWN = 'unknown';
    public const FIELD_SAMPLE_LABEL = 'sample_label';
    public const FIELD_PROMOTION_STATUS = 'promotion_status';
    public const FIELD_WRITER = 'writer';
    public const FIELD_MATCHED_SIGNALS = 'matched_signals';
    public const FIELD_MEMORY_ID = 'memory_id';
    public const FIELD_DECISION_ID = 'decision_id';
    public const FIELD_CANDIDATE_HASH = 'candidate_hash';
    public const FIELD_IMMUNE_CLASSIFICATION = 'immune_classification';
    public const FIELD_MEMORY_TYPE = 'memory_type';
    public const FIELD_REFUTATION_MEMORY = 'refutation_memory';
    public const FIELD_INPUT_CLASS = 'input_class';
    public const FIELD_MEMORY_REVERT = 'memory_revert';
    public const FIELD_STRVAL = 'strval';
    public const FIELD_IS_STRING = 'is_string';

    private readonly ImmuneSignatureStore $store;

    private readonly ImmuneSignatureDeriver $deriver;

    public function __construct(
        ?ImmuneSignatureStore $store = null,
        ?ImmuneSignatureDeriver $deriver = null,
    ) {
        $this->store = $store ?? new ImmuneSignatureStore;
        $this->deriver = $deriver ?? new ImmuneSignatureDeriver;
    }

    /**
     * @param  array<string,mixed>  $verdictRow
     * @param  array<string,mixed>  $classification
     * @return array<string,mixed>|null
     */
    public function maybeIngestFromVerdict(array $verdictRow, array $classification): ?array
    {
        if (! $this->isConfirmedPoisonVerdict($verdictRow)) {
            return null;
        }

        $contentHash = (AiValueNormalizer::trimmedStringOrNull($verdictRow[self::FIELD_CANDIDATE_HASH] ?? null) ?? '');
        if ($contentHash === '') {
            return null;
        }

        $hostileClass = $this->hostileClassFromClassification($classification);
        if ($hostileClass === null) {
            return null;
        }

        /** @var list<string> $signals */
        $signals = array_values(array_map(self::FIELD_STRVAL, AiValueNormalizer::arrayOrEmpty($classification[self::FIELD_MATCHED_SIGNALS] ?? null)));

        return $this->store->recordFromIncident(
            (AiValueNormalizer::trimmedStringOrNull($verdictRow[self::FIELD_ID] ?? null) ?? $contentHash),
            ImmuneSignatureStore::ORIGIN_VERDICT,
            $contentHash,
            $hostileClass,
            $signals,
            [
                self::FIELD_WRITER => (AiValueNormalizer::trimmedStringOrNull($verdictRow[self::FIELD_WRITER] ?? null) ?? self::WRITER_UNKNOWN),
                self::FIELD_SAMPLE_LABEL => $verdictRow[self::FIELD_SAMPLE_LABEL] ?? null,
                self::FIELD_PROMOTION_STATUS => $verdictRow[self::FIELD_PROMOTION_STATUS] ?? null,
            ],
        );
    }

  /**
     * @return array<string,mixed>|null
     */
    public function maybeIngestFromRevertedMemory(AtlasMemoryEntry $entry, string $decisionId): ?array
    {
        $contentHash = is_string($entry->content_hash) && $entry->content_hash !== ''
            ? $entry->content_hash
            : $this->deriver->contentHashFromText(AiValueNormalizer::trimmedString($entry->body ?? $entry->redacted_body ?? ''));

        $metadata = AiValueNormalizer::arrayOrEmpty($entry->metadata);
        $classification = AiValueNormalizer::arrayOrEmpty($metadata[self::FIELD_IMMUNE_CLASSIFICATION] ?? null);

        $memoryType = AiValueNormalizer::trimmedScalarStringOrNull($entry->memory_type ?? null) ?? '';
        $hostileClass = $this->hostileClassFromClassification($classification)
            ?? $this->hostileClassFromMemoryType($memoryType);

        if ($hostileClass === null) {
            return null;
        }

        /** @var list<string> $signals */
        $signals = array_values(array_map(self::FIELD_STRVAL, AiValueNormalizer::arrayOrEmpty($classification[self::FIELD_MATCHED_SIGNALS] ?? [self::FIELD_MEMORY_REVERT])));

        return $this->store->recordFromIncident(
            'decision:'.$decisionId.':memory:'.$entry->id,
            ImmuneSignatureStore::ORIGIN_MEMORY_REVERT,
            $contentHash,
            $hostileClass,
            $signals,
            [
                self::FIELD_MEMORY_ID => AiValueNormalizer::trimmedScalarStringOrNull($entry->id ?? null) ?? '',
                self::FIELD_DECISION_ID => $decisionId,
                self::FIELD_MEMORY_TYPE => $memoryType,
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $verdictRow
     */
    private function isConfirmedPoisonVerdict(array $verdictRow): bool
    {
        $label = AiValueNormalizer::trimmedScalarStringOrNull($verdictRow[self::FIELD_SAMPLE_LABEL] ?? null) ?? '';
        $status = AiValueNormalizer::lowerTrimmedString($verdictRow[self::FIELD_PROMOTION_STATUS] ?? '');
        $blocking = array_values(array_filter(AiValueNormalizer::arrayOrEmpty($verdictRow[self::FIELD_BLOCKING_GATE_IDS] ?? null), self::FIELD_IS_STRING));

        return $label === ImmuneVerdictLedger::LABEL_TRUE_BLOCK
            && $status === self::STATUS_BLOCKED
            && $blocking !== [];
    }

    /**
     * @param  array<string,mixed>  $classification
     */
    private function hostileClassFromClassification(array $classification): ?string
    {
        $inputClass = AiValueNormalizer::lowerTrimmedString($classification[self::FIELD_INPUT_CLASS] ?? '');

        return in_array($inputClass, ['prompt_injection', 'private_sensitive', 'untrusted_content'], true)
            ? $inputClass
            : null;
    }

    private function hostileClassFromMemoryType(string $memoryType): ?string
    {
        return match ($memoryType) {
            'anti_memory', self::FIELD_REFUTATION_MEMORY => 'untrusted_content',
            default => null,
        };
    }
}
