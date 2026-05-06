<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;
use Illuminate\Console\Command;

class AtlasAiProviderPerformanceCommand extends Command
{
    protected $signature = 'atlas:ai:provider-performance
        {--hours=168 : Window size in hours}
        {--provider= : Filter by provider CLI}
        {--domain= : Filter by domain}
        {--flow= : Filter by flow}
        {--task-type= : Filter by task type}
        {--specialist-profile= : Filter by specialist profile}
        {--risk= : Filter by risk}
        {--selection-mode= : Filter by auto/manual_override selection mode}
        {--json : Print machine-readable JSON}';

    protected $description = 'Summarize Atlas AI provider usage and performance evidence for a recent time window.';

    public function handle(ProviderPerformanceProjection $performance, KernelReplayReportInput $input): int
    {
        $hours = $input->hours($this->option('hours'));
        $filters = $this->filters($input);
        $report = $performance->reportForWindow(now()->subHours($hours), filters: $filters);
        $payload = [
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'filters' => $filters,
            'provider_performance' => $report,
        ];

        return $this->render($payload);
    }

    /**
     * @return array<string,string>
     */
    private function filters(KernelReplayReportInput $input): array
    {
        return $input->aliasedScalarFilters($this->options(), [
            'provider_cli' => ['provider'],
            'domain' => ['domain'],
            'flow' => ['flow'],
            'task_type' => ['task-type'],
            'specialist_profile' => ['specialist-profile'],
            'risk' => ['risk'],
            'selection_mode' => ['selection-mode'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $report = (array) ($payload['provider_performance'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Provider Performance</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hours', (string) ($payload['hours'] ?? '-'));
        $this->components->twoColumnDetail('Filters', json_encode($payload['filters'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $this->components->twoColumnDetail('Events', (string) ($report['event_count'] ?? 0));
        $this->components->twoColumnDetail('Returned', (string) ($report['returned_count'] ?? 0));
        $this->components->twoColumnDetail('Fallbacks', (string) ($report['fallback_count'] ?? 0));
        $this->components->twoColumnDetail('Success rate', $report['success_rate'] === null ? '-' : (string) $report['success_rate']);
        $this->components->twoColumnDetail('Avg latency seconds', $report['average_latency_seconds'] === null ? '-' : (string) $report['average_latency_seconds']);
        $this->components->twoColumnDetail('Avg repair count', $report['average_repair_count'] === null ? '-' : (string) $report['average_repair_count']);
        $this->components->twoColumnDetail('Total tokens', (string) ($report['total_tokens'] ?? 0));
        $this->components->twoColumnDetail('Avg tokens', $report['average_total_tokens'] === null ? '-' : (string) $report['average_total_tokens']);
        $this->components->twoColumnDetail('Total cost microusd', (string) ($report['total_cost_microusd'] ?? 0));
        $this->components->twoColumnDetail('Avg cost microusd', $report['average_cost_microusd'] === null ? '-' : (string) $report['average_cost_microusd']);
        $this->components->twoColumnDetail('Unknown cost events', (string) ($report['unknown_cost_count'] ?? 0));
        $this->components->twoColumnDetail('Review signal', (string) data_get($report, 'review_signal.status', 'unknown'));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($report, 'review_signal.recommended_action', 'none'));

        $groupRows = collect((array) ($report['groups'] ?? []))
            ->map(fn (array $group): array => [
                $group['provider_cli'] ?? '-',
                $group['domain'] ?? '-',
                $group['specialist_profile'] ?? '-',
                $group['task_type'] ?? '-',
                $group['returned_count'] ?? 0,
                $group['fallback_count'] ?? 0,
                $group['success_rate'] ?? '-',
                $group['average_latency_seconds'] ?? '-',
                $group['average_repair_count'] ?? '-',
                $group['average_total_tokens'] ?? '-',
                $group['average_cost_microusd'] ?? '-',
            ])
            ->values()
            ->all();

        if ($groupRows !== []) {
            $this->table(['provider', 'domain', 'specialist', 'task', 'returned', 'fallbacks', 'success', 'avg latency', 'avg repairs', 'avg tokens', 'avg cost'], $groupRows);
        }

        $failureRows = collect((array) ($report['failure_reason_counts'] ?? []))
            ->map(fn (int $count, string $reason): array => [$reason, $count])
            ->values()
            ->all();
        if ($failureRows !== []) {
            $this->table(['failure reason', 'count'], $failureRows);
        }

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
