<?php

namespace App\Services\Ai\Kernel\Decision;

use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;

class DynamicComputeMarketAdvisor
{
    public const SCHEMA_VERSION = 'atlas.dynamic_compute_market.v1';

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
        $report = $this->providerPerformance->reportForWindow(now()->subHours(168), filters: $filters);
        $eventCount = (int) ($report['event_count'] ?? 0);
        $successRate = is_numeric($report['success_rate'] ?? null) ? (float) $report['success_rate'] : null;
        $averageLatency = is_numeric($report['average_latency_seconds'] ?? null) ? (float) $report['average_latency_seconds'] : null;
        $averageCost = is_numeric($report['average_cost_microusd'] ?? null) ? (float) $report['average_cost_microusd'] : null;
        $fallbackCount = (int) ($report['fallback_count'] ?? 0);
        $failureCount = (int) ($report['failure_count'] ?? 0);
        $unknownCostCount = (int) ($report['unknown_cost_count'] ?? 0);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'shadow_advisory',
            'authority' => 'advisory_only_atlas_decide_remains_authority',
            'selected_provider' => $selectedProvider,
            'selected_model' => $selectedModel ?: 'selected-by-decide',
            'recommendation' => $this->recommendation($report, $successRate, $averageLatency, $fallbackCount, $failureCount, $unknownCostCount),
            'confidence' => $this->confidence($eventCount, $successRate),
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
     * @param  array<string,mixed>  $report
     */
    private function recommendation(array $report, ?float $successRate, ?float $averageLatency, int $fallbackCount, int $failureCount, int $unknownCostCount): string
    {
        if (! (bool) ($report['available'] ?? false) || (int) ($report['event_count'] ?? 0) === 0) {
            return 'collect_ap99_evidence';
        }

        if ($fallbackCount > 0 || $failureCount > 0 || ($successRate !== null && $successRate < 0.8)) {
            return 'review_provider_policy_patch';
        }

        if ($averageLatency !== null && $averageLatency > 120) {
            return 'benchmark_lower_latency_alternative';
        }

        if ($unknownCostCount > 0) {
            return 'configure_provider_cost_rates';
        }

        return 'keep_selected_provider';
    }

    private function confidence(int $eventCount, ?float $successRate): string
    {
        if ($eventCount < 5 || $successRate === null) {
            return 'low';
        }

        return $eventCount >= 20 ? 'high' : 'medium';
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
