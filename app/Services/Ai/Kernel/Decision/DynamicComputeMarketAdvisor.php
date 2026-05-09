<?php

namespace App\Services\Ai\Kernel\Decision;

use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;

class DynamicComputeMarketAdvisor
{
    public const SCHEMA_VERSION = 'atlas.dynamic_compute_market.v1';

    private const MIN_CONFIDENT_SAMPLE = 5;

    private const MIN_SUCCESS_RATE = 0.8;

    private const HIGH_LATENCY_THRESHOLD_SECONDS = 120.0;

    public function __construct(
        private readonly ProviderPerformanceProjection $providerPerformance,
    ) {}

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $taskProfile
     * @return array<string,mixed>
     */
    public function advise(
        string $selectedProvider,
        ?string $selectedModel,
        array $policy,
        array $taskProfile,
        ?string $specialistProfile,
    ): array {
        $filters = array_filter([
            'provider' => $selectedProvider,
            'domain' => $this->stringOrNull(data_get($policy, 'domain') ?: data_get($policy, 'profile_context.domain')),
            'flow' => $this->stringOrNull(data_get($policy, 'flow') ?: data_get($policy, 'profile_context.flow') ?: data_get($policy, 'profile_id')),
            'task_type' => $this->stringOrNull(data_get($taskProfile, 'task_type')),
            'specialist_profile' => $specialistProfile,
        ], fn (?string $value): bool => $value !== null);
        $since = now()->subHours(168);
        $report = $this->providerPerformance->reportForWindow($since, filters: $filters);
        $marketReport = $this->providerPerformance->reportForWindow($since, filters: collect($filters)->except('provider')->all());
        $eventCount = (int) ($report['event_count'] ?? 0);
        $successRate = is_numeric($report['success_rate'] ?? null) ? (float) $report['success_rate'] : null;
        $averageLatency = is_numeric($report['average_latency_seconds'] ?? null) ? (float) $report['average_latency_seconds'] : null;
        $averageCost = is_numeric($report['average_cost_microusd'] ?? null) ? (float) $report['average_cost_microusd'] : null;
        $fallbackCount = (int) ($report['fallback_count'] ?? 0);
        $failureCount = (int) ($report['failure_count'] ?? 0);
        $unknownCostCount = (int) ($report['unknown_cost_count'] ?? 0);
        $candidate = $this->benchmarkCandidate($selectedProvider, $successRate, $averageLatency, $averageCost, $marketReport);
        $recommendation = $this->recommendation($report, $successRate, $averageLatency, $fallbackCount, $failureCount, $unknownCostCount, $candidate);
        $confidence = $this->confidence($eventCount, $successRate);
        $risk = $this->risk($eventCount, $successRate, $fallbackCount, $failureCount, $unknownCostCount, $candidate);
        $recommendedNextAction = $this->recommendedNextAction($recommendation);
        $decisionFactors = $this->decisionFactors($report, $eventCount, $successRate, $averageLatency, $averageCost, $fallbackCount, $failureCount, $unknownCostCount, $candidate);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'shadow_advisory',
            'authority' => 'advisory_only_atlas_decide_remains_authority',
            'selected_provider' => $selectedProvider,
            'selected_model' => $selectedModel ?: 'selected-by-decide',
            'recommendation' => $recommendation,
            'confidence' => $confidence,
            'risk' => $risk,
            'recommended_next_action' => $recommendedNextAction,
            'recommendation_reason' => $this->recommendationReason($recommendation),
            'decision_factors' => $decisionFactors,
            'routing_control' => [
                'changes_provider' => false,
                'routing_authority' => 'atlas_decide',
                'selected_provider_preserved' => $selectedProvider,
                'selected_model_preserved' => $selectedModel ?: 'selected-by-decide',
                'provider_change_requires' => ['policy_patch', 'decision_receipt'],
            ],
            'proposal_gate' => $this->proposalGate($recommendation, $confidence, $risk, $candidate),
            'dimensions' => [
                'provider' => $selectedProvider,
                'model' => $selectedModel ?: 'selected-by-decide',
                'domain' => $filters['domain'] ?? null,
                'flow' => $filters['flow'] ?? null,
                'task_type' => $filters['task_type'] ?? null,
                'specialist_profile' => $filters['specialist_profile'] ?? null,
            ],
            'score_basis' => [
                'event_count' => $eventCount,
                'success_rate' => $successRate,
                'failure_count' => $failureCount,
                'fallback_count' => $fallbackCount,
                'average_latency_seconds' => $averageLatency,
                'average_cost_microusd' => $averageCost,
                'total_cost_microusd' => is_numeric($report['total_cost_microusd'] ?? null) ? (int) $report['total_cost_microusd'] : null,
                'costed_event_count' => (int) ($report['costed_event_count'] ?? 0),
                'unknown_cost_count' => $unknownCostCount,
                'cost_confidence_counts' => $report['cost_confidence_counts'] ?? [],
                'cost_mode_counts' => $report['cost_mode_counts'] ?? [],
                'cost_status' => $averageCost === null ? 'missing_cost_rate_or_unavailable' : 'available',
                'total_tokens' => is_numeric($report['total_tokens'] ?? null) ? (int) $report['total_tokens'] : null,
                'average_total_tokens' => is_numeric($report['average_total_tokens'] ?? null) ? (float) $report['average_total_tokens'] : null,
            ],
            'explanation' => [
                'summary' => $this->summary($recommendation),
                'quality_basis' => [
                    'success_rate' => $successRate,
                    'minimum_success_rate' => self::MIN_SUCCESS_RATE,
                    'failure_count' => $failureCount,
                    'fallback_count' => $fallbackCount,
                    'candidate_success_rate' => $candidate['success_rate'] ?? null,
                    'candidate_success_rate_delta' => $candidate['success_rate_delta'] ?? null,
                    'review_signal' => data_get($report, 'review_signal.status'),
                    'review_recommended_action' => data_get($report, 'review_signal.recommended_action'),
                ],
                'latency_basis' => [
                    'selected_average_latency_seconds' => $averageLatency,
                    'threshold_seconds' => self::HIGH_LATENCY_THRESHOLD_SECONDS,
                    'candidate_average_latency_seconds' => $candidate['average_latency_seconds'] ?? null,
                    'candidate_latency_delta_seconds' => $candidate['latency_delta_seconds'] ?? null,
                ],
                'cost_basis' => [
                    'selected_average_cost_microusd' => $averageCost,
                    'selected_costed_event_count' => (int) ($report['costed_event_count'] ?? 0),
                    'candidate_average_cost_microusd' => $candidate['average_cost_microusd'] ?? null,
                    'candidate_cost_delta_microusd' => $candidate['cost_delta_microusd'] ?? null,
                    'cost_confidence_counts' => $report['cost_confidence_counts'] ?? [],
                ],
                'missing_cost_status' => [
                    'status' => $averageCost === null ? 'missing_cost_rate_or_unavailable' : 'available',
                    'unknown_cost_count' => $unknownCostCount,
                    'costed_event_count' => (int) ($report['costed_event_count'] ?? 0),
                ],
                'sample_size' => [
                    'selected_event_count' => $eventCount,
                    'minimum_confident_sample' => self::MIN_CONFIDENT_SAMPLE,
                    'candidate_event_count' => $candidate['event_count'] ?? null,
                    'candidate_sample_status' => $candidate['sample_status'] ?? null,
                ],
                'confidence' => [
                    'band' => $confidence,
                    'risk' => $risk,
                ],
                'recommendation_reason' => $this->recommendationReason($recommendation),
                'decision_factors' => $decisionFactors,
                'recommended_next_action' => $recommendedNextAction,
            ],
            'benchmark_candidate' => $candidate,
            'market_basis' => [
                'available' => (bool) ($marketReport['available'] ?? false),
                'event_count' => (int) ($marketReport['event_count'] ?? 0),
                'group_count' => count((array) ($marketReport['groups'] ?? [])),
                'candidate_considered' => $candidate !== null,
                'filters' => $marketReport['filters'] ?? collect($filters)->except('provider')->all(),
            ],
            'budget' => [
                'enabled' => (bool) data_get($policy, 'budget_policy.enabled', false),
                'mode' => data_get($policy, 'budget_policy.mode'),
                'selected_provider_budget_status' => data_get($policy, "providers.{$selectedProvider}.budget_status"),
            ],
            'ap99' => [
                'available' => (bool) ($report['available'] ?? false),
                'filters' => $report['filters'] ?? $filters,
                'review_signal' => $report['review_signal'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $candidate
     * @return array<string,mixed>
     */
    private function proposalGate(string $recommendation, string $confidence, string $risk, ?array $candidate): array
    {
        $opensProposal = in_array($recommendation, [
            'review_provider_policy_patch',
            'benchmark_lower_latency_alternative',
            'configure_provider_cost_rates',
        ], true);

        return [
            'schema_version' => 'atlas.dynamic_compute_market.proposal_gate.v1',
            'mode' => 'proposal_only',
            'can_open_proposal' => $opensProposal,
            'can_change_provider' => false,
            'can_change_policy' => false,
            'can_mutate_decision_receipt' => false,
            'requires_human_review' => $opensProposal,
            'requires_benchmark' => $recommendation === 'benchmark_lower_latency_alternative',
            'requires_cost_rate_action' => $recommendation === 'configure_provider_cost_rates',
            'requires_policy_patch' => $recommendation === 'review_provider_policy_patch',
            'requires_new_decision_receipt_for_future_route_change' => true,
            'proposal_evidence_contract' => [
                'schema_version' => 'atlas.dynamic_compute_market.proposal_evidence.v1',
                'source' => 'ap99_provider_usage_projection',
                'replay_required' => true,
                'human_review_required' => $opensProposal,
                'benchmark_required_before_policy_patch' => $opensProposal,
                'policy_patch_status' => 'draft_only_until_benchmark_and_review',
                'required_events' => [
                    'PROVIDER_RETURNED',
                    'PROVIDER_FALLBACK',
                    'INBOX_ACTION_RECORDED',
                ],
                'required_artifacts' => [
                    'dynamic_compute_market_report',
                    'controlled_provider_benchmark',
                    'policy_patch_candidate',
                    'new_decision_receipt_for_future_route_change',
                ],
            ],
            'review_status' => $opensProposal ? 'review_required_before_any_policy_change' : 'no_policy_change_recommended',
            'confidence' => $confidence,
            'risk' => $risk,
            'candidate_provider' => $candidate['provider'] ?? null,
            'candidate_sample_status' => $candidate['sample_status'] ?? null,
            'allowed_actions' => $opensProposal
                ? ['open_inbox_proposal', 'run_controlled_benchmark', 'draft_policy_patch_for_review', 'discard_with_reason']
                : ['continue_monitoring'],
            'prohibited_actions' => [
                'provider_routing_change',
                'silent_policy_patch',
                'decision_receipt_mutation',
                'provider_preference_hardcode',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>|null  $candidate
     */
    private function recommendation(array $report, ?float $successRate, ?float $averageLatency, int $fallbackCount, int $failureCount, int $unknownCostCount, ?array $candidate): string
    {
        if (! (bool) ($report['available'] ?? false) || (int) ($report['event_count'] ?? 0) === 0) {
            return 'collect_ap99_evidence';
        }

        if ($fallbackCount > 0 || $failureCount > 0 || ($successRate !== null && $successRate < self::MIN_SUCCESS_RATE)) {
            return 'review_provider_policy_patch';
        }

        if ($averageLatency !== null && $averageLatency > self::HIGH_LATENCY_THRESHOLD_SECONDS) {
            return 'benchmark_lower_latency_alternative';
        }

        if ($unknownCostCount > 0) {
            return 'configure_provider_cost_rates';
        }

        if ($candidate !== null) {
            return 'benchmark_lower_latency_alternative';
        }

        return 'keep_selected_provider';
    }

    private function confidence(int $eventCount, ?float $successRate): string
    {
        if ($eventCount < self::MIN_CONFIDENT_SAMPLE || $successRate === null) {
            return 'low';
        }

        return $eventCount >= 20 ? 'high' : 'medium';
    }

    /**
     * @param  array<string,mixed>  $marketReport
     * @return array<string,mixed>|null
     */
    private function benchmarkCandidate(string $selectedProvider, ?float $selectedSuccessRate, ?float $selectedLatency, ?float $selectedCost, array $marketReport): ?array
    {
        $candidates = collect($marketReport['groups'] ?? [])
            ->filter(fn (mixed $group): bool => is_array($group))
            ->filter(fn (array $group): bool => (string) ($group['provider_cli'] ?? '') !== $selectedProvider)
            ->map(function (array $group) use ($selectedSuccessRate, $selectedLatency, $selectedCost): ?array {
                $eventCount = (int) ($group['event_count'] ?? 0);
                $successRate = is_numeric($group['success_rate'] ?? null) ? (float) $group['success_rate'] : null;
                $latency = is_numeric($group['average_latency_seconds'] ?? null) ? (float) $group['average_latency_seconds'] : null;
                $cost = is_numeric($group['average_cost_microusd'] ?? null) ? (float) $group['average_cost_microusd'] : null;
                $qualityComparable = $selectedSuccessRate === null || $successRate === null || $successRate >= max(self::MIN_SUCCESS_RATE, $selectedSuccessRate - 0.05);
                $latencyBetter = $selectedLatency !== null && $latency !== null && $latency < $selectedLatency;
                $costBetter = $selectedCost !== null && $cost !== null && $cost < $selectedCost;

                if (! $qualityComparable || (! $latencyBetter && ! $costBetter)) {
                    return null;
                }

                $sampleStatus = $eventCount < self::MIN_CONFIDENT_SAMPLE ? 'insufficient' : 'sufficient';

                return [
                    'provider' => (string) ($group['provider_cli'] ?? 'unknown'),
                    'event_count' => $eventCount,
                    'sample_status' => $sampleStatus,
                    'sample_confidence_rank' => $sampleStatus === 'sufficient' ? 1 : 0,
                    'success_rate' => $successRate,
                    'success_rate_delta' => $selectedSuccessRate !== null && $successRate !== null ? round($successRate - $selectedSuccessRate, 4) : null,
                    'average_latency_seconds' => $latency,
                    'average_cost_microusd' => $cost,
                    'latency_delta_seconds' => $selectedLatency !== null && $latency !== null ? round($latency - $selectedLatency, 4) : null,
                    'cost_delta_microusd' => $selectedCost !== null && $cost !== null ? round($cost - $selectedCost, 4) : null,
                    'improvement_basis' => array_values(array_filter([
                        $latencyBetter ? 'latency' : null,
                        $costBetter ? 'cost' : null,
                    ])),
                ];
            })
            ->filter()
            ->sort(fn (array $left, array $right): int => $this->compareBenchmarkCandidates($left, $right))
            ->values();

        $candidate = $candidates->first();

        if (! is_array($candidate)) {
            return null;
        }

        unset($candidate['sample_confidence_rank']);

        return $candidate;
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>|null  $candidate
     * @return array<string,mixed>
     */
    private function decisionFactors(array $report, int $eventCount, ?float $successRate, ?float $averageLatency, ?float $averageCost, int $fallbackCount, int $failureCount, int $unknownCostCount, ?array $candidate): array
    {
        return [
            'ap99_available' => (bool) ($report['available'] ?? false),
            'has_selected_sample' => $eventCount > 0,
            'has_confident_selected_sample' => $eventCount >= self::MIN_CONFIDENT_SAMPLE && $successRate !== null,
            'selected_quality_acceptable' => $successRate !== null && $successRate >= self::MIN_SUCCESS_RATE && $fallbackCount === 0 && $failureCount === 0,
            'has_quality_risk' => $fallbackCount > 0 || $failureCount > 0 || ($successRate !== null && $successRate < self::MIN_SUCCESS_RATE),
            'has_high_latency' => $averageLatency !== null && $averageLatency > self::HIGH_LATENCY_THRESHOLD_SECONDS,
            'has_missing_cost' => $averageCost === null || $unknownCostCount > 0,
            'has_market_candidate' => $candidate !== null,
            'candidate_has_sufficient_sample' => $candidate !== null && ($candidate['sample_status'] ?? null) === 'sufficient',
            'precedence' => [
                'collect_ap99_evidence',
                'review_provider_policy_patch',
                'benchmark_lower_latency_alternative_for_high_latency',
                'configure_provider_cost_rates',
                'benchmark_lower_latency_alternative_for_market_candidate',
                'keep_selected_provider',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $left
     * @param  array<string,mixed>  $right
     */
    private function compareBenchmarkCandidates(array $left, array $right): int
    {
        return ((int) ($right['sample_confidence_rank'] ?? 0) <=> (int) ($left['sample_confidence_rank'] ?? 0))
            ?: ((float) ($right['success_rate'] ?? 0.0) <=> (float) ($left['success_rate'] ?? 0.0))
            ?: ((float) ($left['average_latency_seconds'] ?? INF) <=> (float) ($right['average_latency_seconds'] ?? INF))
            ?: ((float) ($left['average_cost_microusd'] ?? INF) <=> (float) ($right['average_cost_microusd'] ?? INF))
            ?: ((int) ($right['event_count'] ?? 0) <=> (int) ($left['event_count'] ?? 0));
    }

    private function risk(int $eventCount, ?float $successRate, int $fallbackCount, int $failureCount, int $unknownCostCount, ?array $candidate): string
    {
        if ($fallbackCount > 0 || $failureCount > 0 || ($successRate !== null && $successRate < self::MIN_SUCCESS_RATE)) {
            return 'quality_risk';
        }

        if ($unknownCostCount > 0) {
            return 'cost_visibility_risk';
        }

        if ($eventCount < self::MIN_CONFIDENT_SAMPLE || $successRate === null || ($candidate !== null && ($candidate['sample_status'] ?? null) === 'insufficient')) {
            return 'sample_size_risk';
        }

        if ($candidate !== null) {
            return 'market_opportunity_risk';
        }

        return 'low';
    }

    private function recommendedNextAction(string $recommendation): string
    {
        return match ($recommendation) {
            'collect_ap99_evidence' => 'collect_ap99_evidence_before_policy_change',
            'review_provider_policy_patch' => 'open_reviewable_provider_performance_proposal',
            'benchmark_lower_latency_alternative' => 'run_controlled_provider_benchmark_before_policy_change',
            'configure_provider_cost_rates' => 'configure_provider_cost_rates',
            default => 'keep_selected_provider_and_continue_monitoring',
        };
    }

    private function summary(string $recommendation): string
    {
        return match ($recommendation) {
            'collect_ap99_evidence' => 'AP-99 does not yet have enough selected-provider evidence for this route.',
            'review_provider_policy_patch' => 'AP-99 shows quality or fallback risk; review policy before changing routing.',
            'benchmark_lower_latency_alternative' => 'A lower latency or lower cost alternative may exist, but it needs benchmark evidence before routing changes.',
            'configure_provider_cost_rates' => 'Quality is acceptable, but cost rates are missing or unavailable.',
            default => 'Selected provider remains the advised route under current AP-99 evidence.',
        };
    }

    private function recommendationReason(string $recommendation): string
    {
        return match ($recommendation) {
            'collect_ap99_evidence' => 'insufficient_selected_provider_ap99_evidence',
            'review_provider_policy_patch' => 'selected_provider_quality_or_fallback_risk',
            'benchmark_lower_latency_alternative' => 'latency_or_cost_candidate_requires_benchmark_receipt',
            'configure_provider_cost_rates' => 'selected_provider_cost_rate_missing_or_unavailable',
            default => 'selected_provider_supported_by_current_ap99_evidence',
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
