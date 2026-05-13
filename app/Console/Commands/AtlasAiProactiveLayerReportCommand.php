<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use App\Services\Ai\Mobile\ProactiveLayerReadModel;
use Illuminate\Console\Command;

class AtlasAiProactiveLayerReportCommand extends Command
{
    protected $signature = 'atlas:ai:proactive-layer-report
        {--hours=24 : Window size in hours}
        {--json : Print machine-readable JSON}';

    protected $description = 'Summarize Atlas proactive insight/watch and notification evidence for a recent time window.';

    public function handle(ProactiveLayerReadModel $readModel, KernelReplayReportInput $input): int
    {
        $hours = $input->hours($this->option('hours'));
        $report = $readModel->report(now()->subHours($hours));
        $payload = [
            'status' => ($report['available'] ?? false) ? 'ok' : 'storage_unavailable',
            'hours' => $hours,
            'proactive_layer' => $report,
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

        $report = (array) ($payload['proactive_layer'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Proactive Layer</>', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hours', (string) ($payload['hours'] ?? '-'));
        $this->components->twoColumnDetail('Insight watch runs', (string) ($report['run_count'] ?? 0));
        $this->components->twoColumnDetail('Failed runs', (string) ($report['failed_run_count'] ?? 0));
        $this->components->twoColumnDetail('Candidates', (string) ($report['candidate_count'] ?? 0));
        $this->components->twoColumnDetail('Insights', (string) ($report['insight_item_count'] ?? 0));
        $this->components->twoColumnDetail('Push deliveries', (string) ($report['push_delivery_count'] ?? 0));
        $this->components->twoColumnDetail('Review signal', (string) data_get($report, 'review_signal.status', 'unknown'));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($report, 'review_signal.recommended_action', 'none'));

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
