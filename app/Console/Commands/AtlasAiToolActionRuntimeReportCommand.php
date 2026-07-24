<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use App\Services\Ai\Runtime\ToolActionRuntimeReadModel;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiToolActionRuntimeReportCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:tool-action-runtime-report
        {--hours=24 : Window size in hours}
        {--workspace= : Target workspace path. Defaults to current application base path}
        {--json : Print machine-readable JSON}';

    protected $description = 'Summarize Atlas Tool/Action Runtime readiness and evidence without executing tools.';

    public function handle(ToolActionRuntimeReadModel $readModel, KernelReplayReportInput $input): int
    {
        $hours = $input->hours($this->option('hours'));
        $workspace = is_string($this->option('workspace')) && trim((string) $this->option('workspace')) !== ''
            ? trim((string) $this->option('workspace'))
            : null;
        $report = $readModel->report(now()->subHours($hours), null, $workspace);
        $payload = [
            'status' => ($report['available'] ?? false) ? 'ok' : 'storage_unavailable',
            'hours' => $hours,
            'tool_action_runtime' => $report,
        ];

        return $this->render($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $report = (array) ($payload['tool_action_runtime'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Tool/Action Runtime</>', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hours', (string) ($payload['hours'] ?? '-'));
        $this->components->twoColumnDetail('Definitions', (string) ($report['definition_count'] ?? 0));
        $this->components->twoColumnDetail('Ready installations', (string) ($report['ready_installation_count'] ?? 0));
        $this->components->twoColumnDetail('Evidence runs', (string) ($report['evidence_run_count'] ?? 0));
        $this->components->twoColumnDetail('Failed required runs', (string) ($report['failed_required_run_count'] ?? 0));
        $this->components->twoColumnDetail('Open blocking findings', (string) ($report['blocking_open_finding_count'] ?? 0));
        $this->components->twoColumnDetail('Review signal', (string) data_get($report, 'review_signal.status', 'unknown'));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($report, 'review_signal.recommended_action', 'none'));

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
