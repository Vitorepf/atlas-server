<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Console\Command;

class AtlasAiSelfImprovementScheduleReportCommand extends Command
{
    protected $signature = 'atlas:ai:self-improvement-schedule-report
        {--hours=24 : Window size in hours}
        {--json : Print machine-readable JSON}';

    protected $description = 'Summarize replay evidence for Atlas AI Self-Improvement recurring schedule observations.';

    public function handle(AtlasLedgerReplayService $replay, KernelReplayReportInput $input): int
    {
        $hours = $input->hours($this->option('hours'));
        $report = $replay->selfImprovementScheduleReportForWindow(now()->subHours($hours));
        $payload = [
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'self_improvement_schedule_replay' => $report,
        ];

        return $this->render($payload);
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

        $report = (array) ($payload['self_improvement_schedule_replay'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Self-Improvement Schedule Report</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hours', (string) ($payload['hours'] ?? '-'));
        $this->components->twoColumnDetail('Schedule observations', (string) ($report['schedule_observation_count'] ?? 0));
        $this->components->twoColumnDetail('Envelopes', (string) ($report['envelope_count'] ?? 0));
        $this->components->twoColumnDetail('Latest health', (string) ($report['latest_health_status'] ?? '-'));
        $this->components->twoColumnDetail('Latest scheduler', (string) ($report['latest_scheduler_status'] ?? '-'));
        $this->components->twoColumnDetail('Latest plan hash', (string) ($report['latest_plan_hash'] ?? '-'));
        $this->components->twoColumnDetail('Review required', ($report['review_required'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Health', (string) data_get($report, 'health.status', 'unknown'));
        $this->components->twoColumnDetail('Review signal', (string) data_get($report, 'review_signal.status', 'unknown'));
        $this->components->twoColumnDetail('Review severity', (string) data_get($report, 'review_signal.severity', 'unknown'));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($report, 'review_signal.recommended_action', 'none'));

        $healthRows = collect((array) ($report['health_status_counts'] ?? []))
            ->map(fn (int $count, string $status): array => [$status, $count])
            ->values()
            ->all();
        if ($healthRows !== []) {
            $this->table(['health status', 'count'], $healthRows);
        }

        $issueRows = collect((array) ($report['issue_counts'] ?? []))
            ->map(fn (int $count, string $issue): array => [$issue, $count])
            ->values()
            ->all();
        if ($issueRows !== []) {
            $this->table(['issue', 'count'], $issueRows);
        }

        $recentRows = collect((array) ($report['recent_events'] ?? []))
            ->map(fn (array $event): array => [
                $event['health_status'] ?? '-',
                $event['scheduler_status'] ?? '-',
                $event['flow'] ?? '-',
                $event['registered_command_count'] ?? 0,
                $event['invalid_flow_count'] ?? 0,
                $event['envelope_id'] ?? '-',
            ])
            ->all();
        if ($recentRows !== []) {
            $this->table(['health', 'scheduler', 'flow', 'registered', 'invalid', 'envelope'], $recentRows);
        }

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
