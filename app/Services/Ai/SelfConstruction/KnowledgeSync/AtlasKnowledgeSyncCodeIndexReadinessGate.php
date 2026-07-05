<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\KnowledgeSync;

/**
 * Pure readiness gate that fails when the indexed symbol delta does not cover
 * the changed code hash, preventing the next brain cycle from trusting a stale
 * code graph.
 *
 * Also checks:
 *   - code_index_age_seconds: if the index is older than MAX_INDEX_AGE_SECONDS,
 *     readiness is blocked with a refresh_code_index action.
 *   - docs_sync_drift_score: if docs sync drift exceeds MAX_DOCS_DRIFT_SCORE,
 *     readiness is blocked separately from code index freshness.
 *
 * Supports a docs_only_bypass for documentation-only changes that don't affect
 * the code symbol graph.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasKnowledgeSyncCodeIndexReadinessGate
{
    public const SCHEMA = 'atlas.knowledge_sync.code_index_readiness_gate.v1';

    /** Maximum age in seconds before the code index is considered stale. */
    private const MAX_INDEX_AGE_SECONDS = 3600;

    /** Maximum docs sync drift score before readiness is blocked. */
    private const MAX_DOCS_DRIFT_SCORE = 0.10;

    /**
     * @param  array{
     *   changed_code_hash?:?string,
     *   changed_symbol_delta_hash?:?string,
     *   indexed_code_hash?:?string,
     *   docs_only_bypass?:bool,
     *   code_index_age_seconds?:int,
     *   docs_sync_drift_score?:float,
     * }  $facts
     * @return array{
     *   schema:string,
     *   ready:bool,
     *   blockers:list<string>,
     *   refresh_actions:list<string>,
     *   summary:string,
     * }
     */
    public function evaluate(array $facts): array
    {
        $changedCodeHash = $facts['changed_code_hash'] ?? null;
        $changedSymbolDeltaHash = $facts['changed_symbol_delta_hash'] ?? null;
        $indexedCodeHash = $facts['indexed_code_hash'] ?? null;
        $docsOnlyBypass = (bool) ($facts['docs_only_bypass'] ?? false);
        $codeIndexAge = isset($facts['code_index_age_seconds']) ? max(0, (int) $facts['code_index_age_seconds']) : null;
        $docsDriftScore = isset($facts['docs_sync_drift_score']) ? (float) $facts['docs_sync_drift_score'] : null;

        $blockers = [];
        $refreshActions = [];

        // Stale code index age check — independent of hash matching.
        if ($codeIndexAge !== null && $codeIndexAge > self::MAX_INDEX_AGE_SECONDS) {
            $blockers[] = 'stale_code_index_age:'.$codeIndexAge.'s';
            $refreshActions[] = 'refresh_code_index';
        }

        // Docs sync drift check — separate from code index freshness.
        if ($docsDriftScore !== null && $docsDriftScore > self::MAX_DOCS_DRIFT_SCORE) {
            $blockers[] = 'docs_sync_drift:'.$docsDriftScore;
            $refreshActions[] = 'refresh_docs_sync';
        }

        // No changed code hash → nothing to check for symbol delta
        if ($changedCodeHash === null || $changedCodeHash === '') {
            $ready = $blockers === [];

            return $this->envelope($ready, $blockers, $refreshActions);
        }

        // Docs-only bypass skips symbol delta requirement
        if ($docsOnlyBypass) {
            $ready = $blockers === [];

            return $this->envelope($ready, $blockers, $refreshActions);
        }

        // Changed code hash present → require matching symbol delta
        if ($changedSymbolDeltaHash === null || $changedSymbolDeltaHash === '') {
            $blockers[] = 'missing:changed_symbol_delta_hash';
            $refreshActions[] = 'refresh_code_index';
        }

        // The indexed code hash must match the changed code hash
        if ($indexedCodeHash !== null && $indexedCodeHash !== '' && $indexedCodeHash !== $changedCodeHash) {
            $blockers[] = 'mismatch:indexed_code_hash≠changed_code_hash';
            $refreshActions[] = 'refresh_code_index';
        }

        // If both symbol delta and indexed hash exist but don't align
        if ($changedSymbolDeltaHash !== null && $changedSymbolDeltaHash !== ''
            && $indexedCodeHash !== null && $indexedCodeHash !== ''
            && $indexedCodeHash === $changedCodeHash
        ) {
            // Symbol delta exists and code hash matches → OK (unless stale/drifted)
            $ready = $blockers === [];

            return $this->envelope($ready, $blockers, $refreshActions);
        }

        if (count($blockers) === 0 && $changedSymbolDeltaHash !== null && $changedSymbolDeltaHash !== '') {
            // Symbol delta present, no hash mismatch → ready (unless stale/drifted)
            $ready = $blockers === [];

            return $this->envelope($ready, $blockers, $refreshActions);
        }

        return $this->envelope(false, $blockers, $refreshActions);
    }

    /** @param  list<string>  $blockers @param  list<string>  $refreshActions */
    private function envelope(bool $ready, array $blockers, array $refreshActions): array
    {
        sort($blockers, SORT_STRING);
        sort($refreshActions, SORT_STRING);

        // Provider-safe summary: no raw hashes, no file paths, no secrets.
        $summary = $ready
            ? 'code_index_and_docs_sync_ready'
            : 'blocked:'.implode(',', $blockers);

        return [
            'schema' => self::SCHEMA,
            'ready' => $ready,
            'blockers' => $blockers,
            'refresh_actions' => array_values(array_unique($refreshActions)),
            'summary' => $summary,
        ];
    }
}
