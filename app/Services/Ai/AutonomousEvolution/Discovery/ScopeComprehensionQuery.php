<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * PART 1 · the contract Part 2 pulls — the LIVE, always-current comprehension of a scope.
 *
 * Three reads, no writes, NO scalar/rank anywhere (the pétreo invariant of {@see AtlasLoopScopeComprehensionModel}
 * flows straight through): the descriptive model, the per-unit NAMED transitions (facts, never ordered by
 * worth), and an honest freshness report so "omnipresence = alive, not a stale photo" is a VERIFIABLE contract
 * rather than a promise.
 */
interface ScopeComprehensionQuery
{
    /** The hydrated descriptive model for a scope root (memoized; byte-identical to a fresh build). */
    public function model(string $scopeRoot): AtlasLoopScopeComprehensionModel;

    /**
     * The NAMED next-level transitions available for a unit, from the FIXED deterministic fact->transition
     * map (orphan->wired, clone->unified, gap->satisfied, regressed->green, untested->tested). Each entry is a
     * grounded fact; WHICH transition is worth most is the Part-2 model-bound judgment, never encoded here.
     *
     * @return list<array{transition:string, from_fact:string, evidence:array<string,mixed>}>
     */
    public function transitionsFor(string $fqcn): array;

    /**
     * Honest freshness of the memoized snapshot for a scope: did the live source drift past the photo?
     *
     * @return array{stale:bool, changed_units:list<string>, snapshot_age_s:int}
     */
    public function staleness(string $scopeRoot): array;
}
