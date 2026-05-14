<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Http\Controllers\AtlasCodeForgeExecutionController;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasForgeLiveExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class AtlasCodeForgeLiveExecutionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $projectId,
        public readonly bool $simulateFailure,
        public readonly string $executionId,
    ) {
        $this->onQueue('atlas-code-forge');
    }

    public function handle(
        AtlasForgeLiveExecutionService $service,
        AtlasCodeForgeExecutionController $controller,
    ): void {
        $project = AtlasProject::query()->find($this->projectId);
        if (! $project instanceof AtlasProject) {
            return;
        }

        $controller->executeAsyncJob($project, $service, $this->simulateFailure, $this->executionId);
    }

    public function failed(?Throwable $exception): void
    {
        if (! $exception) {
            return;
        }

        $project = AtlasProject::query()->find($this->projectId);
        if (! $project instanceof AtlasProject) {
            return;
        }

        app(AtlasCodeForgeExecutionController::class)->markAsyncFailed($project, $this->executionId, $exception);
    }
}
