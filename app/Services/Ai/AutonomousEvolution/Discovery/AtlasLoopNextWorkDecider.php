<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopUtilityGradeService;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use Throwable;

/**
 * DECISION ("o quê a seguir") — the UNGAMEABLE next-work priority.
 *
 * The loop used to decide which task to grind NEXT by `priority = round(target.score * 100)` — a
 * stored, WRITABLE scalar trusted blindly at claim time (`ORDER BY priority DESC`). That is exactly
 * the anti-pattern the merge-quality régua ({@see AtlasLoopUtilityGradeService})
 * fixed for MERGING: it never trusts a stored receipt field; it RE-RESOLVES wiredness/complexity
 * from git+graph fresh. This decider applies the same standard to the PICK:
 *
 *   priority = BAND(shape) + OFFSET(leverage re-resolved fresh from the working tree)
 *
 *   - BAND is the work-SHAPE tier. A wired refactor hub and an obra cluster sit in strictly higher,
 *     non-overlapping bands than an edge-fix; the offset cap (BAND_WIDTH-1) is below the band gap,
 *     so SHAPE always dominates leverage and no forged/stale stored field can cross a band.
 *   - The band is RAISE-ONLY corrected from FRESH measured truth ({@see promoteBandFromGroundTruth}):
 *     a genuinely wired + complex hub that the coarse shape under-classified is promoted to the
 *     refactor band by re-measured callers+cyclomatic — never demoted (fail-open). So the band
 *     tracks ground truth, not a possibly-forged stored shape signal.
 *   - OFFSET is the LEVERAGE re-resolved FRESH at decide time: real caller count
 *     ({@see AtlasLoopWiredCallerService::callerCounts}, tri-state) + measured worst-method cyclomatic
 *     ({@see AtlasLoopSignalAnalyzer::fileComplexity}). NOT the stored writable `target.score`. When
 *     BOTH fresh reads are unavailable, the offset falls back to the stored score but is CAPPED far
 *     below any measured target, so a degraded/forged read can never out-sort genuine measured work.
 *
 * PURE-ish + FAIL-OPEN: no provider call, no repo write, no DB. The caller resolver is ALWAYS
 * anchored to the passed $repoRoot (the campaign workspace — never base_path()), so callers and
 * cyclomatic are read from the SAME tree. Re-resolution runs at ENQUEUE time only — NEVER inside the
 * hot atomic `claimNextTask` transaction. It emits an auditable rationale receipt ("why THIS next").
 * The decider itself is a FORBIDDEN_SELF_TARGET (the loop can never edit its own prioritizer).
 */
final class AtlasLoopNextWorkDecider
{
    /**
     * Band width per shape tier. A 32-bit `priority` column gives ample headroom. The bands are
     * strictly ordered and non-overlapping with the OFFSET capped below BAND_WIDTH, so SHAPE always
     * dominates leverage: a skip target can never out-rank an edge_fix, which can never out-rank a
     * refactor, which can never out-rank an obra — REGARDLESS of any stored score.
     */
    private const BAND_WIDTH = 1000;

    private const BAND_SKIP = 0;            // confirmed dead code — floor band (deferred upstream)

    private const BAND_EDGE_FIX = 2_000;    // default provider-grind lane

    private const BAND_REFACTOR = 4_000;    // wired, complex, test-backed hub (proven-drop lane)

    private const BAND_OBRA = 6_000;        // multi-file obra cluster (operator-gated heavy work)

    /** The ONLY shapes a caller may hint. An unknown/forged hint re-derives via the router. */
    private const SHAPE_ALLOW = [
        AtlasLoopWorkShapeRouter::SHAPE_SKIP,
        AtlasLoopWorkShapeRouter::SHAPE_REFACTOR,
        AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS,
        AtlasLoopWorkShapeRouter::SHAPE_MULTI_FILE,
        AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX,
        'obra',
        'obra_candidate',
        'multi_file_refactor',
    ];

    public function __construct(
        private readonly ?AtlasLoopSignalAnalyzer $signalAnalyzer = null,
        private readonly ?AtlasLoopWorkShapeRouter $workShapeRouter = null,
    ) {}

    /**
     * Decide the ungameable next-work priority for ONE target.
     *
     * @param  array<string,mixed>  $signals  the discovery signals packet stamped on the target
     * @param  float  $storedScore  the legacy composite score (capped fallback offset ONLY)
     * @param  string|null  $shapeHint  the shape of the lane that will actually enqueue this
     *                                  target; an unknown/forged hint is re-derived, never trusted
     * @return array{priority:int, shape:string, band:int, offset:int, receipt:array<string,mixed>}
     */
    public function decide(
        string $repoRoot,
        string $relPath,
        array $signals,
        float $storedScore,
        ?string $shapeHint = null,
    ): array {
        // Whitelist the hint: only an explicitly-allowed shape is trusted; anything else (unknown,
        // typo, forged) RE-DERIVES from the signals via the router — never lands in a band blind.
        $shape = ($shapeHint !== null && in_array($shapeHint, self::SHAPE_ALLOW, true))
            ? $shapeHint
            : (string) ($this->router()->decideShape($signals)['shape'] ?? AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);

        $band = $this->bandFor($shape);

        // OFFSET + the measured leverage used to RAISE the band from ground truth. Never trust the
        // stored score for the decisive component — only as a last-resort CAPPED fallback.
        [$offset, $reResolved, $callers, $cyclomatic] = $this->reResolvedOffset($repoRoot, $relPath, $storedScore);

        // RAISE-ONLY band promotion from fresh measured truth: a genuinely wired+complex hub that the
        // coarse shape under-classified (e.g. missing the sibling-test boolean) is lifted to the
        // refactor band by re-measured callers+cyclomatic. Never demotes (obra stays obra; an
        // unmeasured/low target keeps its router band) — so the band can only GAIN accuracy.
        $band = $this->promoteBandFromGroundTruth($band, $reResolved, $callers, $cyclomatic);

        $priority = $band + $offset;

        $receipt = [
            'decider' => 'AtlasLoopNextWorkDecider',
            'shape' => $shape,
            'band' => $band,
            'offset' => $offset,
            'priority' => $priority,
            'reresolved' => $reResolved,
            'measured_callers' => $callers,
            'measured_cyclomatic' => $cyclomatic,
            'stored_score' => round($storedScore, 4),
            'target' => $relPath,
        ];
        if (! $reResolved) {
            $receipt['unmeasured_fallback'] = true;
        }

        return ['priority' => $priority, 'shape' => $shape, 'band' => $band, 'offset' => $offset, 'receipt' => $receipt];
    }

    /** Strictly-ordered band floor for an ALREADY-WHITELISTED shape. */
    private function bandFor(string $shape): int
    {
        return match ($shape) {
            AtlasLoopWorkShapeRouter::SHAPE_SKIP => self::BAND_SKIP,
            AtlasLoopWorkShapeRouter::SHAPE_REFACTOR,
            AtlasLoopWorkShapeRouter::SHAPE_EXTRACT_CLASS => self::BAND_REFACTOR,
            AtlasLoopWorkShapeRouter::SHAPE_MULTI_FILE,
            'obra', 'obra_candidate', 'multi_file_refactor' => self::BAND_OBRA,
            default => self::BAND_EDGE_FIX,
        };
    }

    /**
     * RAISE-ONLY: lift the band to the refactor floor when FRESH measured truth proves a wired,
     * complex hub the coarse shape missed. Never demotes (max), never fires on unmeasured data.
     */
    private function promoteBandFromGroundTruth(int $band, bool $reResolved, ?int $callers, ?int $cyclomatic): int
    {
        if (! $reResolved) {
            return $band;
        }
        $minCx = max(1, (int) config('atlas.loop.decision_min_refactor_cyclomatic', 10));
        if (is_int($callers) && $callers >= 1 && is_int($cyclomatic) && $cyclomatic >= $minCx) {
            return max($band, self::BAND_REFACTOR);
        }

        return $band;
    }

    /**
     * The leverage OFFSET in [0, BAND_WIDTH-1], re-resolved from ground truth, plus the measured
     * inputs (for raise-only band promotion).
     *
     * leverage = real_callers (capped, fresh grep) weighted with measured worst-method cyclomatic
     * (fresh AST). Both come from the working tree at $repoRoot NOW — not the stored score. When
     * BOTH fresh sources are unavailable, falls back to the stored score but CAPPED to
     * decision_unmeasured_offset_ceiling (default 199) so any genuinely measured target in the same
     * band sorts strictly above any fail-open-degraded one — a forged score cannot buy the slot.
     *
     * @return array{0:int, 1:bool, 2:?int, 3:?int} [offset, reResolved, measuredCallers, measuredCyclomatic]
     */
    private function reResolvedOffset(string $repoRoot, string $relPath, float $storedScore): array
    {
        $cap = self::BAND_WIDTH - 1;

        // 1. Fresh caller count (tri-state: int measured, null = unmeasured/fail-open). ALWAYS
        //    anchored to $repoRoot — the campaign workspace, never base_path().
        $callers = null;
        try {
            $counts = $this->callers($repoRoot)->callerCounts([$relPath]);
            $raw = $counts[ltrim($relPath, '/')] ?? ($counts[$relPath] ?? null);
            $callers = is_int($raw) ? max(0, $raw) : null;
        } catch (Throwable) {
            $callers = null;
        }

        // 2. Fresh worst-method cyclomatic from the working-tree source (not the stored signal).
        $cyclomatic = null;
        try {
            $abs = rtrim($repoRoot, '/').'/'.ltrim($relPath, '/');
            if (is_file($abs)) {
                $src = (string) @file_get_contents($abs);
                if ($src !== '') {
                    $cx = $this->analyzer()->fileComplexity($src);
                    if (($cx['measured'] ?? false) === true) {
                        $cyclomatic = max(0, (int) ($cx['max_per_method'] ?? 0));
                    }
                }
            }
        } catch (Throwable) {
            $cyclomatic = null;
        }

        // Neither fresh source available => fail-open to the stored score, CAPPED far below any
        // measured target so a degraded/forged read can never out-sort genuine measured work.
        if ($callers === null && $cyclomatic === null) {
            $ceiling = max(0, min($cap, (int) config('atlas.loop.decision_unmeasured_offset_ceiling', 199)));
            $fallback = (int) round(max(0.0, min(1.0, $storedScore)) * $ceiling);

            return [max(0, min($ceiling, $fallback)), false, null, null];
        }

        // Compose the fresh leverage. Callers are the dominant wiredness signal; cyclomatic adds the
        // refactor-worthiness. Both saturate so a single huge number can't blow the band.
        $callerComponent = $callers === null ? 0.0 : min(1.0, $callers / 20.0);   // 20+ callers saturates
        $cxComponent = $cyclomatic === null ? 0.0 : min(1.0, $cyclomatic / 30.0); // worst-method 30+ saturates
        $leverage = 0.65 * $callerComponent + 0.35 * $cxComponent;

        $offset = (int) round($leverage * $cap);

        return [max(0, min($cap, $offset)), true, $callers, $cyclomatic];
    }

    private function router(): AtlasLoopWorkShapeRouter
    {
        return $this->workShapeRouter ?? new AtlasLoopWorkShapeRouter;
    }

    /**
     * The caller resolver, ALWAYS anchored to the campaign workspace $repoRoot. Constructing fresh
     * per call (not trusting an injected, possibly base_path()-anchored instance) is the load-bearing
     * fix: callers MUST be grepped from the SAME tree the cyclomatic is read from, or the offset is
     * fabricated from the wrong repo. Mirrors AtlasLoopObraClusterDetectorService's anchoring.
     */
    private function callers(string $repoRoot): AtlasLoopWiredCallerService
    {
        return new AtlasLoopWiredCallerService($repoRoot);
    }

    private function analyzer(): AtlasLoopSignalAnalyzer
    {
        return $this->signalAnalyzer ?? new AtlasLoopSignalAnalyzer;
    }
}
