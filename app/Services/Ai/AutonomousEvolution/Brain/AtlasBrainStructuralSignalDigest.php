<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

/**
 * COMPREHENSION-DEEPENING substrate — the structural-signal digest. The comprehension model already computes
 * orphans (FQCNs with 0 production callers), clone clusters (duplicated implementations), and docStatedGaps
 * (gaps the canonical docs themselves call out) — non-obvious structural leverage the next origination should
 * see. Today none of that reaches the pasted brain: the served payload is one target_path + one objective, so
 * the brain can only originate file-local micro-leverage and the multi-file structural gap stays invisible.
 *
 * This organ is the SEAM: a pure, read-only digest that picks the top-K facts from each axis (bounded so the
 * payload stays small) and shapes them for the brain to read. No write, no I/O, no provider — the comprehension
 * model is the single source of truth, this just selects what surfaces. Determinism: sort by stable key per
 * axis (FQCN string, cluster id, gap text) then slice top-K; same model in ⇒ same digest out.
 *
 * Author≠judge intact: this surfaces SIGNALS, never originates / never decides / never gates. The brain decides
 * which (if any) to act on next; the existing gates still vet whatever the brain authors. Pétreo (the brain
 * never edits its own structural perception — same principle as the model itself and the reflection stream).
 */
final class AtlasBrainStructuralSignalDigest
{
    public const SCHEMA = 'atlas.brain.structural_signal_digest.v1';

    /** Default top-K per axis. Bounded so the payload stays small (≤~45 facts total). */
    public const DEFAULT_K = 5;

    /**
     * Build the digest from a comprehension model. Flag-gated by reflection_enabled? No — gated at the WIRING
     * site (see AtlasBrainNextCommand) so this organ stays pure and reusable. K=0 ⇒ empty digest (useful for a
     * test asserting the OFF byte-identical contract at the call site).
     *
     * @return array{schema:string, orphans:list<string>, clone_clusters:list<string>, doc_stated_gaps:list<string>, k:int}
     */
    public function digest(AtlasLoopScopeComprehensionModel $model, int $k = self::DEFAULT_K): array
    {
        if ($k <= 0) {
            return ['schema' => self::SCHEMA, 'orphans' => [], 'clone_clusters' => [], 'doc_stated_gaps' => [], 'k' => 0];
        }

        // Orphans: FQCNs with no production caller — the canonical multi-file leverage shape (organ unwired,
        // not dead-code per memory loop-orphans-are-unwired-organs-not-deadcode). Sort alphabetically for
        // determinism (the model's own ordering may be insertion-dependent), then slice top-K.
        $orphans = array_values(array_map('strval', $model->orphans));
        sort($orphans, SORT_STRING);
        $orphans = array_slice($orphans, 0, $k);

        // Clone clusters: the model's canonical shape is `list<{cluster_id, clone_hash, members}>` (line 39 of
        // the model docblock). Extract cluster_id; fall back to a string key / scalar value so a hand-built
        // shape (the test fixture, or any caller passing a simpler map) still works.
        $clusterIds = [];
        foreach ($model->cloneClusters as $key => $value) {
            $id = '';
            if (is_array($value)) {
                $id = trim((string) ($value['cluster_id'] ?? $value['id'] ?? ''));
            }
            if ($id === '') {
                $id = is_string($key) ? trim($key) : (is_scalar($value) ? trim((string) $value) : '');
            }
            if ($id !== '') {
                $clusterIds[$id] = true;
            }
        }
        $clusterIds = array_keys($clusterIds);
        sort($clusterIds, SORT_STRING);
        $clusterIds = array_slice($clusterIds, 0, $k);

        // docStatedGaps: gaps the canonical docs themselves call out — the brain should prefer these (high
        // ambient evidence). Same shape-tolerant extraction as clusters.
        $gaps = [];
        foreach ($model->docStatedGaps as $key => $value) {
            $gap = is_string($value) ? $value : (is_scalar($value) ? (string) $value : (is_array($value) ? (string) ($value['text'] ?? $value['gap'] ?? $value['title'] ?? '') : ''));
            $gap = trim($gap);
            if ($gap !== '') {
                $gaps[$gap] = true;
            } elseif (is_string($key)) {
                $kt = trim($key);
                if ($kt !== '') {
                    $gaps[$kt] = true;
                }
            }
        }
        $gaps = array_keys($gaps);
        sort($gaps, SORT_STRING);
        $gaps = array_slice($gaps, 0, $k);

        return [
            'schema' => self::SCHEMA,
            'orphans' => $orphans,
            'clone_clusters' => $clusterIds,
            'doc_stated_gaps' => $gaps,
            'k' => $k,
        ];
    }
}
