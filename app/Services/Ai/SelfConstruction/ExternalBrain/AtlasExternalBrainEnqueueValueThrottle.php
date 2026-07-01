<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Final fail-closed decision layer before a candidate batch is enqueued.
 * Combines value, novelty, impact diversity, worker throughput, and roadmap
 * coverage into one enqueue_decision. Pure: it never mutates the queue
 * itself — it only returns the decision and (when salvageable) a trimmed
 * task-id subset for the caller to enqueue instead.
 *
 * BLOCKERS (any present -> allow_enqueue=false):
 *   low_value                        <- batch_value_score < 0.4
 *   theme_saturated                  <- novelty_score < 0.3
 *   batch_too_large                  <- recommended_batch_size > 10
 *   duplicate_prone                  <- impact_diversity_score < 0.3
 *   unrelated_to_high_priority_gaps  <- roadmap_coverage_score < 0.3
 *   worker_throughput_insufficient   <- worker_drain_confidence < 0.3
 *
 * SALVAGEABLE BATCH: when the only blockers are batch_too_large and/or
 * duplicate_prone (value, novelty, roadmap coverage, and worker throughput
 * are all fine), trimmed_task_ids returns the first half of
 * candidate_task_ids (minimum 1) so the caller can retry with a smaller,
 * higher-signal batch. Otherwise trimmed_task_ids is empty.
 *
 * pivot_recommendation gives a single next-step hint when blocked, in
 * severity order: low_value, theme_saturated,
 * unrelated_to_high_priority_gaps, worker_throughput_insufficient,
 * then the salvageable trim hint. Null when allow_enqueue=true.
 *
 * INPUT:
 *   batch_value_score?:       float (default 0.0)
 *   recommended_batch_size?:  int (default 0)
 *   novelty_score?:           float (default 0.0)
 *   impact_diversity_score?:  float (default 0.0)
 *   worker_drain_confidence?: float (default 0.0)
 *   queue_depth?:             int (default 0)
 *   roadmap_coverage_score?:  float (default 0.0)
 *   candidate_task_ids?:      list<string> (default [])
 *
 * OUTPUT:
 *   { schema, enqueue_decision, allow_enqueue, blockers, blocker_count,
 *     trimmed_task_ids, pivot_recommendation }
 *
 * Pure: no I/O, no queue mutation, no side effects.
 */
final class AtlasExternalBrainEnqueueValueThrottle
{
    public const SCHEMA = 'atlas.external_brain.enqueue_value_throttle.v1';

    private const LOW_VALUE_THRESHOLD = 0.4;

    private const SATURATION_NOVELTY_THRESHOLD = 0.3;

    private const TOO_LARGE_BATCH_SIZE = 10;

    private const DUPLICATE_PRONE_DIVERSITY_THRESHOLD = 0.3;

    private const ROADMAP_COVERAGE_THRESHOLD = 0.3;

    private const WORKER_DRAIN_CONFIDENCE_THRESHOLD = 0.3;

    /** fraction of the batch reported as filler/non-substantive before it's blocked as padding. */
    private const PADDING_RATIO_THRESHOLD = 0.3;

    /** queue_depth above this is "deep" — only a high-leverage batch may still enqueue. */
    private const DEEP_QUEUE_THRESHOLD = 20;

    /** floor each of novelty/structural-leverage/proof-demand must clear to justify a deep-queue enqueue. */
    private const HIGH_LEVERAGE_THRESHOLD = 0.7;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function throttle(array $input): array
    {
        $batchValueScore = max(0.0, min(1.0, (float) ($input['batch_value_score'] ?? 0.0)));
        $recommendedBatchSize = max(0, (int) ($input['recommended_batch_size'] ?? 0));
        $noveltyScore = max(0.0, min(1.0, (float) ($input['novelty_score'] ?? 0.0)));
        $impactDiversityScore = max(0.0, min(1.0, (float) ($input['impact_diversity_score'] ?? 0.0)));
        $workerDrainConfidence = max(0.0, min(1.0, (float) ($input['worker_drain_confidence'] ?? 0.0)));
        $roadmapCoverageScore = max(0.0, min(1.0, (float) ($input['roadmap_coverage_score'] ?? 0.0)));
        $candidateTaskIds = is_array($input['candidate_task_ids'] ?? null) ? array_values($input['candidate_task_ids']) : [];
        $queueDepth = max(0, (int) ($input['queue_depth'] ?? 0));
        $paddingRatio = max(0.0, min(1.0, (float) ($input['padding_ratio'] ?? 0.0)));
        $structuralLeverageScore = max(0.0, min(1.0, (float) ($input['structural_leverage_score'] ?? 0.0)));
        $proofDemandScore = max(0.0, min(1.0, (float) ($input['proof_demand_score'] ?? 0.0)));

        $highLeverage = $noveltyScore >= self::HIGH_LEVERAGE_THRESHOLD
            && $structuralLeverageScore >= self::HIGH_LEVERAGE_THRESHOLD
            && $proofDemandScore >= self::HIGH_LEVERAGE_THRESHOLD;

        $blockers = [];
        if ($batchValueScore < self::LOW_VALUE_THRESHOLD) {
            $blockers[] = 'low_value';
        }
        if ($noveltyScore < self::SATURATION_NOVELTY_THRESHOLD) {
            $blockers[] = 'theme_saturated';
        }
        if ($recommendedBatchSize > self::TOO_LARGE_BATCH_SIZE) {
            $blockers[] = 'batch_too_large';
        }
        if ($impactDiversityScore < self::DUPLICATE_PRONE_DIVERSITY_THRESHOLD) {
            $blockers[] = 'duplicate_prone';
        }
        if ($roadmapCoverageScore < self::ROADMAP_COVERAGE_THRESHOLD) {
            $blockers[] = 'unrelated_to_high_priority_gaps';
        }
        if ($workerDrainConfidence < self::WORKER_DRAIN_CONFIDENCE_THRESHOLD) {
            $blockers[] = 'worker_throughput_insufficient';
        }
        if ($paddingRatio >= self::PADDING_RATIO_THRESHOLD) {
            $blockers[] = 'padding_detected';
        }
        if ($queueDepth > self::DEEP_QUEUE_THRESHOLD && ! $highLeverage) {
            $blockers[] = 'queue_saturated_low_leverage';
        }

        $allowEnqueue = $blockers === [];

        $salvageableBlockers = ['batch_too_large', 'duplicate_prone'];
        $onlySalvageableBlockers = $blockers !== [] && array_diff($blockers, $salvageableBlockers) === [];

        $trimmedTaskIds = [];
        if ($onlySalvageableBlockers && $candidateTaskIds !== []) {
            $keep = max(1, intdiv(count($candidateTaskIds), 2));
            $trimmedTaskIds = array_slice($candidateTaskIds, 0, $keep);
        }

        // salvage_quality_floor_passed: the trimmed subset is only worth enqueuing when value,
        // novelty, roadmap coverage, and worker confidence all clear their floors — batch_too_large
        // and duplicate_prone are volume/shape defects, never a substitute for real quality.
        $salvageQualityFloorPassed = $trimmedTaskIds !== []
            && $batchValueScore >= self::LOW_VALUE_THRESHOLD
            && $noveltyScore >= self::SATURATION_NOVELTY_THRESHOLD
            && $roadmapCoverageScore >= self::ROADMAP_COVERAGE_THRESHOLD
            && $workerDrainConfidence >= self::WORKER_DRAIN_CONFIDENCE_THRESHOLD;

        $pivotRecommendation = match (true) {
            $allowEnqueue => null,
            in_array('low_value', $blockers, true) => 'wait_for_higher_value_candidates',
            in_array('theme_saturated', $blockers, true) => 'pivot_to_different_theme',
            in_array('unrelated_to_high_priority_gaps', $blockers, true) => 'realign_to_roadmap_coverage_gaps',
            in_array('worker_throughput_insufficient', $blockers, true) => 'wait_for_worker_capacity_to_recover',
            in_array('padding_detected', $blockers, true) => 'remove_padding_and_resubmit_only_substantive_tasks',
            in_array('queue_saturated_low_leverage', $blockers, true) => 'prove_high_novelty_leverage_and_proof_demand_before_deep_queue_enqueue',
            $onlySalvageableBlockers => 'trim_batch_to_salvageable_subset',
            default => 'review_batch_manually',
        };

        // AC4: repetition risk — how much this batch looks like saturated/duplicated theme work.
        $repetitionRisk = round(((1.0 - $noveltyScore) + (1.0 - $impactDiversityScore)) / 2, 4);

        // AC4: smallest score delta needed on the primary blocking dimension, mirroring the
        // same priority order as pivot_recommendation. Structural/shape blockers (batch size,
        // padding, queue depth) need a repair, not a score bump, so they yield 0.0 here.
        $minimumImprovementNeeded = match (true) {
            $allowEnqueue => 0.0,
            in_array('low_value', $blockers, true) => round(self::LOW_VALUE_THRESHOLD - $batchValueScore, 4),
            in_array('theme_saturated', $blockers, true) => round(self::SATURATION_NOVELTY_THRESHOLD - $noveltyScore, 4),
            in_array('unrelated_to_high_priority_gaps', $blockers, true) => round(self::ROADMAP_COVERAGE_THRESHOLD - $roadmapCoverageScore, 4),
            in_array('worker_throughput_insufficient', $blockers, true) => round(self::WORKER_DRAIN_CONFIDENCE_THRESHOLD - $workerDrainConfidence, 4),
            default => 0.0,
        };

        return [
            'schema' => self::SCHEMA,
            'enqueue_decision' => $allowEnqueue ? 'allow' : 'block',
            'allow_enqueue' => $allowEnqueue,
            'value_score' => $batchValueScore,
            'repetition_risk' => $repetitionRisk,
            'minimum_improvement_needed' => $minimumImprovementNeeded,
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'trimmed_task_ids' => $trimmedTaskIds,
            'salvage_quality_floor_passed' => $salvageQualityFloorPassed,
            'pivot_recommendation' => $pivotRecommendation,
        ];
    }
}
