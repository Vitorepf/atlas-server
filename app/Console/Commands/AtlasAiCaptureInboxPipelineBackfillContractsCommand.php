<?php

namespace App\Console\Commands;

use App\Services\Ai\Capture\CaptureInboxPipelineContractBackfill;
use Illuminate\Console\Command;

class AtlasAiCaptureInboxPipelineBackfillContractsCommand extends Command
{
    protected $signature = 'atlas:ai:capture-inbox-pipeline-backfill-contracts
        {--hours=720 : Window size in hours}
        {--write : Apply the planned conservative metadata repairs}
        {--json : Print machine-readable JSON}';

    protected $description = 'Backfill conservative Capture/Inbox contracts for legacy captures; dry-run by default.';

    public function handle(CaptureInboxPipelineContractBackfill $backfill): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $report = $backfill->run($hours, (bool) $this->option('write'));
        $payload = [
            'status' => $report['status'] ?? 'unknown',
            'hours' => $hours,
            'capture_inbox_pipeline_backfill' => $report,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? null) === 'storage_unavailable' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Capture/Inbox Contract Backfill</>', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Mode', (bool) $this->option('write') ? 'write' : 'dry-run');
        $this->components->twoColumnDetail('Planned repairs', (string) ($report['planned_count'] ?? 0));
        $this->components->twoColumnDetail('Applied repairs', (string) ($report['applied_count'] ?? 0));

        return ($payload['status'] ?? null) === 'storage_unavailable' ? self::FAILURE : self::SUCCESS;
    }
}
