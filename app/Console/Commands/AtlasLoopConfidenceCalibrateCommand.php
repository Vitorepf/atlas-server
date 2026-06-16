<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopConfidenceSample;
use App\Services\Ai\AutonomousEvolution\AtlasLoopConfidenceCalibrator;
use Illuminate\Console\Command;

/**
 * Confidence-calibration flywheel (ITEM10) — REPORT-ONLY.
 *
 * Reads every {predicted, correct} sample the auto-merge feeder accrued post-merge and runs
 * AtlasLoopConfidenceCalibrator::calibrate() to derive the HONEST recommended gate threshold:
 * the lowest confidence at which observed precision clears the target with enough samples. Until
 * recommended_threshold is non-null (n large enough at the target precision), there is not enough
 * evidence to arm — the honest answer is "insufficient evidence".
 *
 * This command NEVER mutates config/env and NEVER arms any gate. The confidence gate
 * (atlas.loop.confidence_gate_enabled) stays operator-armed: the operator reads this number and
 * arms it deliberately once the evidence is there.
 */
class AtlasLoopConfidenceCalibrateCommand extends Command
{
    protected $signature = 'atlas:loop:confidence-calibrate {--json : Canonical JSON output}';

    protected $description = 'Report-only: fit the HONEST confidence arm-threshold from real post-merge outcomes (never arms the gate).';

    public function handle(AtlasLoopConfidenceCalibrator $calibrator): int
    {
        $samples = AtlasLoopConfidenceSample::query()
            ->get()
            ->map(static fn (AtlasLoopConfidenceSample $r): array => [
                'predicted' => (float) $r->predicted,
                'correct' => (bool) $r->correct,
            ])
            ->all();

        $result = $calibrator->calibrate(
            $samples,
            (float) config('atlas.loop.confidence_calibration.target_precision', 0.93),
            (int) config('atlas.loop.confidence_calibration.min_samples', 20),
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Samples (n)', (string) ($result['n'] ?? 0));
        $this->components->twoColumnDetail('Target precision', (string) ($result['target_precision'] ?? 0.0));
        $this->components->twoColumnDetail('Brier score', (string) ($result['brier'] ?? 0.0));
        $this->components->twoColumnDetail('Mean predicted', (string) ($result['mean_predicted'] ?? 0.0));
        $this->components->twoColumnDetail('Observed accuracy', (string) ($result['observed_accuracy'] ?? 0.0));
        $this->components->twoColumnDetail('Overconfidence', (string) ($result['overconfidence'] ?? 0.0));
        $this->components->twoColumnDetail('Well calibrated', ($result['well_calibrated'] ?? false) ? 'yes' : 'no');

        if (($result['recommended_threshold'] ?? null) !== null) {
            $this->components->info('Recommended arm threshold: '.(string) $result['recommended_threshold'].' (operator arms confidence_gate_enabled deliberately; this command never arms it).');

            return self::SUCCESS;
        }

        $this->components->warn('Insufficient evidence to arm (n='.(string) ($result['n'] ?? 0).'): no threshold reaches the target precision yet — keep the gate OFF.');

        return self::SUCCESS;
    }
}
