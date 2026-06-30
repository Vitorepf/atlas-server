<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure shadow rollout evaluator. Compares baseline vs. amplified proposals
 * using recorded quality metrics without affecting the live task queue.
 *
 * AC2: shadow proposals are NEVER enqueued; the service is read-only.
 *
 * AC3: records quality_lift, duplicate_risk, scaffold_compliance, replay_pass_rate,
 *      and value_density_delta.
 *
 * promotion_candidate = true when amplified beats baseline on ALL of:
 *   - avg_quality_score (by ≥ LIFT_THRESHOLD)
 *   - duplicate_rate    (amplified ≤ baseline)
 *   - scaffold_compliance_rate (amplified ≥ baseline)
 *   - replay_pass_rate  (amplified ≥ baseline)
 *   - avg_value_density (amplified ≥ baseline)
 *
 * safety_findings: list of concerns raised when amplified is worse than baseline
 *   on any dimension, or when shadow_enabled=false.
 *
 * AC4: output always includes shadow_enabled, baseline_summary, amplified_summary,
 *      promotion_candidate, and safety_findings.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainModelAmplifierShadowRollout
{
    public const SCHEMA = 'atlas.external_brain.model_amplifier_shadow_rollout.v1';

    private const LIFT_THRESHOLD = 0.05; // amplified must be at least 5% higher quality

    /**
     * @param  array{
     *   shadow_enabled?: bool,
     *   baseline_proposals?: list<array<string,mixed>>,
     *   amplified_proposals?: list<array<string,mixed>>,
     * }  $input
     * @return array{schema:string, shadow_enabled:bool, baseline_summary:array<string,mixed>, amplified_summary:array<string,mixed>, promotion_candidate:bool, safety_findings:list<string>}
     */
    public function evaluate(array $input): array
    {
        $shadowEnabled = (bool) ($input['shadow_enabled'] ?? true);
        $baseline      = (array) ($input['baseline_proposals']   ?? []);
        $amplified     = (array) ($input['amplified_proposals']  ?? []);

        $baselineSummary  = $this->summarize($baseline);
        $amplifiedSummary = $this->summarize($amplified);

        $safetyFindings     = [];
        $promotionCandidate = false;

        if (! $shadowEnabled) {
            $safetyFindings[] = 'shadow_disabled:promotion_blocked_until_shadow_enabled';
        } else {
            $safetyFindings = $this->computeSafetyFindings($baselineSummary, $amplifiedSummary, $amplified);
            $promotionCandidate = $safetyFindings === []
                && $this->meetsPromotion($baselineSummary, $amplifiedSummary);
        }

        return [
            'schema'              => self::SCHEMA,
            'shadow_enabled'      => $shadowEnabled,
            'baseline_summary'    => $baselineSummary,
            'amplified_summary'   => $amplifiedSummary,
            'promotion_candidate' => $promotionCandidate,
            'safety_findings'     => $safetyFindings,
        ];
    }

    /** @return array<string,mixed> */
    private function summarize(array $proposals): array
    {
        $n = count($proposals);
        if ($n === 0) {
            return [
                'count'                   => 0,
                'avg_quality_score'        => 0.0,
                'duplicate_rate'           => 0.0,
                'scaffold_compliance_rate' => 0.0,
                'replay_pass_rate'         => 0.0,
                'avg_value_density'        => 0.0,
            ];
        }

        $qualitySum    = 0.0;
        $dupCount      = 0;
        $scaffoldCount = 0;
        $replayCount   = 0;
        $densitySum    = 0.0;

        foreach ($proposals as $p) {
            $qualitySum    += max(0.0, min(1.0, (float) ($p['quality_score']   ?? 0.0)));
            $densitySum    += max(0.0, min(1.0, (float) ($p['value_density']   ?? 0.0)));
            $dupCount      += (int) (bool) ($p['has_duplicate']       ?? false);
            $scaffoldCount += (int) (bool) ($p['scaffold_compliant']  ?? false);
            $replayCount   += (int) (bool) ($p['replay_passes']       ?? false);
        }

        return [
            'count'                   => $n,
            'avg_quality_score'        => round($qualitySum / $n, 4),
            'duplicate_rate'           => round($dupCount / $n, 4),
            'scaffold_compliance_rate' => round($scaffoldCount / $n, 4),
            'replay_pass_rate'         => round($replayCount / $n, 4),
            'avg_value_density'        => round($densitySum / $n, 4),
        ];
    }

    private function meetsPromotion(array $b, array $a): bool
    {
        if ($a['count'] === 0 || $b['count'] === 0) {
            return false;
        }

        return ($a['avg_quality_score'] - $b['avg_quality_score']) >= self::LIFT_THRESHOLD
            && $a['duplicate_rate']           <= $b['duplicate_rate']
            && $a['scaffold_compliance_rate'] >= $b['scaffold_compliance_rate']
            && $a['replay_pass_rate']          >= $b['replay_pass_rate']
            && $a['avg_value_density']         >= $b['avg_value_density'];
    }

    /** @return list<string> */
    private function computeSafetyFindings(array $b, array $a, array $amplifiedProposals): array
    {
        $findings = [];

        if ($a['count'] === 0) {
            $findings[] = 'amplified_proposals_empty:no_lift_measurable';
            return $findings;
        }

        if ($a['avg_quality_score'] < $b['avg_quality_score']) {
            $findings[] = sprintf(
                'quality_regression:amplified_avg=%.4f < baseline_avg=%.4f',
                $a['avg_quality_score'],
                $b['avg_quality_score'],
            );
        }

        if ($a['duplicate_rate'] > $b['duplicate_rate']) {
            $findings[] = sprintf(
                'duplicate_risk_increase:amplified_rate=%.4f > baseline_rate=%.4f',
                $a['duplicate_rate'],
                $b['duplicate_rate'],
            );
        }

        if ($a['scaffold_compliance_rate'] < $b['scaffold_compliance_rate']) {
            $findings[] = sprintf(
                'scaffold_compliance_regression:amplified=%.4f < baseline=%.4f',
                $a['scaffold_compliance_rate'],
                $b['scaffold_compliance_rate'],
            );
        }

        if ($a['replay_pass_rate'] < $b['replay_pass_rate']) {
            $findings[] = sprintf(
                'replay_court_regression:amplified=%.4f < baseline=%.4f',
                $a['replay_pass_rate'],
                $b['replay_pass_rate'],
            );
        }

        if ($a['avg_value_density'] < $b['avg_value_density']) {
            $findings[] = sprintf(
                'value_density_regression:amplified=%.4f < baseline=%.4f',
                $a['avg_value_density'],
                $b['avg_value_density'],
            );
        }

        return $findings;
    }
}
