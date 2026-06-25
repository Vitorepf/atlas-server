<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * PART 1 · §1.3 — the FREE runtime FACTS a unit's level_vector reads, sourced from signals the loop already
 * persists (NO `--coverage` run, no new instrument). Per-file booleans, never a number:
 *
 *   - hasGateBlock(file): the mutation-adequacy gate refused to certify a refactor of this file because a
 *     decision mutant survived (read from the persisted grind result via {@see AtlasLoopCoverageGapDetector}).
 *   - lastMergeClean(file): this file's most recent merge to main was clean (canary green), read from
 *     `atlas_loop_proposals.merged_to_main` — the same row {@see AtlasLoopCapabilityTrendService} already reads.
 *
 * A degraded/DB-less source returns the SAFE "no evidence" value (false), so a missing signal never fabricates
 * a "needs work" transition.
 */
interface ScopeRuntimeFacts
{
    /** Did the mutation-adequacy gate block certification for this file? (false = no block evidence = clean.) */
    public function hasGateBlock(string $relPath): bool;

    /** Was this file's most recent merge to main clean (canary green)? (false = no clean merge recorded.) */
    public function lastMergeClean(string $relPath): bool;
}

/**
 * OPTIONAL extension fact: observed test presence sourced from the persistent coverage-edge ledger
 * (see {@see \App\Models\AtlasLoopTestCoverageEdge}) via {@see AtlasLoopTestPresenceOracle}.
 *
 * Kept OUT of {@see ScopeRuntimeFacts} so legacy implementations stay byte-identical. Callers use
 * `instanceof ScopeRuntimeFactsWithTestPresence` to detect availability and degrade safely to
 * `true` when this trait is not present.
 */
interface ScopeRuntimeFactsWithTestPresence
{
    /**
     * Does this file have observed test coverage? Safe degradation: when the database is
     * unavailable or the coverage-edges table is missing, this MUST return true (no spurious
     * `untested->tested` transition fires on infra failure).
     */
    public function hasTest(string $relPath): bool;
}
