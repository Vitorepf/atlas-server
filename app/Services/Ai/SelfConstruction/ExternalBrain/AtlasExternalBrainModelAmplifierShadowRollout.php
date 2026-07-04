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

    /**
     * Compares the current (baseline) and candidate (amplified) decisions on
     * the same shadow cases — never mutating production decisions — and
     * recommends promote, keep_shadowing, or rollback.
     *
     * Promotion is blocked outright whenever the candidate's proxy_risk_rate
     * or give_back_risk_rate is higher than the baseline's, regardless of
     * any quality lift, because a candidate that games proxies or increases
     * give-backs is never safe to promote.
     *
     * DECISION (first matching rule wins):
     *   shadow_enabled=false                                   -> keep_shadowing
     *   amplified proxy_risk_rate > baseline proxy_risk_rate     -> rollback
     *   amplified give_back_risk_rate > baseline give_back_risk_rate -> rollback
     *   evaluate()'s safety_findings is non-empty                -> keep_shadowing
     *   evaluate()'s promotion_candidate=true                    -> promote
     *   otherwise                                                 -> keep_shadowing
     *
     * @param  array<string,mixed>  $input  same shape as evaluate(); proposals
     *   may additionally carry proxy_risk and give_back_risk booleans.
     * @return array<string,mixed>
     */
    public function recommendRollout(array $input): array
    {
        $shadowEnabled = (bool) ($input['shadow_enabled'] ?? true);
        $baseline = (array) ($input['baseline_proposals'] ?? []);
        $amplified = (array) ($input['amplified_proposals'] ?? []);

        $evaluation = $this->evaluate($input);

        $baselineRiskRates = $this->riskRates($baseline);
        $amplifiedRiskRates = $this->riskRates($amplified);

        $proxyRiskIncreased = $amplifiedRiskRates['proxy_risk_rate'] > $baselineRiskRates['proxy_risk_rate'];
        $giveBackRiskIncreased = $amplifiedRiskRates['give_back_risk_rate'] > $baselineRiskRates['give_back_risk_rate'];

        [$recommendation, $reason] = match (true) {
            ! $shadowEnabled => ['keep_shadowing', 'shadow_disabled'],
            $proxyRiskIncreased => ['rollback', 'candidate_increases_proxy_risk'],
            $giveBackRiskIncreased => ['rollback', 'candidate_increases_give_back_risk'],
            $evaluation['safety_findings'] !== [] => ['keep_shadowing', 'safety_findings_present'],
            $evaluation['promotion_candidate'] => ['promote', 'lift_proven_with_no_regressions'],
            default => ['keep_shadowing', 'insufficient_lift_evidence'],
        };

        return [
            'schema' => self::SCHEMA,
            'recommendation' => $recommendation,
            'reason' => $reason,
            'baseline_risk_rates' => $baselineRiskRates,
            'amplified_risk_rates' => $amplifiedRiskRates,
            'baseline_summary' => $evaluation['baseline_summary'],
            'amplified_summary' => $evaluation['amplified_summary'],
            'safety_findings' => $evaluation['safety_findings'],
        ];
    }

    /** @return array{proxy_risk_rate:float, give_back_risk_rate:float} */
    private function riskRates(array $proposals): array
    {
        $n = count($proposals);
        if ($n === 0) {
            return ['proxy_risk_rate' => 0.0, 'give_back_risk_rate' => 0.0];
        }

        $proxyRiskCount = 0;
        $giveBackRiskCount = 0;
        foreach ($proposals as $p) {
            $proxyRiskCount += (int) (bool) ($p['proxy_risk'] ?? false);
            $giveBackRiskCount += (int) (bool) ($p['give_back_risk'] ?? false);
        }

        return [
            'proxy_risk_rate' => round($proxyRiskCount / $n, 4),
            'give_back_risk_rate' => round($giveBackRiskCount / $n, 4),
        ];
    }

    /**
     * Held-out promotion evidence: requires held_out_sample_count and held_out_pass_rate
     * before promotion_candidate can be true. Blocks promotion when amplified proposals
     * improve quality but increase proxy_leak_rate or duplicate_rate.
     *
     * @param  array{
     *   held_out_sample_count?: int,
     *   held_out_pass_count?: int,
     *   proxy_leak_rate?: float,
     *   duplicate_rate?: float,
     *   quality_lift?: float,
     *   baseline_proxy_leak_rate?: float,
     *   baseline_duplicate_rate?: float,
     * }  $input
     * @return array{
     *   promotion_evidence: array<string,mixed>,
     *   held_out_summary: array{held_out_sample_count:int, held_out_pass_rate:float},
     *   safety_findings: list<string>,
     *   promotion_candidate: bool,
     * }
     */
    public function heldOutPromotionEvidence(array $input): array
    {
        $heldOutSampleCount = (int) ($input['held_out_sample_count'] ?? 0);
        $heldOutPassCount = (int) ($input['held_out_pass_count'] ?? 0);
        $proxyLeakRate = (float) ($input['proxy_leak_rate'] ?? 0.0);
        $duplicateRate = (float) ($input['duplicate_rate'] ?? 0.0);
        $qualityLift = (float) ($input['quality_lift'] ?? 0.0);
        $baselineProxyLeakRate = (float) ($input['baseline_proxy_leak_rate'] ?? 0.0);
        $baselineDuplicateRate = (float) ($input['baseline_duplicate_rate'] ?? 0.0);

        $heldOutPassRate = $heldOutSampleCount > 0 ? round($heldOutPassCount / $heldOutSampleCount, 4) : 0.0;

        $safetyFindings = [];

        // Check 1: held-out sample required
        if ($heldOutSampleCount === 0) {
            $safetyFindings[] = 'missing_held_out_sample: cannot promote without held-out evidence';
        }

        // Check 2: proxy leak rate increase blocks promotion
        if ($proxyLeakRate > $baselineProxyLeakRate) {
            $safetyFindings[] = sprintf(
                'proxy_leak_increase: amplified=%.4f > baseline=%.4f',
                $proxyLeakRate,
                $baselineProxyLeakRate,
            );
        }

        // Check 3: duplicate rate increase blocks promotion
        if ($duplicateRate > $baselineDuplicateRate) {
            $safetyFindings[] = sprintf(
                'duplicate_rate_increase: amplified=%.4f > baseline=%.4f',
                $duplicateRate,
                $baselineDuplicateRate,
            );
        }

        $promotionCandidate = $safetyFindings === []
            && $heldOutSampleCount > 0
            && $heldOutPassRate > 0.0
            && $qualityLift > 0.0;

        return [
            'promotion_evidence' => [
                'quality_lift' => $qualityLift,
                'proxy_leak_rate' => $proxyLeakRate,
                'duplicate_rate' => $duplicateRate,
                'held_out_pass_rate' => $heldOutPassRate,
            ],
            'held_out_summary' => [
                'held_out_sample_count' => $heldOutSampleCount,
                'held_out_pass_rate' => $heldOutPassRate,
            ],
            'safety_findings' => $safetyFindings,
            'promotion_candidate' => $promotionCandidate,
        ];
    }
}
