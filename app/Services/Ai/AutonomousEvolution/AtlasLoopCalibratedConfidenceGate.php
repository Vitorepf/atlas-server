<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ACDE lever DG1 — the calibrated-abstention MERGE gate (closes a live write->read pair).
 *
 * The auto-merge feeder already writes one {predicted, correct} confidence sample per merged proposal, and
 * {@see AtlasLoopConfidenceCalibrator} fits the HONEST `recommended_threshold` — the LOWEST cert-time confidence
 * at which observed post-merge precision actually meets the target — from those REAL outcomes. But that
 * threshold is consumed ONLY by a CLI; the live merge authority still merges on a hand-set/default confidence
 * threshold. DG1 reads the calibrated threshold back into the live gate: a certified proposal whose cert-time
 * delivery_confidence sits BELOW the empirically-precise band is ABSTAINED (parked for the operator) instead of
 * auto-merged.
 *
 * Why a weak engine beats a strong single pass: a strong pass merges on its own self-assessed confidence; DG1
 * makes the merge bar a function of the weak model's PROVEN calibration curve (overconfident models get a
 * higher bar automatically), so what lands is gated by measured precision, never a declared number. The
 * threshold is fit deterministically from machine-resolved post-merge outcomes — never an LLM grading itself.
 *
 * Fail-OPEN by construction: flag OFF, no samples table, not-yet-well-calibrated, a missing confidence signal,
 * or any error => abstain=false => the existing reprove + trust gates decide exactly as before (byte-identical).
 * The pure {@see recommendFrom} core is unit-testable without a database.
 */
final class AtlasLoopCalibratedConfidenceGate
{
    public function __construct(private readonly ?AtlasLoopConfidenceCalibrator $calibrator = null) {}

    /**
     * Decide whether a certified proposal must ABSTAIN (park) on calibrated confidence. Reads the cert-time
     * delivery_confidence from the proposal quality envelope and compares it to the fit threshold.
     *
     * @param  mixed  $quality  the proposal.quality envelope (array or json) carrying delivery_confidence.confidence
     * @return array{abstain:bool, predicted:?float, threshold:?float, calibrated:bool}
     */
    public function abstain(mixed $quality): array
    {
        $no = ['abstain' => false, 'predicted' => null, 'threshold' => null, 'calibrated' => false];

        if (! (bool) config('atlas.loop.calibrated_confidence_gate_enabled', false)) {
            return $no;
        }
        $predicted = data_get($quality, 'delivery_confidence.confidence');
        if (! is_numeric($predicted)) {
            return $no; // no cert-time confidence signal => never abstain
        }
        $threshold = $this->resolveThreshold();
        if ($threshold === null) {
            return $no; // not enough real outcomes to fit an honest band => never abstain on no evidence
        }

        return [
            'abstain' => $this->wouldAbstain($predicted, $threshold),
            'predicted' => (float) $predicted,
            'threshold' => $threshold,
            'calibrated' => true,
        ];
    }

    /** PURE — abstain iff there is a numeric prediction below a non-null calibrated threshold. */
    public function wouldAbstain(mixed $predicted, ?float $threshold): bool
    {
        return is_numeric($predicted) && $threshold !== null && (float) $predicted < $threshold;
    }

    /**
     * Resolve the empirically-calibrated confidence threshold from recent real post-merge outcomes, or null
     * when the table is absent / there are too few samples / the predictor is not well-calibrated. Fail-open.
     */
    public function resolveThreshold(): ?float
    {
        try {
            if (! DatabaseTableAvailability::has('atlas_loop_confidence_samples')) {
                return null;
            }
            $targetPrecision = max(0.0, min(1.0, (float) config('atlas.loop.calibrated_confidence_target_precision', 0.93)));
            $minSamples = max(2, (int) config('atlas.loop.calibrated_confidence_min_samples', 20));
            $windowDays = max(1, (int) config('atlas.loop.calibrated_confidence_window_days', 30));

            $samples = DB::table('atlas_loop_confidence_samples')
                ->where('created_at', '>=', Carbon::now()->subDays($windowDays))
                ->limit(5000)
                ->get(['predicted', 'correct'])
                ->map(static fn ($r): array => ['predicted' => (float) $r->predicted, 'correct' => (bool) $r->correct])
                ->all();

            return $this->recommendFrom($samples, $targetPrecision, $minSamples);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * PURE — fit the recommended threshold from {predicted, correct} samples via the calibrator, returning null
     * when the calibrator cannot recommend an honest threshold (too few samples at any band / not well-calibrated).
     *
     * @param  list<array{predicted:float, correct:bool}>  $samples
     */
    public function recommendFrom(array $samples, float $targetPrecision = 0.93, int $minSamples = 20): ?float
    {
        $calibration = ($this->calibrator ?? new AtlasLoopConfidenceCalibrator)->calibrate($samples, $targetPrecision, $minSamples);
        $threshold = $calibration['recommended_threshold'] ?? null;

        return is_numeric($threshold) ? (float) $threshold : null;
    }
}
