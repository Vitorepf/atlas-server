<?php

namespace App\Providers;

use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Vox\Confirmation\VoxConfirmationService;
use App\Services\Ai\Vox\Execution\VoxClaudeCliExecutor;
use App\Services\Ai\Vox\Execution\VoxCodexCliExecutor;
use App\Services\Ai\Vox\Execution\VoxExecutor;
use App\Services\Ai\Vox\Execution\VoxExecutorRouter;
use App\Services\Ai\Vox\Execution\VoxFilesystemEditExecutor;
use App\Services\Ai\Vox\Execution\VoxNoteCaptureExecutor;
use App\Services\Ai\Vox\Execution\VoxTerminalProposeExecutor;
use App\Services\Ai\Vox\VoxActionOutcomeService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SkillBundleStore::class);

        // Vox V3 confirmation cache: pin the default cache repository so the
        // service stays on the same store across the (intent → execute)
        // round-trip. Laravel does not auto-resolve CacheRepository
        // otherwise, and a per-injection `new Repository()` would lose the
        // confirmation token between requests.
        $this->app->singleton(VoxConfirmationService::class, function ($app) {
            return new VoxConfirmationService($app->make(CacheRepository::class));
        });
        $this->app->bind(CacheRepository::class, fn () => Cache::store());

        // Vox V3 governed executors. Order is irrelevant — the router keys
        // them by `id()`. Each executor self-reports availability so the
        // controller can advertise an honest health state.
        $this->app->singleton(VoxExecutorRouter::class, function ($app) {
            /** @var list<VoxExecutor> $executors */
            $executors = [
                $app->make(VoxTerminalProposeExecutor::class),
                $app->make(VoxNoteCaptureExecutor::class),
                $app->make(VoxCodexCliExecutor::class),
                $app->make(VoxClaudeCliExecutor::class),
                $app->make(VoxFilesystemEditExecutor::class),
            ];

            return new VoxExecutorRouter(
                executors: $executors,
                outcomes: $app->make(VoxActionOutcomeService::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();
    }
}
