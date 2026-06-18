<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;

/**
 * E3 — Mutation-score gate.
 *
 * The gate that reads the REAL infection-reported MSI (parsed by
 * {@see MutationTestingAdapter} from the infection summary JSON) and decides
 * the verdict as a PURE function of (msi, threshold, mode) — VAL-E3-007.
 * There is no self-declared score: the gate consumes the structured
 * {@see MutationTestingResult} the adapter produced, and the adapter refuses
 * to fabricate an MSI over a failed/unevaluable run (VAL-E3-011 honest
 * ceiling).
 *
 * Verdict channels (the two sanctioned channels, no third way, no silent
 * green):
 *
 *   - ADVISORY (e3.mode=advisory): when the MSI is below the threshold (or
 *      the run failed so the MSI is unevaluable), the gate trips and
 *      appends the honesty flag {@see MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD}.
 *      The CompletionStateGate then auto-downgrades PASSED -> needs_review
 *      (the passed-forbids-flags ctor invariant guarantees no green-with-
 *      flag). Advisory NEVER forces STATUS_FAILED for the flag alone
 *      (VAL-E3-002, VAL-E3-009).
 *
 *   - HARD (e3.mode=hard): the same trip routes to STATUS_FAILED (the
 *      sanctioned hard gate channel) so completion resolves to failed/
 *      blocked. The flag is retained for auditability. Hard NEVER just
 *      downgrades to needs_review on a real below-threshold MSI
 *      (VAL-E3-003).
 *
 *   - OFF (e3.mode=off): the gate is a no-op — no flag, no STATUS_FAILED,
 *      no infection invoked (the executor must skip the adapter invocation
 *      entirely so the run is byte-identical to pre-E3, VAL-E3-010).
 *
 *   - SKIPPED result (no test files touched, VAL-E3-008): a no-op — never a
 *      false fail. A patch with no test files cannot be mutation-tested, so
 *      the gate surfaces a documented no-op reason rather than tripping.
 *
 * Boundary: MSI == threshold passes (boundary inclusive), so a robust test
 * sitting exactly at the configured floor is not penalised (VAL-E3-004).
 *
 * Honest ceiling on a failed run (VAL-E3-011): when the adapter surfaced a
 * non-skipped FAILURE (infection exited non-zero, missing driver, or no MSI
 * could be parsed), the MSI is NULL. The gate MUST NOT silently green that
 * case in a non-off mode — there is no real score to read. In advisory the
 * gate appends a honesty flag carrying the failure context; in hard the gate
 * fail-closes (shouldFailGate=true). It never fabricates an MSI.
 *
 * Anti-gaming (e3-mutation-anti-gaming feature):
 *
 *   - VAL-E3-005/006: the gate uses the REAL MSI
 *     ({@see MutationTestingResult::realMsi}), recomputed over the FULL
 *     applicable mutant population (totalMutantsCount as denominator), NOT
 *     infection's self-reported stats.msi (which subtracts skipped/ignored
 *     from the denominator and can be inflated by a patch config that marks
 *     survivors as ignored). Mutator-skipping is defeated structurally: the
 *     adapter never passes --mutators=, so totalMutantsCount IS the full
 *     population. When realMsi is unavailable (legacy adapter / degraded
 *     run), the gate falls back to the reported msi.
 *
 *   - VAL-E3-013: for a multi-test-file patch, the gate also checks
 *     per-source-file MSI ({@see MutationTestingResult::perFileStats}). If
 *     ANY source file's MSI is below the threshold, the gate trips — the
 *     weak file is NOT masked by a high aggregate MSI.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M3 / E3).
 */
final class MutationScoreGate
{
    /**
     * Default MSI threshold (percent). A patch whose real reported MSI is
     * below this trips the gate (advisory flag / hard STATUS_FAILED). The
     * threshold is operator-tunable via the `atlas_dev.elevations.e3.threshold`
     * config (env: ATLAS_DEV_ELEVATION_E3_THRESHOLD).
     */
    public const DEFAULT_THRESHOLD = 60.0;

    /**
     * An advisory honesty flag appended when infection reported a real
     * failure (NULL MSI) and the gate ran in a non-off mode. Distinct from
     * the below-threshold flag so receipts/decisions are auditable: a below-
     * threshold MSI is a weak test; a NULL MSI is an unevaluable run.
     */
    public const FLAG_MUTATION_RUN_FAILED = 'mutation_run_failed';

    public function __construct(
        private readonly ElevationConfig $e3Config,
        private readonly float $threshold = self::DEFAULT_THRESHOLD,
    ) {}

    /**
     * Resolve the gate from the live config kernel. The threshold is read
     * from `atlas_dev.elevations.e3.threshold` (env:
     * ATLAS_DEV_ELEVATION_E3_THRESHOLD, default {@see DEFAULT_THRESHOLD}).
     * Missing/invalid values resolve to the documented safe default without
     * throwing (mirrors the ElevationConfig safe-default convention).
     *
     * The $e3Config is taken explicitly so callers resolve the mode through
     * the canonical {@see ElevationConfig::fromConfig('e3')} path (the same
     * path the other elevations use), keeping the resolution single-sourced.
     */
    public static function fromConfig(ElevationConfig $e3Config): self
    {
        $threshold = self::DEFAULT_THRESHOLD;
        try {
            $raw = config('atlas_dev.elevations.e3.threshold');
            if (is_numeric($raw)) {
                $resolved = (float) $raw;
                if ($resolved >= 0.0 && $resolved <= 100.0) {
                    $threshold = $resolved;
                }
            }
        } catch (\Throwable) {
            // Degrade to the documented default (no crash, no silent disable).
            $threshold = self::DEFAULT_THRESHOLD;
        }

        return new self($e3Config, $threshold);
    }

    public function threshold(): float
    {
        return $this->threshold;
    }

    /**
     * Compute the verdict for a {@see MutationTestingResult}. Pure: same
     * (result, threshold, mode) always yields the same verdict
     * (VAL-E3-007: a pure function of the real reported MSI).
     *
     * Anti-gaming (e3-mutation-anti-gaming):
     *   - The gated MSI is the REAL MSI over the full population
     *     ({@see MutationTestingResult::realMsi}), recomputed from raw counts
     *     so survivor-exclusion cannot inflate it (VAL-E3-006). Falls back to
     *     infection's reported msi when realMsi is unavailable (legacy/
     *     degraded run).
     *   - For multi-file patches, if ANY source file's per-file MSI is below
     *     the threshold, the gate trips (VAL-E3-013: a weak file is not
     *     masked by a high aggregate MSI).
     */
    public function evaluate(MutationTestingResult $result): MutationScoreVerdict
    {
        // VAL-E3-010: off mode => byte-identical no-op. No flag, no fail, no
        // surfacing at all. The executor additionally skips the adapter
        // invocation entirely so infection is never even run.
        if ($this->e3Config->isOff()) {
            return MutationScoreVerdict::noOp(
                'e3.mode=off: mutation gate is byte-identical to pre-E3',
                $this->threshold,
            );
        }

        // VAL-E3-008: a skipped result (no test files touched, or off-mode at
        // the adapter level) is a documented no-op. Never a false fail.
        if ($result->skipped) {
            return MutationScoreVerdict::noOp(
                $result->skipReason !== ''
                    ? $result->skipReason
                    : 'e3: mutation gate skipped (no scope)',
                $this->threshold,
            );
        }

        // Honest ceiling (VAL-E3-011): a non-skipped FAILED result carries a
        // NULL MSI. The gate MUST NOT silently green that case in a non-off
        // mode — there is no real score to read. Advisory => flag; hard =>
        // fail-closed. Never fabricates an MSI.
        if ($result->failed || $result->msi === null) {
            $reason = $result->failureReason !== ''
                ? $result->failureReason
                : 'e3: infection completed but no MSI could be read (refusing to fabricate a score)';
            $flags = $this->e3Config->isHard()
                ? [
                    MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD,
                    self::FLAG_MUTATION_RUN_FAILED,
                ]
                : [self::FLAG_MUTATION_RUN_FAILED];

            return new MutationScoreVerdict(
                tripped: true,
                shouldFailGate: $this->e3Config->isHard(),
                honestyFlags: $flags,
                isNoOp: false,
                noOpReason: '',
                reason: $reason,
                msi: null,
                threshold: $this->threshold,
            );
        }

        // Anti-gaming (VAL-E3-005/006): the gated MSI is the REAL MSI over
        // the full applicable population (totalMutantsCount as denominator),
        // recomputed by the adapter from the raw summary counts. This is
        // immune to survivor-exclusion (a patch config that marks survivors
        // as ignored cannot raise it). Falls back to the reported msi when
        // realMsi is unavailable (legacy adapter / degraded run).
        $gatedMsi = $result->realMsi ?? $result->msi;

        // VAL-E3-013: for a multi-test-file patch, check per-source-file MSI.
        // A weak file (below threshold) among strong ones is NOT masked by a
        // high aggregate MSI — the gate trips if ANY file is below threshold.
        $weakFiles = $this->findWeakFiles($result->perFileStats);

        // VAL-E3-007: gated MSI >= threshold (boundary inclusive) AND no weak
        // file => pass.
        if ($gatedMsi >= $this->threshold && $weakFiles === []) {
            return MutationScoreVerdict::pass($gatedMsi, $this->threshold);
        }

        // Build the trip reason: cite the real MSI and/or the weak files.
        $reasonParts = [];
        if ($gatedMsi < $this->threshold) {
            $reasonParts[] = sprintf(
                'e3: real MSI %.2f%% is below the configured threshold %.2f%%',
                $gatedMsi,
                $this->threshold,
            );
        }
        foreach ($weakFiles as $file => $fileMsi) {
            $reasonParts[] = sprintf(
                'e3: source file %s has MSI %.2f%% (below threshold %.2f%%) — weak file not masked by aggregate',
                $file,
                $fileMsi,
                $this->threshold,
            );
        }
        $reason = implode('; ', $reasonParts);

        // VAL-E3-002 / VAL-E3-003: trip.
        //   - advisory => honesty flag only (drives the downgrade, never
        //     STATUS_FAILED for the flag alone).
        //   - hard     => STATUS_FAILED gate channel (never just downgrades).
        // The flag is retained for auditability in both modes.
        return new MutationScoreVerdict(
            tripped: true,
            shouldFailGate: $this->e3Config->isHard(),
            honestyFlags: [MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD],
            isNoOp: false,
            noOpReason: '',
            reason: $reason,
            msi: $gatedMsi,
            threshold: $this->threshold,
        );
    }

    /**
     * Find source files whose per-file MSI is below the threshold
     * (VAL-E3-013). Returns an associative array [file => msi] for each weak
     * file. Empty when per-file stats are unavailable (single-file scope,
     * degraded run) or all files are at/above the threshold.
     *
     * @param  ?array<string,array{msi:float,killed:int,escaped:int,total:int}>  $perFileStats
     * @return array<string,float>
     */
    private function findWeakFiles(?array $perFileStats): array
    {
        if ($perFileStats === null || $perFileStats === []) {
            return [];
        }
        $weak = [];
        foreach ($perFileStats as $file => $stats) {
            $fileMsi = $stats['msi'] ?? 0.0;
            if ($fileMsi < $this->threshold) {
                $weak[$file] = $fileMsi;
            }
        }

        return $weak;
    }
}
