<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\AtlasDev\Support\PipelineRunExecutor;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Programming\AtlasDev\Discovery\CodeDiscoveryEngine;
use App\Services\Ai\Programming\AtlasDev\Discovery\DocContextTierSelector;
use App\Services\Ai\Programming\AtlasDev\Discovery\OpenBrainProjectionAdapter;
use App\Services\Ai\Programming\AtlasDev\Gate\SymfonyProcessCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecisionEngine;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RunIdGenerator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecComposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassifier;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\SymfonyClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Runtime\ProcOpenRunWorkerDispatcher;
use App\Services\Ai\Programming\AtlasDev\Runtime\RunWorkerDispatcher;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenService;
use App\Services\Ai\Programming\AtlasDev\Surface\AtlasCliDevAdapter;
use App\Services\Ai\Programming\AtlasDev\Surface\SurfaceResponseFormatter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class AtlasDevServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReceiptStorage::class, function (Application $app): ReceiptStorage {
            $configured = $app['config']->get('atlas_dev.receipts_path');
            $path = is_string($configured) && $configured !== ''
                ? $configured
                : (function_exists('storage_path') ? storage_path('atlas-dev/receipts') : sys_get_temp_dir().'/atlas-dev/receipts');

            return new ReceiptStorage($path);
        });

        $this->app->singleton(AtlasDevFastPathOrchestrator::class, function (Application $app): AtlasDevFastPathOrchestrator {
            return new AtlasDevFastPathOrchestrator(
                intake: new IntakeNormalizer(new RunIdGenerator),
                classifier: new TaskClassifier,
                riskScorer: new RiskLevelScorer,
                specComposer: new SpecComposer,
                tierSelector: new DocContextTierSelector,
                codeDiscovery: new CodeDiscoveryEngine,
                openBrainAdapter: new OpenBrainProjectionAdapter($app->make(AtlasOpenBrainService::class)),
                promptBuilder: new ProviderPromptBuilder(
                    sectionsMapper: new PromptSectionsMapper,
                    renderer: new PromptRenderer,
                    qualityChecker: new PromptQualityChecker,
                ),
                routingEngine: new RoutingDecisionEngine,
                receiptStorage: $app->make(ReceiptStorage::class),
            );
        });

        // F-05 canonical: DB+HMAC ConfirmationTokenService. TTL is read inline
        // from config/atlas_dev.php (default 300s) so env overrides apply
        // without re-resolving the singleton.
        $this->app->singleton(ConfirmationTokenService::class, fn (): ConfirmationTokenService => new ConfirmationTokenService);

        $this->app->singleton(ClaudeCliGateway::class, fn (Application $app): ClaudeCliGateway => new SymfonyClaudeCliGateway($app['config']));
        $this->app->singleton(VerificationCommandRunner::class, fn (): VerificationCommandRunner => new SymfonyProcessCommandRunner);

        $this->app->bind(RunExecutor::class, PipelineRunExecutor::class);
        $this->app->singleton(RunWorkerDispatcher::class, ProcOpenRunWorkerDispatcher::class);

        $this->app->singleton(AtlasCliDevAdapter::class, function (Application $app): AtlasCliDevAdapter {
            return new AtlasCliDevAdapter(
                intake: new IntakeNormalizer(new RunIdGenerator),
                formatter: $app->make(SurfaceResponseFormatter::class),
            );
        });
    }
}
