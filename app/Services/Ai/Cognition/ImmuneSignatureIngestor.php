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

        $contentHash = (string) ($verdictRow['candidate_hash'] ?? '');
        if ($contentHash === '') {
            return null;
        }

        $hostileClass = $this->hostileClassFromClassification($classification);
        if ($hostileClass === null) {
            return null;
        }

        /** @var list<string> $signals */
        $signals = array_values(array_map('strval', AiValueNormalizer::arrayOrEmpty($classification['matched_signals'] ?? null)));

        return $this->store->recordFromIncident(
            (string) ($verdictRow['id'] ?? $contentHash),
            ImmuneSignatureStore::ORIGIN_VERDICT,
            $contentHash,
            $hostileClass,
            $signals,
            [
                'writer' => (string) ($verdictRow['writer'] ?? 'unknown'),
                'sample_label' => $verdictRow['sample_label'] ?? null,
                'promotion_status' => $verdictRow['promotion_status'] ?? null,
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
            : $this->deriver->contentHashFromText((string) ($entry->body ?? $entry->redacted_body ?? ''));

        $metadata = AiValueNormalizer::arrayOrEmpty($entry->metadata);
        $classification = AiValueNormalizer::arrayOrEmpty($metadata['immune_classification'] ?? null);

        $memoryType = (string) ($entry->memory_type ?? '');
        $hostileClass = $this->hostileClassFromClassification($classification)
            ?? $this->hostileClassFromMemoryType($memoryType);

        if ($hostileClass === null) {
            return null;
        }

        /** @var list<string> $signals */
        $signals = array_values(array_map('strval', (array) ($classification['matched_signals'] ?? ['memory_revert'])));

        return $this->store->recordFromIncident(
            'decision:'.$decisionId.':memory:'.$entry->id,
            ImmuneSignatureStore::ORIGIN_MEMORY_REVERT,
            $contentHash,
            $hostileClass,
            $signals,
            [
                'memory_id' => (string) $entry->id,
                'decision_id' => $decisionId,
                'memory_type' => $memoryType,
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $verdictRow
     */
    private function isConfirmedPoisonVerdict(array $verdictRow): bool
    {
        $label = is_scalar($verdictRow['sample_label'] ?? null) ? (string) $verdictRow['sample_label'] : '';
        $status = AiValueNormalizer::lowerTrimmedString($verdictRow['promotion_status'] ?? '');
        $blocking = array_values(array_filter(AiValueNormalizer::arrayOrEmpty($verdictRow['blocking_gate_ids'] ?? null), 'is_string'));

        return $label === ImmuneVerdictLedger::LABEL_TRUE_BLOCK
            && $status === 'blocked'
            && $blocking !== [];
    }

    /**
     * @param  array<string,mixed>  $classification
     */
    private function hostileClassFromClassification(array $classification): ?string
    {
        $inputClass = AiValueNormalizer::lowerTrimmedString($classification['input_class'] ?? '');

        return in_array($inputClass, ['prompt_injection', 'private_sensitive', 'untrusted_content'], true)
            ? $inputClass
            : null;
    }

    private function hostileClassFromMemoryType(string $memoryType): ?string
    {
        return match ($memoryType) {
            'anti_memory', 'refutation_memory' => 'untrusted_content',
            default => null,
        };
    }
}
