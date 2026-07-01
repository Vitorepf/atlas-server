<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure verifier that a scaffold lift counts as useful only when receipts show
 * real improvement in task quality, give_back rate, or proof coverage versus baseline.
 *
 * Rejects cosmetic formatting changes with no quality delta.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainScaffoldLiftReceiptVerifier
{
    public const SCHEMA = 'atlas.external_brain.scaffold_lift_receipt_verifier.v1';

    public const VERDICT_MEASURED_LIFT = 'measured_lift';

    public const VERDICT_NO_LIFT = 'no_measured_lift';

    /**
     * @param  array{
     *   give_back_rate?:float,
     *   proof_coverage?:float,
     *   task_quality_score?:float,
     * }  $baseline
     * @param  array{
     *   give_back_rate?:float,
     *   proof_coverage?:float,
     *   task_quality_score?:float,
     * }  $candidate
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   baseline:array<string,mixed>,
     *   candidate:array<string,mixed>,
     *   decision:string,
     *   reasons:list<string>,
     * }
     */
    public function verify(array $baseline, array $candidate): array
    {
        $bGiveBack = (float) ($baseline['give_back_rate'] ?? 1.0);
        $cGiveBack = (float) ($candidate['give_back_rate'] ?? 1.0);
        $bProofCoverage = (float) ($baseline['proof_coverage'] ?? 0.0);
        $cProofCoverage = (float) ($candidate['proof_coverage'] ?? 0.0);
        $bQuality = (float) ($baseline['task_quality_score'] ?? 0.0);
        $cQuality = (float) ($candidate['task_quality_score'] ?? 0.0);

        $reasons = [];
        $hasLift = false;

        // Check give_back rate improvement (lower is better)
        if ($cGiveBack < $bGiveBack) {
            $reasons[] = 'give_back_improved:' . round($bGiveBack, 4) . '→' . round($cGiveBack, 4);
            $hasLift = true;
        }

        // Check proof coverage improvement (higher is better), but only if not worse
        if ($cProofCoverage > $bProofCoverage) {
            $reasons[] = 'proof_coverage_improved:' . round($bProofCoverage, 4) . '→' . round($cProofCoverage, 4);
            $hasLift = true;
        }

        // Check quality score improvement (higher is better)
        if ($cQuality > $bQuality) {
            $reasons[] = 'quality_score_improved:' . round($bQuality, 4) . '→' . round($cQuality, 4);
            $hasLift = true;
        }

        // Require that give_back improvement doesn't come with worse proof coverage
        $proofDegraded = $cProofCoverage < $bProofCoverage;
        if ($proofDegraded) {
            $reasons[] = 'proof_coverage_degraded';
        }

        // Lift requires at least one improvement AND no degradation in other dimensions
        $hasMeasuredLift = $hasLift && ! $proofDegraded;

        $verdict = $hasMeasuredLift ? self::VERDICT_MEASURED_LIFT : self::VERDICT_NO_LIFT;
        $decision = $hasMeasuredLift
            ? 'accept scaffold: measurable quality lift detected'
            : 'reject scaffold: no measurable quality lift';

        if (! $hasLift) {
            $reasons[] = 'no_quality_delta_detected';
        }

        sort($reasons, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'baseline' => $baseline,
            'candidate' => $candidate,
            'decision' => $decision,
            'reasons' => $reasons,
        ];
    }
}
