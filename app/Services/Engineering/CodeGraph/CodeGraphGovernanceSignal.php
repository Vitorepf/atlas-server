<?php

namespace App\Services\Engineering\CodeGraph;

use App\Services\Engineering\EngineeringStringListNormalizer;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Fail-closed governance signal for the code graph (AP-811 / AP-812, M-10).
 *
 * This is the bridge that lets the *real* code graph participate in the same
 * automatic gate {@see \App\Services\Engineering\AtlasCodeIntelligenceAutomaticGateService}
 * already runs for the symbol index: it answers one question — "is the resolved
 * edge set fresh, populated and drift-free enough that Dev/Forge may trust it?"
 * — and emits the exact `{status, blockers}` shape that gate consumes, so a stale
 * or empty or drifted graph BLOCKS the same way the symbol index does. It does
 * NOT rebuild that gate, run the index, or decide anything beyond the yes/no.
 *
 * Pure transform: no DB, no IO, no provider, no Python runtime, no wall clock.
 * The caller supplies the already-measured stats (it owns the DB read and the
 * clock); this only classifies them. Same inputs always yield byte-identical
 * output — determinism is a tested invariant, not an aspiration.
 *
 * Fail-closed by construction: the default verdict is `blocked`. A graph is only
 * `ready` when every guard passes. Anything we cannot prove fresh — a missing or
 * unparseable build timestamp, a non-positive max-age budget — is treated as a
 * blocker, never silently waved through. "Don't know" is "blocked".
 *
 * Blockers (named to mirror the symbol-index gate's vocabulary):
 *  - code_graph_edges_empty          edge_count <= 0 (nothing to trust);
 *  - code_graph_drift_detected       drift_count > 0 (graph disagrees with code);
 *  - code_graph_last_built_at_missing last_built_at absent/blank (can't prove age);
 *  - code_graph_last_built_at_invalid last_built_at unparseable (can't prove age);
 *  - code_graph_now_minutes_invalid   now_minutes absent/non-numeric (no clock);
 *  - code_graph_max_age_invalid       max_age_minutes <= 0 (no freshness budget);
 *  - code_graph_index_stale_by_age    age (now - built) exceeds max_age_minutes.
 */
class CodeGraphGovernanceSignal
{
    public const SCHEMA = 'atlas.code_graph.governance_signal.v1';

    public const STATUS_READY = 'ready';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * Classify the code-graph stats into the gate verdict.
     *
     * @param  array{
     *     edge_count?:int,
     *     last_built_at?:string|null,
     *     drift_count?:int,
     *     max_age_minutes?:int,
     *     now_minutes?:int
     * }  $stats
     * @return array{status:'ready'|'blocked', blockers:array<int,string>}
     */
    public function evaluate(array $stats): array
    {
        $blockers = [];

        // Populated: an empty edge set means there is no graph to trust at all.
        if ($this->intOrNull($stats['edge_count'] ?? null) === null
            || (int) $stats['edge_count'] <= 0) {
            $blockers[] = 'code_graph_edges_empty';
        }

        // Drift: any drift means the graph disagrees with the code on disk, so it
        // is unsafe to navigate by — exactly the symbol-index drift contract.
        if (($this->intOrNull($stats['drift_count'] ?? null) ?? 0) > 0) {
            $blockers[] = 'code_graph_drift_detected';
        }

        // Freshness: prove the graph is recent enough, or fail closed. Each input
        // we need to compute age is validated; a missing/invalid one is itself a
        // blocker rather than an excuse to skip the age check.
        foreach ($this->freshnessBlockers($stats) as $blocker) {
            $blockers[] = $blocker;
        }

        $blockers = EngineeringStringListNormalizer::uniqueNonEmptyStrings($blockers);

        return [
            'status' => $blockers === [] ? self::STATUS_READY : self::STATUS_BLOCKED,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $stats
     * @return array<int,string>
     */
    private function freshnessBlockers(array $stats): array
    {
        $maxAge = $this->intOrNull($stats['max_age_minutes'] ?? null);
        if ($maxAge === null || $maxAge <= 0) {
            // No freshness budget => we cannot certify recency => fail closed.
            return ['code_graph_max_age_invalid'];
        }

        $now = $this->intOrNull($stats['now_minutes'] ?? null);
        if ($now === null) {
            return ['code_graph_now_minutes_invalid'];
        }

        $builtAt = $this->builtAtMinutes($stats['last_built_at'] ?? null);
        if ($builtAt === 'missing') {
            return ['code_graph_last_built_at_missing'];
        }
        if ($builtAt === 'invalid') {
            return ['code_graph_last_built_at_invalid'];
        }

        // Age is clamped at 0: a build "in the future" (clock skew) is treated as
        // age 0, i.e. fresh — never as a negative that would mask staleness.
        $ageMinutes = max(0, $now - (int) $builtAt);

        return $ageMinutes > $maxAge ? ['code_graph_index_stale_by_age'] : [];
    }

    /**
     * Resolve last_built_at to minutes on the same clock as now_minutes.
     *
     * Accepts two honest forms without touching a wall clock:
     *  - numeric => already minutes on the caller's clock (used as-is);
     *  - ISO/parseable timestamp => converted to absolute minutes since the Unix
     *    epoch. (The caller is then expected to express now_minutes the same way;
     *    mixing units is the caller's contract, not ours to silently reconcile.)
     *
     * @return int|string  minutes (int) on success, or the sentinels
     *                      'missing' | 'invalid' to drive a named blocker.
     */
    private function builtAtMinutes(mixed $value): int|string
    {
        if ($value === null) {
            return 'missing';
        }

        // Numeric (int/float/numeric-string) => caller-clock minutes verbatim.
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return 'missing';
            }
            if (is_numeric($trimmed)) {
                return (int) $trimmed;
            }

            try {
                return (int) floor(CarbonImmutable::parse($trimmed)->getTimestamp() / 60);
            } catch (Throwable) {
                return 'invalid';
            }
        }

        return 'invalid';
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return null;
    }
}
