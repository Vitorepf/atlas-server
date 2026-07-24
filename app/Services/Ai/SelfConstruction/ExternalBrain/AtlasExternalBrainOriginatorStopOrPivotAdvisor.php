<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Support\YesNo;

/**
 * Combines queue depth, worker drain rate, recent batch value, theme saturation, impact
 * diversity, duplicate risk, and unresolved high-priority gaps into ONE honest next_action
 * decision for the originator: keep going, pivot, research deeper, consolidate, or stop.
 *
 * Composes (never duplicates the logic of):
 *   AtlasExternalBrainOriginatorBatchValueAuditor    — is the recent batch actually valuable?
 *   AtlasExternalBrainOriginatorThemeSaturationMeter — is origination stuck on one theme?
 *   AtlasExternalBrainOriginatorImpactDiversityReport — does the batch advance diverse capability?
 *
 * DECISION PRIORITY (first match wins):
 *   1. research_before_originating — the batch value auditor itself says stop_and_research
 *      (e.g. no task has runnable proof) — keep_originating would compound a blind spot.
 *   2. pivot_to_gap                — unresolved high-priority impact gaps exist and the queue
 *      is not yet sufficient: redirect origination toward the gap instead of more of the same.
 *   3. stop_due_to_low_value       — queue depth is ALREADY sufficient AND the next candidate
 *      batch is saturated (theme) or low-novelty (diversity) or flagged low_value. Originating
 *      more under these conditions would only pad an already-full queue with weak work.
 *   4. consolidate_existing_queue  — duplicate risk is high, or theme is saturated with no
 *      unresolved gap to pivot toward: work through what already exists before creating more.
 *   5. pivot_to_gap                — theme is saturated but unresolved high-priority gaps exist.
 *   6. keep_originating            — queue is not yet sufficient and nothing above blocks it.
 *   7. pivot_to_gap                — queue depth is ALREADY sufficient, nothing above blocks it,
 *      but unresolved high-priority gaps remain. Claimable depth is supply-buffer management,
 *      not the operator's continuous-evolution goal: a "sufficient" number of queued tasks must
 *      never read as an excuse to go idle while unexhausted high-leverage surfaces exist.
 *   8. consolidate_existing_queue  — default fallback (never silently keep_originating).
 *
 * Pure: no I/O, no queue mutation; composes 3 already-pure ExternalBrain advisors.
 */
final class AtlasExternalBrainOriginatorStopOrPivotAdvisor
{
    public const SCHEMA = 'atlas.external_brain.originator_stop_or_pivot_advisor.v1';

    public const ACTION_KEEP_ORIGINATING = 'keep_originating';
    public const ACTION_PIVOT_TO_GAP = 'pivot_to_gap';
    public const ACTION_RESEARCH_BEFORE_ORIGINATING = 'research_before_originating';
    public const ACTION_CONSOLIDATE_EXISTING_QUEUE = 'consolidate_existing_queue';
    public const ACTION_STOP_DUE_TO_LOW_VALUE = 'stop_due_to_low_value';

    private const LOW_DIVERSITY_THRESHOLD = 0.25;
    private const HIGH_DUPLICATE_RISK_THRESHOLD = 0.50;
    private const LOW_DRAIN_RATE_PER_WORKER = 2.0;

    public function __construct(
        private readonly AtlasExternalBrainOriginatorBatchValueAuditor $batchValueAuditor = new AtlasExternalBrainOriginatorBatchValueAuditor,
        private readonly AtlasExternalBrainOriginatorThemeSaturationMeter $themeSaturationMeter = new AtlasExternalBrainOriginatorThemeSaturationMeter,
        private readonly AtlasExternalBrainOriginatorImpactDiversityReport $impactDiversityReport = new AtlasExternalBrainOriginatorImpactDiversityReport,
    ) {}

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function advise(array $facts): array
    {
        $queue = is_array($facts['queue'] ?? null) ? $facts['queue'] : [];
        $claimableDepth = max(0, (int) ($queue['claimable_depth'] ?? 0));
        $targetMinClaimable = max(1, (int) ($queue['target_min_claimable'] ?? 3));
        $activeLeases = max(0, (int) ($queue['active_leases'] ?? 0));
        $servableNow = max(0, (int) ($queue['servable_now'] ?? $claimableDepth));

        $batchValue = $this->batchValueAuditor->audit(['tasks' => $facts['recent_batch_tasks'] ?? []]);
        $themeSaturation = $this->themeSaturationMeter->measure(
            is_array($facts['theme_recent_tasks'] ?? null) ? $facts['theme_recent_tasks'] : [],
            is_array($facts['theme_context'] ?? null) ? $facts['theme_context'] : [],
        );
        $impactDiversity = $this->impactDiversityReport->report([
            'tasks' => $facts['diversity_tasks'] ?? [],
            'high_priority_classes' => $facts['high_priority_classes'] ?? null,
        ]);

        $queueSufficient = $claimableDepth >= $targetMinClaimable;
        $claimablePerWorker = $activeLeases > 0 ? round($servableNow / $activeLeases, 4) : null;
        $drainRateLow = $claimablePerWorker !== null && $claimablePerWorker < self::LOW_DRAIN_RATE_PER_WORKER;

        $batchRecommendation = (string) ($batchValue['recommendation'] ?? '');
        $lowValue = (bool) ($batchValue['low_value'] ?? false);

        $saturationHigh = (bool) ($themeSaturation['saturation_high'] ?? false);

        $impactDiversityScore = (float) ($impactDiversity['impact_diversity_score'] ?? 0.0);
        $lowDiversity = $impactDiversityScore < self::LOW_DIVERSITY_THRESHOLD;

        $duplicateRisk = array_key_exists('duplicate_risk_ratio', $facts)
            ? max(0.0, min(1.0, (float) $facts['duplicate_risk_ratio']))
            : $this->avgDuplicationRisk((array) ($batchValue['task_scores'] ?? []));
        $highDuplicateRisk = $duplicateRisk >= self::HIGH_DUPLICATE_RISK_THRESHOLD;

        $unresolvedHighPriorityGaps = array_key_exists('unresolved_high_priority_gaps', $facts) && is_array($facts['unresolved_high_priority_gaps'])
            ? array_values(array_map('strval', $facts['unresolved_high_priority_gaps']))
            : (array) ($impactDiversity['missing_high_priority_classes'] ?? []);

        $evidence = [
            "claimable_depth:{$claimableDepth}",
            "target_min_claimable:{$targetMinClaimable}",
            'queue_sufficient:'.(YesNo::trueFalse($queueSufficient)),
            'claimable_per_worker:'.($claimablePerWorker ?? 'unknown'),
            'drain_rate_low:'.(YesNo::trueFalse($drainRateLow)),
            "batch_recommendation:{$batchRecommendation}",
            'batch_low_value:'.(YesNo::trueFalse($lowValue)),
            'theme_saturation_high:'.(YesNo::trueFalse($saturationHigh)),
            "impact_diversity_score:{$impactDiversityScore}",
            'impact_diversity_low:'.(YesNo::trueFalse($lowDiversity)),
            "duplicate_risk:{$duplicateRisk}",
            'duplicate_risk_high:'.(YesNo::trueFalse($highDuplicateRisk)),
            'unresolved_high_priority_gaps:'.implode(',', $unresolvedHighPriorityGaps),
        ];

        [$action, $reasons] = match (true) {
            $batchRecommendation === AtlasExternalBrainOriginatorBatchValueAuditor::RECOMMENDATION_STOP_AND_RESEARCH => [
                self::ACTION_RESEARCH_BEFORE_ORIGINATING,
                ['batch_value_auditor_recommended_stop_and_research'],
            ],
            $unresolvedHighPriorityGaps !== [] && ! $queueSufficient => [
                self::ACTION_PIVOT_TO_GAP,
                ['unresolved_high_priority_gaps_present', 'queue_not_yet_sufficient'],
            ],
            $queueSufficient && ($saturationHigh || $lowDiversity || $lowValue) => [
                self::ACTION_STOP_DUE_TO_LOW_VALUE,
                array_values(array_filter([
                    'queue_depth_already_sufficient',
                    $saturationHigh ? 'next_candidate_batch_theme_saturated' : null,
                    $lowDiversity ? 'next_candidate_batch_low_novelty' : null,
                    $lowValue ? 'recent_batch_flagged_low_value' : null,
                ])),
            ],
            $highDuplicateRisk => [
                self::ACTION_CONSOLIDATE_EXISTING_QUEUE,
                ['duplicate_risk_high'],
            ],
            $saturationHigh && $unresolvedHighPriorityGaps === [] => [
                self::ACTION_CONSOLIDATE_EXISTING_QUEUE,
                ['theme_saturated_with_no_unresolved_gap_to_pivot_toward'],
            ],
            $saturationHigh => [
                self::ACTION_PIVOT_TO_GAP,
                ['theme_saturated_but_unresolved_high_priority_gaps_present'],
            ],
            ! $queueSufficient && ! $lowValue && ! $saturationHigh && ! $lowDiversity => [
                self::ACTION_KEEP_ORIGINATING,
                ['queue_below_target', 'recent_batch_healthy', 'no_saturation_or_novelty_concern'],
            ],
            $queueSufficient && $unresolvedHighPriorityGaps !== [] => [
                self::ACTION_PIVOT_TO_GAP,
                ['queue_depth_sufficient_is_supply_buffer_not_the_operator_goal', 'unresolved_high_priority_gaps_present_continue_high_leverage_origination'],
            ],
            default => [
                self::ACTION_CONSOLIDATE_EXISTING_QUEUE,
                ['no_clear_signal_to_originate_more_default_to_consolidation'],
            ],
        };

        return [
            'schema' => self::SCHEMA,
            'next_action' => $action,
            'action_reason' => implode('; ', $reasons),
            'reasons' => $reasons,
            'target_gap' => $unresolvedHighPriorityGaps !== [] ? $unresolvedHighPriorityGaps[0] : null,
            'avoided_wait_reason' => $action !== self::ACTION_STOP_DUE_TO_LOW_VALUE && $queueSufficient
                ? 'queue_sufficient_but_actionable_alternative_exists'
                : null,
            'evidence' => $evidence,
            'inputs' => [
                'queue_sufficient' => $queueSufficient,
                'claimable_depth' => $claimableDepth,
                'target_min_claimable' => $targetMinClaimable,
                'claimable_per_worker' => $claimablePerWorker,
                'drain_rate_low' => $drainRateLow,
                'batch_value' => $batchValue,
                'theme_saturation' => $themeSaturation,
                'impact_diversity' => $impactDiversity,
                'duplicate_risk' => $duplicateRisk,
                'unresolved_high_priority_gaps' => $unresolvedHighPriorityGaps,
            ],
            'mutates_queue' => false,
        ];
    }

    /** @param  list<array<string,mixed>>  $taskScores */
    private function avgDuplicationRisk(array $taskScores): float
    {
        if ($taskScores === []) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($taskScores as $score) {
            $sum += max(0.0, min(1.0, (float) ($score['duplication_risk'] ?? 0.0)));
        }

        return round($sum / count($taskScores), 4);
    }
}
