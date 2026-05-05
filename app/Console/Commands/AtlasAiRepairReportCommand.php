<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use Illuminate\Console\Command;

class AtlasAiRepairReportCommand extends Command
{
    protected $signature = 'atlas:ai:repair-report
        {--hours=24 : Window size in hours}
        {--status= : Filter by repair decision status}
        {--strategy= : Filter by repair strategy}
        {--failure-domain= : Filter by failure domain}
        {--emitter-stage= : Filter by ledger emitter stage}
        {--json : Print machine-readable JSON}';

    protected $description = 'Summarize Atlas AI Repair Loop evidence for a recent time window.';

    public function handle(AtlasLedgerReplayService $replay): int
    {
        $hours = max(1, min(720, (int) $this->option('hours')));
        $filters = $this->filters();
        $report = $replay->repairReportForWindow(now()->subHours($hours), filters: $filters);
        $payload = [
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_repair' => $report,
        ];

        return $this->render($payload);
    }

    /**
     * @return array<string,string>
     */
    private function filters(): array
    {
        $filters = [];

        foreach ([
            'status' => 'status',
            'strategy' => 'strategy',
            'failure-domain' => 'failure_domain',
            'emitter-stage' => 'emitter_stage',
        ] as $option => $key) {
            $value = $this->option($option);
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
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

        $repair = (array) ($payload['kernel_repair'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Repair Report</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hours', (string) ($payload['hours'] ?? '-'));
        $this->components->twoColumnDetail('Filters', json_encode($payload['filters'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $this->components->twoColumnDetail('Repair events', (string) ($repair['repair_event_count'] ?? 0));
        $this->components->twoColumnDetail('Envelopes', (string) ($repair['envelope_count'] ?? 0));
        $this->components->twoColumnDetail('Initiated', (string) ($repair['initiated_count'] ?? 0));
        $this->components->twoColumnDetail('Completed', (string) ($repair['completed_count'] ?? 0));
        $this->components->twoColumnDetail('Latest status', (string) ($repair['latest_status'] ?? '-'));
        $this->components->twoColumnDetail('Latest strategy', (string) ($repair['latest_strategy'] ?? '-'));
        $this->components->twoColumnDetail('Needs human review', ($repair['requires_human_review'] ?? false) ? 'yes' : 'no');

        $strategyRows = collect((array) ($repair['strategy_counts'] ?? []))
            ->map(fn (int $count, string $strategy): array => [$strategy, $count])
            ->values()
            ->all();
        if ($strategyRows !== []) {
            $this->table(['strategy', 'count'], $strategyRows);
        }

        $recentRows = collect((array) ($repair['recent_events'] ?? []))
            ->map(fn (array $event): array => [
                $event['event_type'] ?? '-',
                $event['status'] ?? '-',
                $event['strategy'] ?? '-',
                $event['failure_domain'] ?? '-',
                $event['envelope_id'] ?? '-',
            ])
            ->all();
        if ($recentRows !== []) {
            $this->table(['event', 'status', 'strategy', 'failure', 'envelope'], $recentRows);
        }

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
