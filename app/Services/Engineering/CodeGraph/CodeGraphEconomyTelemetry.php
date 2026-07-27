<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * AP-815 · E-10 — Per-workspace token-economy ROI telemetry for the code graph.
 *
 * The cross-project context engine earns its keep by answering a query with FEWER
 * tokens than a naive ("read everything") baseline would have cost. This recorder is
 * the auditable ledger of that saving: for each answered query it stores what the
 * baseline WOULD have cost, what the graph-shaped context ACTUALLY cost, and the
 * derived economy (tokens saved + saved/baseline ratio). {@see report()} then rolls
 * the ledger up per workspace and across all workspaces so the operator can see the
 * real ROI of the engine without trusting a self-reported headline number.
 *
 *   saved  = max(0, baseline − actual)         (an "actual exceeds baseline" run is a
 *                                               NON-saving, never a negative one)
 *   ratio  = baseline > 0 ? saved / baseline : 0.0   (∈ [0,1]; 0 when there is no
 *                                               baseline to economise against)
 *
 * Determinism & fail-safety (house contract):
 *   - Pure of DB + clock + random + provider. The ledger lives in a plain in-memory
 *     array; an external store may be injected via the constructor so the recorder is
 *     fully testable and embeddable without persistence. Same inputs always yield the
 *     same row and the same report.
 *   - Never throws on bad data. Token counts are coerced to a safe non-negative
 *     integer: NaN/INF, negatives, floats and numeric strings are normalised; any
 *     non-numeric garbage becomes 0. A blank workspace/query key falls back to a
 *     stable placeholder so a malformed call can never corrupt the ledger or the
 *     rollup keys.
 *   - report() is order-insensitive in its inputs and orders its own output
 *     deterministically (workspaces sorted by id) so assertions and audits are stable.
 *
 * This is [php] by the runtime-language boundary: it RECORDS and AGGREGATES a
 * governance/ROI signal (a decision-support read model). It does no heavy ML/data
 * work — just integer accounting.
 */
class CodeGraphEconomyTelemetry
{
    public const SCHEMA = 'atlas.code_graph.economy_telemetry.v1';

    /** Placeholder used when a workspace id is blank/garbage, so keys stay stable. */
    public const UNKNOWN_WORKSPACE = 'unknown';

    /** Placeholder used when a query key is blank/garbage. */
    public const UNKNOWN_QUERY = 'unknown';

    /**
     * The append-only ledger of recorded query economies.
     *
     * Each row is a normalised array:
     *   {workspace_id:string, query_key:string, baseline:int, actual:int,
     *    saved:int, ratio:float}
     *
     * Held by reference to the (optionally injected) store so callers can share /
     * inspect the same underlying array.
     *
     * @var array<int,array{
     *   workspace_id:string, query_key:string,
     *   baseline:int, actual:int, saved:int, ratio:float
     * }>
     */
    private array $store;

    /**
     * @param  array<int,mixed>|null  $store  optional pre-existing ledger to append to.
     *   Defaults to a fresh empty ledger. Any rows present are kept as-is (the recorder
     *   never rewrites history); only NEW rows go through normalisation.
     */
    public function __construct(?array $store = null)
    {
        /** @var array<int,array{workspace_id:string,query_key:string,baseline:int,actual:int,saved:int,ratio:float}> $store */
        $store = $store ?? [];
        $this->store = $store;
    }

    /**
     * Record one query's token economy and return the normalised row that was stored.
     *
     * @param  string  $workspaceId  the workspace the query ran against (blank →
     *   {@see UNKNOWN_WORKSPACE}).
     * @param  string  $queryKey  a stable identifier for the query/route (blank →
     *   {@see UNKNOWN_QUERY}).
     * @param  int  $baselineTokens  tokens the naive "read everything" path WOULD have
     *   cost. Negatives/garbage are clamped to 0.
     * @param  int  $actualTokens  tokens the graph-shaped context ACTUALLY cost.
     *   Negatives/garbage are clamped to 0.
     * @return array{
     *   workspace_id:string, query_key:string,
     *   baseline:int, actual:int, saved:int, ratio:float
     * } the row exactly as appended to the ledger.
     */
    public function record(string $workspaceId, string $queryKey, int $baselineTokens, int $actualTokens): array
    {
        $baseline = $this->safeCount($baselineTokens);
        $actual = $this->safeCount($actualTokens);

        $saved = $baseline - $actual;
        if ($saved < 0) {
            $saved = 0;
        }

        $ratio = $baseline > 0 ? $saved / $baseline : 0.0;

        $row = [
            'workspace_id' => $this->safeKey($workspaceId, self::UNKNOWN_WORKSPACE),
            'query_key' => $this->safeKey($queryKey, self::UNKNOWN_QUERY),
            'baseline' => $baseline,
            'actual' => $actual,
            'saved' => $saved,
            'ratio' => $this->round($ratio),
        ];

        $this->store[] = $row;

        return $row;
    }

    /**
     * Roll the ledger up per workspace and across all workspaces.
     *
     * @param  string|null  $workspaceId  when given, restrict the report to that one
     *   workspace (matched against the same normalisation {@see record()} applies, so
     *   a blank filter matches rows stored under {@see UNKNOWN_WORKSPACE}). When null,
     *   report every workspace.
     * @return array{
     *   by_workspace: array<string,array{
     *     baseline:int, actual:int, saved:int, ratio:float, queries:int
     *   }>,
     *   totals: array{baseline:int, actual:int, saved:int, ratio:float, queries:int}
     * }
     *   `by_workspace` is keyed by workspace id, sorted ascending for determinism.
     *   Each bucket's `ratio` is its aggregate saved/baseline; `totals.ratio` is the
     *   grand aggregate saved/baseline (NOT an average of per-workspace ratios), 0.0
     *   when there is no baseline. `saved` is recomputed from the aggregate
     *   (max(0, Σbaseline − Σactual)) so the rollup is internally consistent even if
     *   individual rows were clamped.
     */
    public function report(?string $workspaceId = null): array
    {
        $filter = $workspaceId === null
            ? null
            : $this->safeKey($workspaceId, self::UNKNOWN_WORKSPACE);

        /** @var array<string,array{baseline:int, actual:int, queries:int}> $buckets */
        $buckets = [];

        $grandBaseline = 0;
        $grandActual = 0;
        $grandQueries = 0;

        foreach ($this->store as $row) {
            if (! is_array($row)) {
                continue; // fail-safe: ignore a corrupt externally-injected entry.
            }

            $wsId = $this->safeKey(
                isset($row['workspace_id']) && is_string($row['workspace_id']) ? $row['workspace_id'] : '',
                self::UNKNOWN_WORKSPACE
            );

            if ($filter !== null && $wsId !== $filter) {
                continue;
            }

            $baseline = $this->safeCount($row['baseline'] ?? 0);
            $actual = $this->safeCount($row['actual'] ?? 0);

            if (! isset($buckets[$wsId])) {
                $buckets[$wsId] = ['baseline' => 0, 'actual' => 0, 'queries' => 0];
            }

            $buckets[$wsId]['baseline'] += $baseline;
            $buckets[$wsId]['actual'] += $actual;
            $buckets[$wsId]['queries']++;

            $grandBaseline += $baseline;
            $grandActual += $actual;
            $grandQueries++;
        }

        // Sort workspaces by id for deterministic, auditable output.
        ksort($buckets);

        $byWorkspace = [];
        foreach ($buckets as $wsId => $bucket) {
            $byWorkspace[$wsId] = $this->summarize(
                $bucket['baseline'],
                $bucket['actual'],
                $bucket['queries']
            );
        }

        return [
            'by_workspace' => $byWorkspace,
            'totals' => $this->summarize($grandBaseline, $grandActual, $grandQueries),
        ];
    }

    /**
     * Clear the ledger. Mutates the in-memory store in place.
     */
    public function reset(): void
    {
        $this->store = [];
    }

    /**
     * Build a summary bucket from aggregate counts. `saved` and `ratio` are derived
     * from the aggregate so they obey the same clamp/zero-baseline rules as a row.
     *
     * @return array{baseline:int, actual:int, saved:int, ratio:float, queries:int}
     */
    private function summarize(int $baseline, int $actual, int $queries): array
    {
        $saved = $baseline - $actual;
        if ($saved < 0) {
            $saved = 0;
        }

        $ratio = $baseline > 0 ? $saved / $baseline : 0.0;

        return [
            'baseline' => $baseline,
            'actual' => $actual,
            'saved' => $saved,
            'ratio' => $this->round($ratio),
            'queries' => $queries,
        ];
    }

    /**
     * Coerce any token-count input to a safe non-negative integer.
     *
     * Accepts int/float/numeric-string; NaN/INF and non-numeric values become 0;
     * floats are floored (a fractional token is never rounded UP into an
     * overstated saving); negatives clamp to 0. This is the over-claim-safe
     * direction for every field.
     */
    private function safeCount(mixed $value): int
    {
        if (is_bool($value)) {
            // A bool is not a meaningful token count; treat as 0 rather than 1/0.
            return 0;
        }

        if (is_int($value)) {
            return $value < 0 ? 0 : $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value) || $value <= 0.0) {
                return 0;
            }

            return (int) floor($value);
        }

        if (is_string($value) && is_numeric(trim($value))) {
            $float = (float) trim($value);
            if (is_nan($float) || is_infinite($float) || $float <= 0.0) {
                return 0;
            }

            return (int) floor($float);
        }

        return 0;
    }

    /**
     * Normalise a key, falling back to a stable placeholder when blank. The key is
     * trimmed but otherwise preserved (callers own their id scheme); only emptiness
     * is corrected so the ledger and rollup buckets can never key on "".
     */
    private function safeKey(string $value, string $fallback): string
    {
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : $fallback;
    }

    /** Round reported ratios so stats are stable for assertions and audit. */
    private function round(float $value): float
    {
        return round($value, 6);
    }
}
