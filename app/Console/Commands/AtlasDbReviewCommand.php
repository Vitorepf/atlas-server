<?php

namespace App\Console\Commands;

use App\Models\AtlasTask;
use App\Services\Engineering\PostgresEngineeringReviewService;
use Illuminate\Console\Command;

class AtlasDbReviewCommand extends Command
{
    protected $signature = 'atlas:db:review {--task-id=} {--workspace=} {--file=*} {--json}';

    protected $description = 'Run deterministic Postgres engineering review for a task.';

    public function handle(PostgresEngineeringReviewService $review): int
    {
        $task = $this->task();
        if (! $task) {
            return self::FAILURE;
        }

        $payload = $review->review($task, [
            'workspace' => $this->option('workspace'),
            'files' => (array) $this->option('file'),
        ]);

        return $this->render($payload);
    }

    protected function task(): ?AtlasTask
    {
        $taskId = trim((string) $this->option('task-id'));
        $task = $taskId !== '' ? AtlasTask::query()->find($taskId) : null;
        if (! $task) {
            $this->error('--task-id precisa apontar para uma task existente.');
        }

        return $task;
    }

    protected function render(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Postgres review', (string) $payload['status']);
            foreach ((array) $payload['findings'] as $finding) {
                $this->warn((string) ($finding['title'] ?? 'Finding'));
            }
        }

        return $payload['blocking'] ? self::FAILURE : self::SUCCESS;
    }
}
