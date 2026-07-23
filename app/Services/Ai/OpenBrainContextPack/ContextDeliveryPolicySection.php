<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextPack;

use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Context\AtlasContextFeedbackSignalPolicy;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;
use Throwable;

/**
 * GOD-DEBULK split of {@see \App\Services\Ai\AtlasOpenBrainContextPackService}.
 * Verbatim contextdeliverypolicy family extracted from the AOBG context-pack
 * façade; behavior-preserving (private helpers -> Support; public API stays on the façade).
 */
final class ContextDeliveryPolicySection
{
    public function __construct(
        private readonly Support $support,
        private readonly AtlasContextFeedbackSignalPolicy $feedbackSignalPolicy,
    ) {}

    /**
     * Build the compact delivery policy that tells external providers how much
     * context was loaded now and which source types should be expanded later.
     *
     * The only automatic effect here is a bounded initial budget shrink when
     * repeated provider-safe feedback shows low ROI or waste. Ref demotion and
     * source expansion remain explicit, provider-safe handles.
     *
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    public function contextDeliveryPolicy(array $opts): array
    {
        $windowHours = max(1, min(720, $this->support->intOpt($opts, 'feedback_window_hours', 168)));
        $flowId = $this->contextFeedbackFlowId($opts);
        $base = [
            'schema_version' => AtlasOpenBrainContextPackService::CONTEXT_DELIVERY_POLICY_SCHEMA,
            'status' => 'inactive',
            'delivery_mode' => 'standard_minimal_top_k',
            'source' => 'none',
            'flow_id' => $flowId,
            'window_hours' => $windowHours,
            'initial_context_budget_multiplier' => 1.0,
            'applied_to_initial_budget' => false,
            'actions' => ['keep_current_pack'],
            'expand_source_types' => [],
            'deferred_source_types' => [],
            'demote_context_refs' => [],
            'on_demand_handles' => $this->expansionHandles([]),
            'source_selection_policy' => $this->sourceSelectionPolicy([], 0),
            'evidence' => [
                'feedback_event_count' => 0,
                'measured_event_count' => 0,
                'measured_count' => 0,
                'total_event_count' => 0,
                'synthetic_share' => 0.0,
                'latest_feedback_hashes' => [],
            ],
            'quality_gate_hint' => 'feedback_not_available_for_initial_pack',
            'policy' => $this->contextDeliveryPolicySafety(false),
        ];

        if (! DatabaseTableAvailability::has('ai_rag_feedback_events')) {
            return $base + [
                'status' => 'unavailable',
                'reason' => 'feedback_table_missing',
            ];
        }

        try {
            $query = AiRagFeedbackEvent::query()
                ->where('created_at', '>=', now()->subHours($windowHours))
                ->latest('created_at')
                ->limit(20);

            if ($flowId !== null) {
                $query->where('flow_id', $flowId);
            }

            /** @var Collection<int,AiRagFeedbackEvent> $events */
            $events = $query->get();
        } catch (Throwable) {
            return $base + [
                'status' => 'unavailable',
                'reason' => 'feedback_read_failed',
            ];
        }

        $totalEventCount = $events->count();
        if ($events->isEmpty()) {
            return $base + [
                'status' => 'no_data',
                'source' => $flowId !== null ? 'no_flow_feedback' : 'no_recent_feedback',
                'reason' => 'no_context_feedback_events',
                'quality_gate_hint' => 'record_atlas_context_feedback_after_provider_runs',
            ];
        }

        $rawMeasuredCount = $events
            ->filter(fn (AiRagFeedbackEvent $event): bool => $this->feedbackSignalPolicy->isMeasured($event))
            ->count();
        $events = $events
            ->filter(fn (AiRagFeedbackEvent $event): bool => $this->feedbackSignalPolicy->isMeasuredAggregateEligible($event))
            ->values();
        $eligibleCount = $events->count();
        $syntheticShare = $totalEventCount === 0 ? 0.0 : round(($totalEventCount - $eligibleCount) / $totalEventCount, 4);

        if ($events->isEmpty()) {
            $evidence = [
                'feedback_event_count' => 0,
                'measured_event_count' => 0,
                'measured_count' => 0,
                'raw_measured_event_count' => $rawMeasuredCount,
                'total_event_count' => $totalEventCount,
                'synthetic_share' => $syntheticShare,
                'latest_feedback_hashes' => [],
            ];
            if ($totalEventCount >= AtlasContextFeedbackSignalPolicy::TOTAL_EVENT_FLOOR) {
                $evidence['measured_share'] = 0.0;
            }

            return array_replace_recursive($base, [
                'status' => 'insufficient_signal',
                'source' => $flowId !== null ? 'unmeasured_flow_feedback' : 'unmeasured_recent_context_feedback',
                'reason' => 'context_feedback_events_unmeasured',
                'evidence' => $evidence,
                'quality_gate_hint' => 'record_explicit_used_refs_and_post_execution_utility_after_provider_runs',
            ]);
        }

        $roiScores = [];
        $useRatios = [];
        $wasteRatios = [];
        $sufficiencyScores = [];
        $utilityScores = [];
        $lowRoiCount = 0;
        $wasteCount = 0;
        $noiseCount = 0;
        $missedCount = 0;
        $nonPassingCount = 0;
        $actionableFeedbackCount = 0;
        $nonActionableFeedbackCount = 0;
        $missingRoiSignalCount = 0;
        $actions = [];
        $expandSourceTypes = [];
        $deferSections = [];
        $demoteContextRefs = [];
        $feedbackHashes = [];
        $sourceTypeStats = [];

        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $roi = $this->feedbackPayloadArray($payload, 'context_roi');
            $attribution = $this->feedbackPayloadArray($payload, 'context_ref_attribution');
            $nextPolicy = $this->feedbackPayloadArray($payload, 'next_context_policy');
            $missed = $this->support->stringList($event->missed_required_sources ?? []);
            $hasRoiSignal = $roi !== [] || $attribution !== [];
            $hasPolicySignal = $nextPolicy !== [];
            if ($hasRoiSignal || $hasPolicySignal || $missed !== [] || (int) $event->noise_sources > 0) {
                $actionableFeedbackCount++;
            } else {
                $nonActionableFeedbackCount++;
            }
            if (! $hasRoiSignal) {
                $missingRoiSignalCount++;
            }

            $roiScore = $this->nullableFloat(data_get($roi, 'roi_score'));
            if ($roiScore !== null) {
                $roiScores[] = $roiScore;
                if ($roiScore < 0.50) {
                    $lowRoiCount++;
                }
            }

            $useRatio = $this->nullableFloat(data_get($attribution, 'use_ratio', data_get($roi, 'use_ratio')));
            if ($useRatio !== null) {
                $useRatios[] = $useRatio;
            }

            $wasteRatio = $this->nullableFloat(data_get($attribution, 'waste_ratio'));
            if ($wasteRatio !== null) {
                $wasteRatios[] = $wasteRatio;
                if ($wasteRatio >= 0.40) {
                    $wasteCount++;
                }
            }

            $sufficiency = $this->nullableFloat(data_get($roi, 'context_sufficiency', $event->context_sufficiency));
            if ($sufficiency !== null) {
                $sufficiencyScores[] = $sufficiency;
            }

            $utility = $this->nullableFloat(data_get($roi, 'post_execution_utility', $event->post_execution_utility));
            if ($utility !== null) {
                $utilityScores[] = $utility;
            }

            $noiseCount += max((int) $event->noise_sources, (int) data_get($attribution, 'noise_count', 0));
            $missedCount += count($missed);
            $expandSourceTypes = array_merge(
                $expandSourceTypes,
                $missed,
                $this->support->stringList(data_get($attribution, 'missing_source_types', [])),
                $this->support->stringList(data_get($nextPolicy, 'expand_source_types', [])),
            );

            if ($this->isNonPassingContextOutcome((string) $event->outcome_status)) {
                $nonPassingCount++;
            }

            $actions = array_merge($actions, $this->support->stringList(data_get($nextPolicy, 'actions', [])));
            $deferSections = array_merge($deferSections, $this->support->stringList(data_get($nextPolicy, 'defer_sections', [])));
            if ($this->feedbackEventAllowsDemotion($event, $payload, $attribution, $roi)) {
                $demoteContextRefs = array_merge($demoteContextRefs, $this->support->stringList(data_get($nextPolicy, 'demote_context_refs', [])));
            }
            $sourceTypeStats = $this->mergeSourceTypeStats($sourceTypeStats, $attribution);
            if ($utility !== null) {
                $sourceTypeStats = $this->mergeSourceUtilityStats($sourceTypeStats, $attribution, $utility);
            }
            if ((string) $event->feedback_hash !== '') {
                $feedbackHashes[] = (string) $event->feedback_hash;
            }
        }

        $observedCount = $events->count();
        $avgRoi = $this->average($roiScores);
        $avgUseRatio = $this->average($useRatios);
        $avgWasteRatio = $this->average($wasteRatios);
        $expandSourceTypes = $this->support->uniqueStrings($expandSourceTypes);
        $deferSections = $this->support->uniqueStrings($deferSections);
        $demoteContextRefs = $this->support->uniqueStrings($demoteContextRefs);
        $sourceSelectionPolicy = $this->sourceSelectionPolicy(
            $totalEventCount >= AtlasContextFeedbackSignalPolicy::TOTAL_EVENT_FLOOR ? $sourceTypeStats : [],
            $totalEventCount >= AtlasContextFeedbackSignalPolicy::TOTAL_EVENT_FLOOR ? $actionableFeedbackCount : 0,
        );

        if ($expandSourceTypes !== []) {
            $actions[] = 'expand_missing_source_types';
        }
        if ($demoteContextRefs !== [] || $noiseCount > 0) {
            $actions[] = 'demote_noise_context_refs';
        }
        if ((bool) ($sourceSelectionPolicy['applied_to_initial_pack'] ?? false)) {
            $actions[] = 'adjust_initial_source_mix';
        }
        if ($wasteCount > 0 || (count($useRatios) >= 2 && $avgUseRatio < 0.50) || $lowRoiCount >= 2) {
            $actions[] = 'shrink_initial_context';
        }
        if ($lowRoiCount > 0 || $nonPassingCount > 0) {
            $actions[] = 'review_context_pack';
        }

        $actions = $this->support->uniqueStrings($actions);
        if ($actions === []) {
            $actions = ['keep_current_pack'];
        }

        $shouldShrink = in_array('shrink_initial_context', $actions, true);
        $shouldExpand = in_array('expand_missing_source_types', $actions, true);
        $canApplyBudget = $totalEventCount >= AtlasContextFeedbackSignalPolicy::TOTAL_EVENT_FLOOR && $observedCount >= 2;
        $multiplier = $canApplyBudget
            ? match (true) {
                $shouldShrink && $shouldExpand => 0.85,
                $shouldShrink => 0.75,
                default => 1.0,
            }
        : 1.0;
        $applied = $multiplier < 1.0 && $canApplyBudget;

        $evidence = [
            'feedback_event_count' => $observedCount,
            'measured_event_count' => $observedCount,
            'measured_count' => $observedCount,
            'raw_measured_event_count' => $rawMeasuredCount,
            'total_event_count' => $totalEventCount,
            'synthetic_share' => $syntheticShare,
            'latest_feedback_hashes' => array_slice($feedbackHashes, 0, 5),
            'low_roi_count' => $lowRoiCount,
            'waste_count' => $wasteCount,
            'noise_count' => $noiseCount,
            'missed_required_source_count' => $missedCount,
            'unresolved_missed_count' => $this->unresolvedMissedCount($events),
            'non_passing_count' => $nonPassingCount,
            'actionable_feedback_count' => $actionableFeedbackCount,
            'non_actionable_feedback_count' => $nonActionableFeedbackCount,
            'missing_roi_signal_count' => $missingRoiSignalCount,
            'source_buckets' => $sourceTypeStats,
            'auto_apply_scope' => 'bounded_source_mix_only',
            'ref_repromotion_enabled' => (bool) config('atlas.aobg.repromote_specific_refs_enabled', false),
            'averages' => [
                'roi_score' => round($avgRoi, 4),
                'use_ratio' => round($avgUseRatio, 4),
                'waste_ratio' => round($avgWasteRatio, 4),
                'context_sufficiency' => round($this->average($sufficiencyScores), 2),
                'post_execution_utility' => round($this->average($utilityScores), 2),
            ],
        ];
        if ($totalEventCount >= AtlasContextFeedbackSignalPolicy::TOTAL_EVENT_FLOOR) {
            $evidence['measured_share'] = round($observedCount / max(1, $totalEventCount), 4);
        }

        return [
            'schema_version' => AtlasOpenBrainContextPackService::CONTEXT_DELIVERY_POLICY_SCHEMA,
            'status' => $totalEventCount < AtlasContextFeedbackSignalPolicy::TOTAL_EVENT_FLOOR
                ? 'insufficient_signal'
                : ($actions === ['keep_current_pack'] ? 'observed' : 'active'),
            'delivery_mode' => match (true) {
                $applied => 'feedback_shrunk_initial_expand_on_demand',
                $shouldExpand => 'feedback_targeted_expansion_handles',
                $actions !== ['keep_current_pack'] => 'feedback_advisory_review',
                default => 'standard_minimal_top_k',
            },
            'source' => $flowId !== null ? 'latest_flow_feedback' : 'recent_context_feedback',
            'flow_id' => $flowId,
            'window_hours' => $windowHours,
            'initial_context_budget_multiplier' => $multiplier,
            'applied_to_initial_budget' => $applied,
            'actions' => $actions,
            'expand_source_types' => $expandSourceTypes,
            'deferred_source_types' => $this->support->uniqueStrings(array_merge($expandSourceTypes, $deferSections)),
            'demote_context_refs' => array_slice(array_values(array_unique(array_merge(
                $demoteContextRefs,
                $this->support->concentrationDemoteContextRefs(),
            ))), 0, 12),
            'on_demand_handles' => $this->expansionHandles($expandSourceTypes),
            'source_selection_policy' => $sourceSelectionPolicy,
            'evidence' => $evidence,
            'quality_gate_hint' => $shouldExpand
                ? 'expand_missing_source_types_before_implementation'
                : ($applied ? 'budget_shrunk_by_feedback_keep_expansion_available' : ($actionableFeedbackCount === 0 ? 'feedback_observed_but_not_actionable_for_budget' : 'feedback_review_before_context_expansion')),
            'policy' => $this->contextDeliveryPolicySafety($applied),
        ];
    }

    public function feedbackEventMeasured(AiRagFeedbackEvent $event): bool
    {
        return $this->feedbackSignalPolicy->isMeasuredAggregateEligible($event);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function feedbackPayloadArray(array $payload, string $key): array
    {
        foreach ([$key, 'payload.'.$key, 'payload.payload.'.$key] as $path) {
            $value = data_get($payload, $path);
            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function feedbackPayloadString(array $payload, string $key): string
    {
        foreach ([$key, 'payload.'.$key, 'payload.payload.'.$key] as $path) {
            $value = data_get($payload, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $attribution
     * @param  array<string,mixed>  $roi
     */
    public function feedbackEventAllowsDemotion(AiRagFeedbackEvent $event, array $payload, array $attribution, array $roi): bool
    {
        if (! $this->feedbackEventMeasured($event)) {
            return false;
        }

        $usageBasis = strtolower(trim($this->feedbackPayloadString($payload, 'usage_basis')));
        if ($usageBasis === '') {
            $usageBasis = strtolower(trim((string) data_get($attribution, 'usage_basis', data_get($roi, 'usage_basis', ''))));
        }

        return ! $this->usageBasisIsInferred($usageBasis);
    }

    public function usageBasisIsInferred(string $usageBasis): bool
    {
        $usageBasis = strtolower(trim($usageBasis));
        if ($usageBasis === '') {
            return false;
        }

        return str_contains($usageBasis, 'inferred')
            || str_starts_with($usageBasis, 'synthetic')
            || str_starts_with($usageBasis, 'unmeasured');
    }

    /**
     * @param  array<string,array<string,int>>  $stats
     * @return array<string,mixed>
     */
    public function sourceSelectionPolicy(array $stats, int $actionableFeedbackCount): array
    {
        if ((bool) config('atlas.aobg.source_selection_ev_weighted', false)) {
            return $this->evWeightedSourceSelectionPolicy($stats, $actionableFeedbackCount);
        }

        $multipliers = [
            'code' => 1.0,
            'graph' => 1.0,
            'memory' => 1.0,
        ];
        $sourceTypes = [];
        $actions = [];

        foreach (['code', 'graph', 'memory'] as $type) {
            $row = $stats[$type] ?? ['delivered' => 0, 'used' => 0, 'unused' => 0, 'noise' => 0];
            $delivered = max(0, (int) ($row['delivered'] ?? 0));
            $used = max(0, (int) ($row['used'] ?? 0));
            $unused = max(0, (int) ($row['unused'] ?? 0));
            $noise = max(0, (int) ($row['noise'] ?? 0));
            $waste = $unused + $noise;
            $useRatio = $delivered > 0 ? round($used / $delivered, 4) : 0.0;
            $wasteRatio = $delivered > 0 ? round($waste / $delivered, 4) : 0.0;

            $multiplier = 1.0;
            $action = 'keep';
            if ($actionableFeedbackCount > 0 && $delivered >= AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS && ($noise > 0 || ($used === 0 && $wasteRatio >= 0.50))) {
                $multiplier = 0.70;
                $action = 'reduce_initial_share';
            } elseif ($actionableFeedbackCount > 0 && $delivered >= AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS && $wasteRatio >= 0.40 && $useRatio < 0.50) {
                $multiplier = 0.85;
                $action = 'trim_initial_share';
            } elseif ($actionableFeedbackCount > 0 && $delivered >= AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS && $useRatio >= 0.50 && $wasteRatio < 0.40) {
                $action = 'preserve_initial_share';
            }

            $multipliers[$type] = $multiplier;
            if ($action !== 'keep') {
                $actions[] = $action.':'.$type;
            }
            $sourceTypes[$type] = [
                'delivered' => $delivered,
                'used' => $used,
                'unused' => $unused,
                'noise' => $noise,
                'use_ratio' => $useRatio,
                'waste_ratio' => $wasteRatio,
                'action' => $action,
                'budget_multiplier' => $multiplier,
                'minimum_measured_events' => AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS,
            ];
        }

        $applied = min($multipliers) < 1.0;

        return [
            'schema_version' => 'atlas.aobg.source_selection_policy.v1',
            'status' => $applied ? 'active' : ($actionableFeedbackCount > 0 ? 'observed' : 'inactive'),
            'applied_to_initial_pack' => $applied,
            'actions' => $actions !== [] ? $actions : ['keep_source_mix'],
            'budget_multipliers' => $multipliers,
            'source_types' => $sourceTypes,
            'guardrails' => [
                'min_top_item_per_present_source' => true,
                'expansion_handles_remain_available' => true,
                'raw_text_exposed' => false,
                'auto_apply_scope' => $applied ? 'bounded_source_mix_only' : 'none',
            ],
        ];
    }

    /**
     * MAXE-07 — formula v2. Continuous source multipliers from measured expected
     * value: used_ratio * average post_execution_utility for refs actually used
     * in that source bucket. Buckets without measured signal stay neutral.
     *
     * @param  array<string,array<string,int|float>>  $stats
     * @return array<string,mixed>
     */
    public function evWeightedSourceSelectionPolicy(array $stats, int $actionableFeedbackCount): array
    {
        $multipliers = [
            'code' => 1.0,
            'graph' => 1.0,
            'memory' => 1.0,
        ];
        $sourceTypes = [];
        $actions = [];

        foreach (['code', 'graph', 'memory'] as $type) {
            $row = $stats[$type] ?? ['delivered' => 0, 'used' => 0, 'unused' => 0, 'noise' => 0];
            $delivered = max(0, (int) ($row['delivered'] ?? 0));
            $used = max(0, (int) ($row['used'] ?? 0));
            $unused = max(0, (int) ($row['unused'] ?? 0));
            $noise = max(0, (int) ($row['noise'] ?? 0));
            $utilityCount = max(0, (int) ($row['utility_count'] ?? 0));
            $utilitySum = max(0.0, AiValueNormalizer::finiteFloatOrNull($row['utility_sum'] ?? null) ?? 0.0);
            $useRatio = $delivered > 0 ? round($used / $delivered, 4) : 0.0;
            $wasteRatio = $delivered > 0 ? round(($unused + $noise) / $delivered, 4) : 0.0;
            $avgUtility = $utilityCount > 0 ? round($utilitySum / $utilityCount, 4) : null;
            $expectedValue = $avgUtility === null ? null : round($useRatio * ($avgUtility / 100), 4);

            $multiplier = 1.0;
            $action = 'keep';
            if ($actionableFeedbackCount > 0
                && $delivered >= AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS
                && $utilityCount >= AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS
                && $expectedValue !== null) {
                $multiplier = round(max(0.5, min(1.0, 0.5 + $expectedValue)), 4);
                $action = $multiplier < 1.0 ? 'ev_weighted_adjust_initial_share' : 'preserve_initial_share';
            } elseif ($delivered >= AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS) {
                $action = 'insufficient_ev_signal';
            }

            $multipliers[$type] = $multiplier;
            if ($action !== 'keep') {
                $actions[] = $action.':'.$type;
            }
            $sourceTypes[$type] = [
                'delivered' => $delivered,
                'used' => $used,
                'unused' => $unused,
                'noise' => $noise,
                'use_ratio' => $useRatio,
                'waste_ratio' => $wasteRatio,
                'utility_count' => $utilityCount,
                'average_utility' => $avgUtility,
                'expected_value' => $expectedValue,
                'action' => $action,
                'budget_multiplier' => $multiplier,
                'minimum_measured_events' => AtlasContextFeedbackSignalPolicy::SOURCE_BUCKET_MIN_EVENTS,
            ];
        }

        $applied = min($multipliers) < 1.0;

        return [
            'schema_version' => 'atlas.aobg.source_selection_policy.v2',
            'formula_version' => 'atlas.aobg.source_selection_ev_weighted.v1',
            'mode' => 'ev_weighted',
            'status' => $applied ? 'active' : ($actionableFeedbackCount > 0 ? 'observed' : 'inactive'),
            'applied_to_initial_pack' => $applied,
            'actions' => $actions !== [] ? $actions : ['keep_source_mix'],
            'budget_multipliers' => $multipliers,
            'source_types' => $sourceTypes,
            'guardrails' => [
                'min_top_item_per_present_source' => true,
                'expansion_handles_remain_available' => true,
                'raw_text_exposed' => false,
                'auto_apply_scope' => $applied ? 'bounded_source_mix_only' : 'none',
                'multiplier_floor' => 0.5,
                'multiplier_ceiling' => 1.0,
            ],
        ];
    }

    /**
     * @param  array<string,array<string,int>>  $stats
     * @param  array<string,mixed>  $attribution
     * @return array<string,array<string,int>>
     */
    public function mergeSourceTypeStats(array $stats, array $attribution): array
    {
        foreach ([
            'delivered_refs' => 'delivered',
            'used_refs' => 'used',
            'unused_refs' => 'unused',
            'noise_refs' => 'noise',
        ] as $key => $bucket) {
            foreach ((array) ($attribution[$key] ?? []) as $ref) {
                if (! is_array($ref)) {
                    continue;
                }
                $type = $this->normalizedInitialSourceType((string) ($ref['source_type'] ?? $ref['ref'] ?? ''));
                if ($type === null) {
                    continue;
                }
                $stats[$type] ??= ['delivered' => 0, 'used' => 0, 'unused' => 0, 'noise' => 0];
                $stats[$type][$bucket] = ($stats[$type][$bucket] ?? 0) + 1;
            }
        }

        return $stats;
    }

    /**
     * @param  array<string,array<string,int|float>>  $stats
     * @param  array<string,mixed>  $attribution
     * @return array<string,array<string,int|float>>
     */
    public function mergeSourceUtilityStats(array $stats, array $attribution, float $utility): array
    {
        foreach ((array) ($attribution['used_refs'] ?? []) as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $type = $this->normalizedInitialSourceType((string) ($ref['source_type'] ?? $ref['ref'] ?? ''));
            if ($type === null) {
                continue;
            }
            $stats[$type] ??= ['delivered' => 0, 'used' => 0, 'unused' => 0, 'noise' => 0];
            $stats[$type]['utility_sum'] = (AiValueNormalizer::finiteFloatOrNull($stats[$type]['utility_sum'] ?? null) ?? 0.0) + $utility;
            $stats[$type]['utility_count'] = (int) ($stats[$type]['utility_count'] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * @param  Collection<int,AiRagFeedbackEvent>  $events
     */
    public function unresolvedMissedCount(Collection $events): int
    {
        $count = 0;
        foreach ($events as $event) {
            $missed = $this->support->stringList($event->missed_required_sources ?? []);
            if ($missed === []) {
                continue;
            }

            $resolved = $this->support->stringList(data_get($event->payload, 'payload.missed_resolution.resolved_source_types', []));
            foreach ($missed as $sourceType) {
                if (! in_array($sourceType, $resolved, true)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function normalizedInitialSourceType(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        if (str_contains($value, ':')) {
            $value = strtok($value, ':') ?: $value;
        }

        return match ($value) {
            'code', 'code_intelligence', 'context_ref', 'symbol', 'route', 'migration', 'test' => 'code',
            'graph', 'graph_retrieval', 'reality_graph', 'aurg' => 'graph',
            'memory', 'memory_signals', 'semantic', 'semantic_candidate', 'vector_retrieval', 'decision', 'technical_context' => 'memory',
            default => null,
        };
    }

    public function isNonPassingContextOutcome(string $status): bool
    {
        $status = strtolower(trim($status));
        if ($status === '' || in_array($status, ['passed', 'success', 'succeeded', 'ok', 'ready', 'completed'], true)) {
            return false;
        }

        if (in_array($status, ['ready_for_provider', 'unknown', 'observed', 'no_data'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    public function contextFeedbackFlowId(array $opts): ?string
    {
        $explicit = $this->support->stringOpt($opts, 'flow_id');
        if ($explicit !== null) {
            return $explicit;
        }

        // Obra 7 / OPT-04: programming surfaces pass flow_id for ARFL→ACRS repromote.
        if ((bool) config('atlas.context.programming_flow_repromote_enabled', true)) {
            $programmingFlow = $this->support->stringOpt($opts, 'programming_flow')
                ?? $this->support->stringOpt($opts, 'programming_profile');
            if ($programmingFlow !== null) {
                return match ($programmingFlow) {
                    'forge' => 'atlas_forge',
                    'repair', 'debug' => 'atlas_debug',
                    'review' => 'atlas_review',
                    default => 'atlas_dev',
                };
            }
        }

        $domain = $this->support->stringOpt($opts, 'domain');
        $taskType = $this->support->stringOpt($opts, 'task_type');
        if ($domain !== null && $taskType !== null) {
            return $domain.'.'.$taskType;
        }

        return null;
    }

    /**
     * @param  array<int,string>  $sourceTypes
     * @return array<int,string>
     */
    public function expansionHandles(array $sourceTypes): array
    {
        $types = $this->support->uniqueStrings(array_merge($sourceTypes, [
            'code_intelligence',
            'memory_signals',
            'evidence_replay',
            'canonical_doc',
        ]));

        return array_values(array_map(
            static fn (string $sourceType): string => $sourceType === 'canonical_doc'
                ? 'recheck:canonical_doc'
                : 'expand:'.$sourceType,
            $types,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function contextDeliveryPolicySafety(bool $budgetApplied): array
    {
        return [
            'provider_safe_only' => true,
            'raw_text_exposed' => false,
            'providers_invoked' => false,
            'writes' => false,
            'auto_apply_scope' => $budgetApplied ? 'bounded_initial_budget_only' : 'none',
            'ref_demotion_auto_applied' => false,
            'source_expansion_auto_applied' => false,
            'requires_provider_pull_for_expansion' => true,
        ];
    }

    /**
     * @param  array<int,float>  $values
     */
    public function average(array $values): float
    {
        $values = array_values(array_filter($values, static fn (float $value): bool => is_finite($value)));

        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    public function nullableFloat(mixed $value): ?float
    {
        return AiValueNormalizer::finiteFloatOrNull($value);
    }
}
