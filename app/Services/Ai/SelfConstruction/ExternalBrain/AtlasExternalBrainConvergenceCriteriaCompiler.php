<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Compiles final-95 readiness, brain health, queue health, value yield, outcome
 * learning, simplification, provider independence, and readiness-gap evidence
 * into one HONEST convergence verdict for long-running brain campaigns.
 *
 * VERDICT / convergence_status (first match wins):
 *   convergence_ready — ALL required pillars meet their threshold with CLEAN evidence
 *   consolidate        — enough organs / partial proof exist, but integration or
 *                         evidence closure is incomplete (passing_pillars >=
 *                         CONSOLIDATE_PILLAR_FLOOR AND integration_organs_count >=
 *                         CONSOLIDATE_ORGANS_FLOOR)
 *   keep_building       — any required pillar lacks proof (fallback)
 *
 * A pillar's score ALONE is never sufficient — evidence is also refused when:
 *   self_declared = true       — the score was asserted by the same system being
 *                                 judged, not externally verified.
 *   age_days > MAX_EVIDENCE_AGE_DAYS — the evidence is stale and may no longer
 *                                 reflect current state.
 * Either condition forces that pillar to fail regardless of score, and is
 * recorded in missing_proof with the concrete reason.
 *
 * REQUIRED PILLARS AND DEFAULT THRESHOLDS (category in parens):
 *   final_95_readiness            >= 0.95  (readiness gaps)
 *   brain_health_score            >= 0.70  (brain health)
 *   queue_health_score            >= 0.70  (queue health)
 *   autonomy_independence_score   >= 0.80  (provider independence)
 *   learning_loop_closedness      >= 0.75  (outcome learning)
 *   worker_proof_count            >= 5     (worker proof, int comparison)
 *   compounding_evidence_score    >= 0.60  (value yield)
 *   simplification_score          >= 0.70  (simplification)
 *
 * INPUT:
 *   <pillar>:              float|int  the pillar's measured score
 *   evidence_meta: map<pillar, {age_days?: int, self_declared?: bool}>
 *   thresholds:             map<pillar, float>  per-pillar threshold overrides
 *   integration_organs_count: int
 *   max_evidence_age_days?:  int (default 30)
 *
 * OUTPUT:
 *   { schema, verdict, convergence_status, failing_pillars, missing_proof,
 *     next_action, next_verification_step, evidence_receipts }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainConvergenceCriteriaCompiler
{
    public const SCHEMA = 'atlas.external_brain.convergence_criteria_compiler.v1';

    public const VERDICT_CONVERGENCE_READY = 'convergence_ready';
    public const VERDICT_CONSOLIDATE = 'consolidate';
    public const VERDICT_KEEP_BUILDING = 'keep_building';

    private const CONSOLIDATE_PILLAR_FLOOR = 3;
    private const CONSOLIDATE_ORGANS_FLOOR = 3;
    private const DEFAULT_MAX_EVIDENCE_AGE_DAYS = 30;

    private const PILLAR_DEFAULTS = [
        'final_95_readiness' => 0.95,
        'brain_health_score' => 0.70,
        'queue_health_score' => 0.70,
        'autonomy_independence_score' => 0.80,
        'learning_loop_closedness' => 0.75,
        'worker_proof_count' => 5.0,
        'compounding_evidence_score' => 0.60,
        'simplification_score' => 0.70,
    ];

    private const PILLAR_VERIFICATION_STEPS = [
        'final_95_readiness' => 'Run the final-95 readiness gate and inspect remaining gaps before claiming readiness.',
        'brain_health_score' => 'Re-run the brain health diagnostic and review any unhealthy organ.',
        'queue_health_score' => 'Inspect atlas:task health for jams, lease leaks, or stale backlog.',
        'autonomy_independence_score' => 'Re-run the provider pool independence gate to confirm no provider is required_for_steady_state.',
        'learning_loop_closedness' => 'Verify outcome learning actually records and feeds back real task outcomes, not a stub.',
        'worker_proof_count' => 'Collect more independently verified worker completions before claiming proof.',
        'compounding_evidence_score' => 'Re-run the impact backtest harness and confirm predicted leverage matches actual outcomes.',
        'simplification_score' => 'Re-run a ponytail-style simplification audit and confirm dead/duplicate code was actually removed.',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $thresholdOverrides = is_array($input['thresholds'] ?? null) ? $input['thresholds'] : [];
        $integrationOrgansCount = max(0, (int) ($input['integration_organs_count'] ?? 0));
        $evidenceMeta = is_array($input['evidence_meta'] ?? null) ? $input['evidence_meta'] : [];
        $maxEvidenceAgeDays = (int) ($input['max_evidence_age_days'] ?? self::DEFAULT_MAX_EVIDENCE_AGE_DAYS);

        $evidenceReceipts = [];
        $failingPillars = [];
        $missingProof = [];
        $passingCount = 0;

        foreach (self::PILLAR_DEFAULTS as $pillar => $defaultThreshold) {
            $threshold = isset($thresholdOverrides[$pillar])
                ? (float) $thresholdOverrides[$pillar]
                : (float) $defaultThreshold;

            $score = isset($input[$pillar]) ? (float) $input[$pillar] : 0.0;
            $meta = is_array($evidenceMeta[$pillar] ?? null) ? $evidenceMeta[$pillar] : [];
            $ageDays = (int) ($meta['age_days'] ?? 0);
            $selfDeclared = (bool) ($meta['self_declared'] ?? false);
            $isStale = $ageDays > $maxEvidenceAgeDays;

            $meetsThreshold = $score >= $threshold;
            $passes = $meetsThreshold && ! $isStale && ! $selfDeclared;

            $rejectionReasons = [];
            if (! $meetsThreshold) {
                $rejectionReasons[] = 'score_below_threshold';
            }
            if ($selfDeclared) {
                $rejectionReasons[] = 'self_declared_not_externally_verified';
            }
            if ($isStale) {
                $rejectionReasons[] = sprintf('evidence_stale_age_days_%d_exceeds_max_%d', $ageDays, $maxEvidenceAgeDays);
            }

            $evidenceReceipts[$pillar] = [
                'score' => $score,
                'threshold' => $threshold,
                'self_declared' => $selfDeclared,
                'age_days' => $ageDays,
                'passes' => $passes,
                'rejection_reasons' => $rejectionReasons,
            ];

            if ($passes) {
                $passingCount++;
            } else {
                $failingPillars[] = $pillar;
                $missingProof[] = [
                    'pillar' => $pillar,
                    'reasons' => $rejectionReasons,
                    'verification_step' => self::PILLAR_VERIFICATION_STEPS[$pillar] ?? "Produce externally verifiable, fresh evidence for {$pillar}.",
                ];
            }
        }

        if ($failingPillars === []) {
            $verdict = self::VERDICT_CONVERGENCE_READY;
            $nextAction = 'monitor_and_maintain';
        } elseif (
            $passingCount >= self::CONSOLIDATE_PILLAR_FLOOR
            && $integrationOrgansCount >= self::CONSOLIDATE_ORGANS_FLOOR
        ) {
            $verdict = self::VERDICT_CONSOLIDATE;
            $nextAction = 'close_evidence_gaps_and_integrate';
        } else {
            $verdict = self::VERDICT_KEEP_BUILDING;
            $nextAction = 'address_failing_pillars';
        }

        $nextVerificationStep = $missingProof === [] ? null : $missingProof[0]['verification_step'];

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'convergence_status' => $verdict,
            'failing_pillars' => $failingPillars,
            'missing_proof' => $missingProof,
            'next_action' => $nextAction,
            'next_verification_step' => $nextVerificationStep,
            'evidence_receipts' => $evidenceReceipts,
        ];
    }
}
