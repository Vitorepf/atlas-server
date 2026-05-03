<?php

namespace App\Console\Commands;

use App\Services\Engineering\PostgresEngineeringReviewService;

class AtlasDbExplainCommand extends AtlasDbReviewCommand
{
    protected $signature = 'atlas:db:explain {--task-id=} {--query=} {--json}';

    protected $description = 'Record Postgres query-plan evidence for a task.';

    public function handle(PostgresEngineeringReviewService $review): int
    {
        $task = $this->task();
        if (! $task) {
            return self::FAILURE;
        }

        return $this->render($review->explain($task, [
            'query' => $this->option('query'),
        ]));
    }
}
