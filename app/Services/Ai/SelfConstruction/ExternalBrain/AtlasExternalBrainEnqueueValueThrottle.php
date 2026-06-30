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

        $allowEnqueue = $blockers === [];

        $salvageableBlockers = ['batch_too_large', 'duplicate_prone'];
        $onlySalvageableBlockers = $blockers !== [] && array_diff($blockers, $salvageableBlockers) === [];

        $trimmedTaskIds = [];
        if ($onlySalvageableBlockers && $candidateTaskIds !== []) {
            $keep = max(1, intdiv(count($candidateTaskIds), 2));
            $trimmedTaskIds = array_slice($candidateTaskIds, 0, $keep);
        }

        $pivotRecommendation = match (true) {
            $allowEnqueue => null,
            in_array('low_value', $blockers, true) => 'wait_for_higher_value_candidates',
            in_array('theme_saturated', $blockers, true) => 'pivot_to_different_theme',
            in_array('unrelated_to_high_priority_gaps', $blockers, true) => 'realign_to_roadmap_coverage_gaps',
            in_array('worker_throughput_insufficient', $blockers, true) => 'wait_for_worker_capacity_to_recover',
            $onlySalvageableBlockers => 'trim_batch_to_salvageable_subset',
            default => 'review_batch_manually',
        };

        return [
            'schema' => self::SCHEMA,
            'enqueue_decision' => $allowEnqueue ? 'allow' : 'block',
            'allow_enqueue' => $allowEnqueue,
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'trimmed_task_ids' => $trimmedTaskIds,
            'pivot_recommendation' => $pivotRecommendation,
        ];
    }
}
