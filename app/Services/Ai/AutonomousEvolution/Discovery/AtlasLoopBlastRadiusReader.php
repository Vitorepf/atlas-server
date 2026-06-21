<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

/**
 * ACDE lever B4a — DE-ORPHAN the blast-radius brain onto the live loop window.
 *
 * The weak engine edits a file blind to WHO depends on it. {@see AtlasLoopBlastRadiusAnalyzer} computes that
 * impact set (reverse-dependency BFS + risk band) but was a pure keystone with no real edge source. This reader
 * supplies the missing source: it resolves the target file to its code-graph node(s) in the latest world model
 * (AP-815), feeds the analyzer a `consumersOf` callable backed by {@see CodeGraphAdjacencyIndex}'s `incoming`
 * semantics (here: a bounded reverse-edge query), and TRANSLATES the impacted node ids back to repo-relative
 * file PATHS so the driver can inject "these consume you — keep them green".
 *
 * PROVIDER-SAFE: returns only repo-relative PATHS + a coarse risk band — never raw code, never a self-report,
 * never engine-vs-engine. A read over an index that already exists (no new table, no write, no merge-path
 * touch — the same shape as B3). Flag-gated default-OFF => empty => byte-identical; every DB touch is guarded
 * (a DB-less / un-indexed caller degrades to empty, never throws).
 *
 * World-model selection is the most-recently-updated model (the loop runs against one primary indexed
 * workspace locally). Default-OFF means the operator only arms this once the index is fresh for their
 * workspace; a stale or foreign model simply yields no path match => empty (it can never inject a wrong file).
 */
final class AtlasLoopBlastRadiusReader
{
    /**
     * Impact summary for the file being edited, or [] when OFF / no DB / un-indexed / no consumers.
     *
     * @return array{consumer_count:int, risk:string, consumers:list<string>, truncated:bool}|array{}
     */
    public function read(string $targetPath, int $maxDepth = 1, int $maxNodes = 60): array
    {
        if (! (bool) config('atlas.loop.blast_radius_brain_enabled', false)) {
            return [];
        }
        if (! DatabaseTableAvailability::all([
            'ai_codebase_world_models',
            'ai_codebase_world_model_nodes',
            'ai_codebase_world_model_edges',
        ])) {
            return [];
        }
        $targetPath = trim($targetPath);
        if ($targetPath === '') {
            return [];
        }
        $maxDepth = max(1, $maxDepth);
        $maxNodes = max(1, $maxNodes);

        try {
            return (new AtlasLoopBlastRadiusReaderSupport)->readFromIndexedGraph($targetPath, $maxDepth, $maxNodes);
        } catch (Throwable) {
            return [];
        }
    }
}
