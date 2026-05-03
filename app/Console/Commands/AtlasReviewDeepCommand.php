<?php

namespace App\Console\Commands;

use App\Models\AtlasTask;
use App\Services\Engineering\EngineeringReviewService;
use Illuminate\Console\Command;

class AtlasReviewDeepCommand extends Command
{
    protected $signature = 'atlas:review:deep
        {--task-id=}
        {--finding=* : JSON finding payload}
        {--json}';

    protected $description = 'Run or record a deep engineering review with severity, confidence and category thresholds.';

    public function handle(EngineeringReviewService $reviews): int
    {
        $taskId = trim((string) $this->option('task-id'));
        $task = $taskId !== '' ? AtlasTask::query()->find($taskId) : null;
        if (! $task) {
            $this->error('--task-id precisa apontar para uma task existente.');

            return self::FAILURE;
        }

        $findings = collect((array) $this->option('finding'))
            ->map(fn (mixed $finding): mixed => is_string($finding) ? json_decode($finding, true) : null)
            ->filter(fn (mixed $finding): bool => is_array($finding))
            ->values()
            ->all();

        $payload = $reviews->deepReview($task, ['findings' => $findings, 'recorded_by' => 'atlas_cli']);
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Deep review', $payload['blocking'] ? 'blocked' : 'passed');
            $this->components->twoColumnDetail('Blocking findings', (string) data_get($payload, 'summary.blocking_count', 0));
        }

        return $payload['blocking'] ? self::FAILURE : self::SUCCESS;
    }
}
