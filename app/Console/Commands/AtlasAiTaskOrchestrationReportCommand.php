<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use App\Services\Ai\Tasks\TaskOrchestrationReadModel;
use Illuminate\Console\Command;

class AtlasAiTaskOrchestrationReportCommand extends Command
{
    protected $signature = 'atlas:ai:task-orchestration-report
        {--hours=24 : Window size in hours}
        {--json : Print machine-readable JSON}';

    protected $description = 'Summarize Atlas task orchestration lifecycle receipts without executing providers, runtimes or agents.';

    public function handle(TaskOrchestrationReadModel $readModel, KernelReplayReportInput $input): int
    {
        $hours = $input->hours($this->option('hours'));
        $report = $readModel->report(now()->subHours($hours));
        $payload = [
            'status' => ($report['available'] ?? false) ? 'ok' : 'storage_unavailable',
            'hours' => $hours,
            'task_orchestration' => $report,
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

        $report = (array) ($payload['task_orchestration'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Task Orchestration</>', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hours', (string) ($payload['hours'] ?? '-'));
        $this->components->twoColumnDetail('Tasks', (string) ($report['task_count'] ?? 0));
        $this->components->twoColumnDetail('Events', (string) ($report['event_count'] ?? 0));
        $this->components->twoColumnDetail('Receipt events', (string) ($report['receipt_event_count'] ?? 0));
        $this->components->twoColumnDetail('Missing receipts', (string) ($report['missing_receipt_event_count'] ?? 0));
        $this->components->twoColumnDetail('Unsafe receipts', (string) ($report['unsafe_event_receipt_count'] ?? 0));
        $this->components->twoColumnDetail('External review tasks', (string) ($report['external_review_required_task_count'] ?? 0));
        $this->components->twoColumnDetail('Review signal', (string) data_get($report, 'review_signal.status', 'unknown'));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($report, 'review_signal.recommended_action', 'none'));

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
