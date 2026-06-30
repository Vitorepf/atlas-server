<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Compiles final-95 readiness, brain health, queue health, autonomy independence, and
 * closed-loop learning evidence into one honest convergence verdict.
 *
 * VERDICT (first match wins):
 *   convergence_ready — ALL required pillars meet their threshold
 *   consolidate       — enough organs / partial proof exist, but integration or evidence
 *                        closure is incomplete (passing_pillars >= CONSOLIDATE_PILLAR_FLOOR
 *                        AND integration_organs_count >= CONSOLIDATE_ORGANS_FLOOR)
 *   keep_building     — any required pillar lacks proof (fallback)
 *
 * REQUIRED PILLARS AND DEFAULT THRESHOLDS:
 *   final_95_readiness            >= 0.95
 *   brain_health_score            >= 0.70
 *   queue_health_score            >= 0.70
 *   autonomy_independence_score   >= 0.80
 *   learning_loop_closedness      >= 0.75
 *   worker_proof_count            >= 5      (int comparison)
 *   compounding_evidence_score    >= 0.60
 *
 * OUTPUT:
 *   { schema, verdict, failing_pillars, next_action, evidence_receipts }
 *
 *   failing_pillars:   list<string>
 *   next_action:       string
 *   evidence_receipts: map<pillar, { score, threshold, passes }>
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainConvergenceCriteriaCompiler
{
    public const SCHEMA = 'atlas.external_brain.convergence_criteria_compiler.v1';

    public const VERDICT_CONVERGENCE_READY = 'convergence_ready';
    public const VERDICT_CONSOLIDATE       = 'consolidate';
    public const VERDICT_KEEP_BUILDING     = 'keep_building';

    private const CONSOLIDATE_PILLAR_FLOOR = 3;
    private const CONSOLIDATE_ORGANS_FLOOR = 3;

    private const PILLAR_DEFAULTS = [
        'final_95_readiness'          => 0.95,
        'brain_health_score'          => 0.70,
        'queue_health_score'          => 0.70,
        'autonomy_independence_score' => 0.80,
        'learning_loop_closedness'    => 0.75,
        'worker_proof_count'          => 5.0,
        'compounding_evidence_score'  => 0.60,
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $thresholdOverrides     = is_array($input['thresholds'] ?? null) ? $input['thresholds'] : [];
        $integrationOrgansCount = max(0, (int) ($input['integration_organs_count'] ?? 0));

        $evidenceReceipts = [];
        $failingPillars   = [];
        $passingCount     = 0;

        foreach (self::PILLAR_DEFAULTS as $pillar => $defaultThreshold) {
            $threshold = isset($thresholdOverrides[$pillar])
                ? (float) $thresholdOverrides[$pillar]
                : (float) $defaultThreshold;

            $score  = isset($input[$pillar]) ? (float) $input[$pillar] : 0.0;
            $passes = $score >= $threshold;

            $evidenceReceipts[$pillar] = [
                'score'     => $score,
                'threshold' => $threshold,
                'passes'    => $passes,
            ];

            if ($passes) {
                $passingCount++;
            } else {
                $failingPillars[] = $pillar;
            }
        }

        $totalPillars = count(self::PILLAR_DEFAULTS);

        if ($failingPillars === []) {
            $verdict    = self::VERDICT_CONVERGENCE_READY;
            $nextAction = 'monitor_and_maintain';
        } elseif (
            $passingCount >= self::CONSOLIDATE_PILLAR_FLOOR
            && $integrationOrgansCount >= self::CONSOLIDATE_ORGANS_FLOOR
        ) {
            $verdict    = self::VERDICT_CONSOLIDATE;
            $nextAction = 'close_evidence_gaps_and_integrate';
        } else {
            $verdict    = self::VERDICT_KEEP_BUILDING;
            $nextAction = 'address_failing_pillars';
        }

        return [
            'schema'            => self::SCHEMA,
            'verdict'           => $verdict,
            'failing_pillars'   => $failingPillars,
            'next_action'       => $nextAction,
            'evidence_receipts' => $evidenceReceipts,
        ];
    }
}
