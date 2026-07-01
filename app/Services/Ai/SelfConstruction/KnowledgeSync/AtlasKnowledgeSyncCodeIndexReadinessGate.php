<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\KnowledgeSync;

/**
 * Pure readiness gate that fails when the indexed symbol delta does not cover
 * the changed code hash, preventing the next brain cycle from trusting a stale
 * code graph.
 *
 * Supports a docs_only_bypass for documentation-only changes that don't affect
 * the code symbol graph.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasKnowledgeSyncCodeIndexReadinessGate
{
    public const SCHEMA = 'atlas.knowledge_sync.code_index_readiness_gate.v1';

    /**
     * @param  array{
     *   changed_code_hash?:?string,
     *   changed_symbol_delta_hash?:?string,
     *   indexed_code_hash?:?string,
     *   docs_only_bypass?:bool,
     * }  $facts
     * @return array{
     *   schema:string,
     *   ready:bool,
     *   blockers:list<string>,
     * }
     */
    public function evaluate(array $facts): array
    {
        $changedCodeHash = $facts['changed_code_hash'] ?? null;
        $changedSymbolDeltaHash = $facts['changed_symbol_delta_hash'] ?? null;
        $indexedCodeHash = $facts['indexed_code_hash'] ?? null;
        $docsOnlyBypass = (bool) ($facts['docs_only_bypass'] ?? false);

        $blockers = [];

        // No changed code hash → nothing to check, ready
        if ($changedCodeHash === null || $changedCodeHash === '') {
            return $this->envelope(true, []);
        }

        // Docs-only bypass skips symbol delta requirement
        if ($docsOnlyBypass) {
            return $this->envelope(true, []);
        }

        // Changed code hash present → require matching symbol delta
        if ($changedSymbolDeltaHash === null || $changedSymbolDeltaHash === '') {
            $blockers[] = 'missing:changed_symbol_delta_hash';
        }

        // The indexed code hash must match the changed code hash
        if ($indexedCodeHash !== null && $indexedCodeHash !== '' && $indexedCodeHash !== $changedCodeHash) {
            $blockers[] = 'mismatch:indexed_code_hash≠changed_code_hash';
        }

        // If both symbol delta and indexed hash exist but don't align
        if ($changedSymbolDeltaHash !== null && $changedSymbolDeltaHash !== ''
            && $indexedCodeHash !== null && $indexedCodeHash !== ''
            && $indexedCodeHash === $changedCodeHash
        ) {
            // Symbol delta exists and code hash matches → OK
            return $this->envelope(true, []);
        }

        if (count($blockers) === 0 && $changedSymbolDeltaHash !== null && $changedSymbolDeltaHash !== '') {
            // Symbol delta present, no hash mismatch → ready
            return $this->envelope(true, []);
        }

        return $this->envelope(false, $blockers);
    }

    /** @param  list<string>  $blockers */
    private function envelope(bool $ready, array $blockers): array
    {
        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'ready' => $ready,
            'blockers' => $blockers,
        ];
    }
}
