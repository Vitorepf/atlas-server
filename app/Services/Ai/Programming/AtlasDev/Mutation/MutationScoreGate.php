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
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M3 / E3,
 * e3-mutation-score-gate feature).
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
     * m3-e3 scrutiny Defect 3 (NON-BLOCKING): the config file casts the env
     * value via `(float)`, which silently turns a non-numeric env value
     * (e.g. ATLAS_DEV_ELEVATION_E3_THRESHOLD=banana) into 0.0 BEFORE the gate
     * sees it. A threshold of 0.0 DISABLES the gate (MSI >= 0.0 is always
     * true). To make the gate robust to BOTH the config-cast layer AND a
     * missing/invalid env, fromConfig reads the RAW env value (bypassing the
     * config cast) and validates it: invalid/non-numeric falls back to the
     * safe default 60.0, never 0.0. An explicit numeric "0" is honored as a
     * valid operator choice (the operator intentionally disabled the gate).
     *
     * The $e3Config is taken explicitly so callers resolve the mode through
     * the canonical {@see ElevationConfig::fromConfig('e3')} path (the same
     * path the other elevations use), keeping the resolution single-sourced.
     */
    public static function fromConfig(ElevationConfig $e3Config): self
    {
        $threshold = self::DEFAULT_THRESHOLD;
        try {
            // m3-e3 Defect 3: read the RAW env value (bypassing the config
            // (float) cast, which silently zeroes non-numeric values). The
            // raw env is the source of truth for "did the operator set a
            // valid numeric threshold?"; the config-cast value cannot
            // distinguish 'banana' (invalid -> should default) from '0'
            // (valid -> operator disabled the gate) because both yield 0.0
            // after the (float) cast.
            $raw = getenv('ATLAS_DEV_ELEVATION_E3_THRESHOLD');
            if ($raw === false || $raw === '') {
                // Fall back to the config-cast value if the env was not set
                // at all (the config default is DEFAULT_THRESHOLD). This keeps
                // the documented production default when the operator did not
                // set the env.
                $configValue = config('atlas_dev.elevations.e3.threshold');
                if (is_numeric($configValue)) {
                    $resolved = (float) $configValue;
                    if ($resolved >= 0.0 && $resolved <= 100.0) {
                        $threshold = $resolved;
                    }
                }
            } elseif (is_numeric($raw)) {
                $resolved = (float) $raw;
                if ($resolved >= 0.0 && $resolved <= 100.0) {
                    $threshold = $resolved;
                }
                // else: out-of-range numeric falls back to the safe default
                // (never 0.0, never the invalid value).
            }
            // else: non-numeric raw value (e.g. 'banana') => fall back to the
            // safe default (never 0.0, never the invalid value).
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

        $msi = $result->msi;

        // VAL-E3-007: MSI >= threshold (boundary inclusive) => pass.
        if ($msi >= $this->threshold) {
            return MutationScoreVerdict::pass($msi, $this->threshold);
        }

        // VAL-E3-002 / VAL-E3-003: MSI < threshold => trip.
        //   - advisory => honesty flag only (drives the downgrade, never
        //     STATUS_FAILED for the flag alone).
        //   - hard     => STATUS_FAILED gate channel (never just downgrades).
        // The flag is retained for auditability in both modes.
        $reason = sprintf(
            'e3: real reported MSI %.2f%% is below the configured threshold %.2f%%',
            $msi,
            $this->threshold,
        );

        return new MutationScoreVerdict(
            tripped: true,
            shouldFailGate: $this->e3Config->isHard(),
            honestyFlags: [MutationTestingAdapter::FLAG_MUTATION_SCORE_BELOW_THRESHOLD],
            isNoOp: false,
            noOpReason: '',
            reason: $reason,
            msi: $msi,
            threshold: $this->threshold,
        );
    }
}
