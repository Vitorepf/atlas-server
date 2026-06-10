<?php

namespace App\Services\Ai\Kernel\Evidence;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ProviderPerformanceProjection
{
    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,mixed>
     */
    public function reportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array
    {
        $until ??= now();
        $filters = $this->normalizedFilters($filters);

        if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return [
                'available' => false,
                'schema_version' => ProviderUsagePayload::SCHEMA_VERSION,
                'window' => [
                    'since' => $since->toJSON(),
                    'until' => $until->toJSON(),
                ],
                'filters' => $filters,
                ...$this->summary(new Collection),
                'review_signal' => $this->unavailableReviewSignal(),
                'recent_events' => [],
            ];
        }

        $events = AtlasLedgerEvent::query()
            ->whereIn('event_type', [
                LedgerEventType::ProviderReturned->value,
                LedgerEventType::ProviderFallback->value,
            ])
            ->whereBetween('occurred_at', [$since, $until])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get()
            ->map(fn (AtlasLedgerEvent $event): array => $this->eventFromLedger($event))
            ->filter(fn (array $event): bool => $this->matchesFilters($event, $filters))
            ->values();
        $summary = $this->summary($events);

        return [
            'available' => true,
            'schema_version' => ProviderUsagePayload::SCHEMA_VERSION,
            'window' => [
                'since' => $since->toJSON(),
                'until' => $until->toJSON(),
            ],
            'filters' => $filters,
            ...$summary,
            'review_signal' => $this->reviewSignal($summary),
            'recent_events' => $events
                ->reverse()
                ->take(10)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function eventFromLedger(AtlasLedgerEvent $event): array
    {
        $payload = $event->payload ?? [];

        return [
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'envelope_id' => $event->envelope_id,
            'receipt_id' => $event->receipt_id,
            'trace_id' => $event->trace_id,
            'occurred_at' => $event->occurred_at?->toJSON(),
            'provider_cli' => (string) data_get($payload, 'provider_cli', data_get($payload, 'provider', 'unknown')),
            'model' => data_get($payload, 'model_name_if_available'),
            'domain' => data_get($payload, 'domain'),
            'flow' => data_get($payload, 'flow'),
            'task_type' => data_get($payload, 'task_type'),
            'specialist_profile' => data_get($payload, 'specialist_profile'),
            'risk' => data_get($payload, 'risk'),
            'phase' => data_get($payload, 'phase'),
            'exit_status' => data_get($payload, 'exit_status'),
            'failure_reason' => data_get($payload, 'failure_reason'),
            'latency_seconds' => is_numeric(data_get($payload, 'latency_seconds')) ? (float) data_get($payload, 'latency_seconds') : null,
            'attempt_number' => (int) data_get($payload, 'attempt_number', 0),
            'repair_count' => (int) data_get($payload, 'repair_count', 0),
            'quality_gate_result' => data_get($payload, 'quality_gate_result'),
            'selection_mode' => data_get($payload, 'selection_mode', 'unknown'),
            'router_decision_id' => data_get($payload, 'router_decision_id'),
            'fallback_provider' => data_get($payload, 'fallback_provider'),
            'input_context_size_estimate' => (int) data_get($payload, 'input_context_size_estimate', 0),
            'output_size_estimate' => (int) data_get($payload, 'output_size_estimate', 0),
            'prompt_tokens' => $this->intOrNull(data_get($payload, 'prompt_tokens')),
            'completion_tokens' => $this->intOrNull(data_get($payload, 'completion_tokens')),
            'total_tokens' => $this->intOrNull(data_get($payload, 'total_tokens')),
            'estimated_tokens' => $this->intOrNull(data_get($payload, 'estimated_tokens')),
            'token_source' => data_get($payload, 'token_source'),
            'cost_microusd' => $this->intOrNull(data_get($payload, 'cost_microusd')),
            'cost_confidence' => data_get($payload, 'cost_confidence', 'unknown'),
            'cost_source' => data_get($payload, 'cost_source'),
            'cost_mode' => data_get($payload, 'cost_mode', 'unknown'),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function summary(Collection $events): array
    {
        $returned = $events->where('event_type', LedgerEventType::ProviderReturned->value);
        $fallbacks = $events->where('event_type', LedgerEventType::ProviderFallback->value);
        $succeeded = $returned->where('exit_status', 'succeeded');
        $failed = $returned->where('exit_status', 'failed');
        $costed = $events->filter(fn (array $event): bool => is_numeric($event['cost_microusd'] ?? null));
        $tokenized = $events->filter(fn (array $event): bool => is_numeric($event['total_tokens'] ?? null));

        return [
            'event_count' => $events->count(),
            'returned_count' => $returned->count(),
            'fallback_count' => $fallbacks->count(),
            'success_count' => $succeeded->count(),
            'failure_count' => $failed->count() + $fallbacks->count(),
            'success_rate' => $returned->count() === 0 ? null : round($succeeded->count() / $returned->count(), 4),
            'average_latency_seconds' => $this->average($returned->pluck('latency_seconds')->filter(fn (mixed $value): bool => is_numeric($value))),
            'average_repair_count' => $this->average($events->pluck('repair_count')->filter(fn (mixed $value): bool => is_numeric($value))),
            'total_tokens' => $tokenized->sum('total_tokens'),
            'average_total_tokens' => $this->average($tokenized->pluck('total_tokens')),
            'total_cost_microusd' => $costed->sum('cost_microusd'),
            'average_cost_microusd' => $this->average($costed->pluck('cost_microusd')),
            'costed_event_count' => $costed->count(),
            'unknown_cost_count' => $events->filter(fn (array $event): bool => ($event['cost_confidence'] ?? 'unknown') === 'unknown')->count(),
            'cost_confidence_counts' => $events->pluck('cost_confidence')->filter()->countBy()->all(),
            'cost_mode_counts' => $events->pluck('cost_mode')->filter()->countBy()->all(),
            'token_source_counts' => $events->pluck('token_source')->filter()->countBy()->all(),
            'provider_counts' => $events->countBy('provider_cli')->all(),
            'domain_counts' => $events->pluck('domain')->filter()->countBy()->all(),
            'task_type_counts' => $events->pluck('task_type')->filter()->countBy()->all(),
            'specialist_profile_counts' => $events->pluck('specialist_profile')->filter()->countBy()->all(),
            'failure_reason_counts' => $events->pluck('failure_reason')->filter()->countBy()->all(),
            'selection_mode_counts' => $events->pluck('selection_mode')->filter()->countBy()->all(),
            'groups' => $events
                ->groupBy(fn (array $event): string => implode('|', [
                    $event['provider_cli'] ?: 'unknown',
                    $event['domain'] ?: 'unknown',
                    $event['specialist_profile'] ?: 'unknown',
                    $event['task_type'] ?: 'unknown',
                ]))
                ->map(fn (Collection $group, string $key): array => $this->groupSummary($key, $group))
                ->sortByDesc('success_rate')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,string>
     */
    private function reviewSignal(array $summary): array
    {
        if ((int) ($summary['event_count'] ?? 0) === 0) {
            return [
                'status' => 'unknown',
                'severity' => 'low',
                'recommended_action' => 'wait_for_provider_usage_evidence',
            ];
        }

        if (($summary['success_rate'] ?? null) !== null && (float) $summary['success_rate'] < 0.5) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'recommended_action' => 'open_reviewable_provider_performance_proposal',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'recommended_action' => 'none',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function unavailableReviewSignal(): array
    {
        return [
            'status' => 'unknown',
            'severity' => 'low',
            'recommended_action' => 'wait_for_provider_usage_evidence',
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    private function groupSummary(string $key, Collection $events): array
    {
        [$provider, $domain, $specialistProfile, $taskType] = array_pad(explode('|', $key, 4), 4, 'unknown');
        $returned = $events->where('event_type', LedgerEventType::ProviderReturned->value);
        $succeeded = $returned->where('exit_status', 'succeeded');
        $fallbacks = $events->where('event_type', LedgerEventType::ProviderFallback->value);
        $costed = $events->filter(fn (array $event): bool => is_numeric($event['cost_microusd'] ?? null));
        $tokenized = $events->filter(fn (array $event): bool => is_numeric($event['total_tokens'] ?? null));

        return [
            'provider_cli' => $provider,
            'domain' => $domain,
            'specialist_profile' => $specialistProfile,
            'task_type' => $taskType,
            'event_count' => $events->count(),
            'returned_count' => $returned->count(),
            'fallback_count' => $fallbacks->count(),
            'success_count' => $succeeded->count(),
            'failure_count' => $returned->where('exit_status', 'failed')->count() + $fallbacks->count(),
            'success_rate' => $returned->count() === 0 ? null : round($succeeded->count() / $returned->count(), 4),
            'average_latency_seconds' => $this->average($returned->pluck('latency_seconds')->filter(fn (mixed $value): bool => is_numeric($value))),
            'average_repair_count' => $this->average($events->pluck('repair_count')->filter(fn (mixed $value): bool => is_numeric($value))),
            'total_tokens' => $tokenized->sum('total_tokens'),
            'average_total_tokens' => $this->average($tokenized->pluck('total_tokens')),
            'total_cost_microusd' => $costed->sum('cost_microusd'),
            'average_cost_microusd' => $this->average($costed->pluck('cost_microusd')),
            'costed_event_count' => $costed->count(),
            'unknown_cost_count' => $events->filter(fn (array $event): bool => ($event['cost_confidence'] ?? 'unknown') === 'unknown')->count(),
            'failure_reason_counts' => $events->pluck('failure_reason')->filter()->countBy()->all(),
            'selection_mode_counts' => $events->pluck('selection_mode')->filter()->countBy()->all(),
            'cost_confidence_counts' => $events->pluck('cost_confidence')->filter()->countBy()->all(),
            'cost_mode_counts' => $events->pluck('cost_mode')->filter()->countBy()->all(),
        ];
    }

    /**
     * @param  Collection<int,mixed>  $values
     */
    private function average(Collection $values): ?float
    {
        return $values->isEmpty() ? null : round((float) $values->avg(), 4);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedFilters(array $filters): array
    {
        return collect($filters)
            ->only(['provider', 'provider_cli', 'domain', 'flow', 'task_type', 'specialist_profile', 'risk', 'selection_mode'])
            ->map(fn (mixed $value): ?string => is_scalar($value) ? trim((string) $value) : null)
            ->filter(fn (?string $value): bool => $value !== null && $value !== '')
            ->mapWithKeys(fn (string $value, string $key): array => [$key === 'provider' ? 'provider_cli' : $key => $value])
            ->all();
    }

    /**
     * @param  array<string,mixed>  $event
     * @param  array<string,string>  $filters
     */
    private function matchesFilters(array $event, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if ((string) ($event[$key] ?? '') !== $value) {
                return false;
            }
        }

        return true;
    }
}
