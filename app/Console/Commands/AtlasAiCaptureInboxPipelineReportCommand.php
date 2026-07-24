<?php

namespace App\Console\Commands;

use App\Services\Ai\Capture\CaptureInboxPipelineReadModel;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiCaptureInboxPipelineReportCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:capture-inbox-pipeline-report
        {--hours=24 : Window size in hours}
        {--json : Print machine-readable JSON}';

    protected $description = 'Summarize Capture/Inbox pipeline integrity without mutating memory, context or curation state.';

    public function handle(CaptureInboxPipelineReadModel $readModel): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $report = $readModel->report($hours);
        $payload = [
            'status' => $report['status'] ?? 'unknown',
            'hours' => $hours,
            'capture_inbox_pipeline' => $report,
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

            return ($payload['status'] ?? null) === 'storage_unavailable' ? self::FAILURE : self::SUCCESS;
        }

        $report = (array) ($payload['capture_inbox_pipeline'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Capture/Inbox Pipeline</>', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hours', (string) ($payload['hours'] ?? '-'));
        $this->components->twoColumnDetail('Captures', (string) ($report['capture_count'] ?? 0));
        $this->components->twoColumnDetail('Quarantined captures', (string) ($report['quarantined_capture_count'] ?? 0));
        $this->components->twoColumnDetail('Content intelligence', (string) ($report['content_intelligence_count'] ?? 0));
        $this->components->twoColumnDetail('Unsafe captures', (string) ($report['unsafe_capture_count'] ?? 0));
        $this->components->twoColumnDetail('Semantic proposals', (string) ($report['proposal_count'] ?? 0));
        $this->components->twoColumnDetail('Pending proposals', (string) ($report['pending_proposal_count'] ?? 0));
        $this->components->twoColumnDetail('Memory deltas', (string) ($report['memory_delta_count'] ?? 0));
        $this->components->twoColumnDetail('Active inbox items', (string) ($report['active_inbox_item_count'] ?? 0));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($report, 'review_signal.recommended_action', 'none'));

        $reasons = (array) data_get($report, 'review_signal.reasons', []);
        if ($reasons !== []) {
            $this->components->bulletList($reasons);
        }

        return ($payload['status'] ?? null) === 'storage_unavailable' ? self::FAILURE : self::SUCCESS;
    }
}
