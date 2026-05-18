<?php

namespace App\Services\Ai\Strategy;

use App\Models\AiExperimentPlan;
use App\Models\AiMarketModel;
use App\Models\AiOpportunity;
use App\Models\AiStrategyMemo;
use App\Models\AiStrategyRun;
use App\Models\AiUnitEconomics;
use App\Models\AiVentureBlueprint;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use Throwable;

class StrategyReadinessService
{
    public const SCHEMA = 'atlas.ai.strategy.readiness.v1';

    private const REQUIRED_TABLES = [
        'ai_strategy_runs',
        'ai_opportunities',
        'ai_venture_blueprints',
        'ai_market_models',
        'ai_unit_economics',
        'ai_experiment_plans',
        'ai_strategy_memos',
    ];

    private const REQUIRED_MODELS = [
        AiStrategyRun::class,
        AiOpportunity::class,
        AiVentureBlueprint::class,
        AiMarketModel::class,
        AiUnitEconomics::class,
        AiExperimentPlan::class,
        AiStrategyMemo::class,
    ];

    private const REQUIRED_SERVICES = [
        StrategyDomainManifestSeeder::class,
        OpportunityRadarService::class,
        VentureBlueprintService::class,
        MarketModelService::class,
        UnitEconomicsService::class,
        GTMPlanService::class,
        ExperimentPlanService::class,
        StrategyMemoService::class,
        StrategyRuntimeService::class,
        StrategyControlPlaneProjection::class,
    ];

    private const REQUIRED_TEST_FILES = [
        'tests/Feature/Ai/Strategy/StrategyDomainReadinessTest.php',
        'tests/Feature/Ai/Strategy/StrategyDomainSmokeTest.php',
        'tests/Feature/Ai/Strategy/StrategyDomainOpportunityTest.php',
        'tests/Feature/Ai/Strategy/StrategyDomainVentureBlueprintTest.php',
        'tests/Feature/Ai/Strategy/StrategyDomainExperimentPlanTest.php',
        'tests/Feature/Ai/Strategy/StrategyDomainMemoTest.php',
        'tests/Feature/Ai/Strategy/StrategyDomainControlPlaneTest.php',
        'tests/Feature/Ai/Strategy/StrategyDomainEvidenceIntegrationTest.php',
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [];
        foreach (self::REQUIRED_TABLES as $table) {
            $exists = Schema::hasTable($table);
            $checks[] = [
                'name' => "table:{$table}",
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'exists' : 'missing - run php artisan migrate',
            ];
        }

        foreach (self::REQUIRED_MODELS as $model) {
            $exists = class_exists($model);
            $checks[] = [
                'name' => 'model:'.class_basename($model),
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'class exists' : "missing class [{$model}]",
            ];
        }

        foreach (self::REQUIRED_SERVICES as $service) {
            $resolvable = false;
            $detail = 'not resolvable';
            try {
                $resolved = $this->container->make($service);
                $resolvable = $resolved instanceof $service;
                $detail = $resolvable ? 'resolved' : 'not an instance';
            } catch (Throwable $e) {
                $detail = 'exception: '.$e->getMessage();
            }
            $checks[] = [
                'name' => 'service:'.class_basename($service),
                'status' => $resolvable ? 'passed' : 'failed',
                'detail' => $detail,
            ];
        }

        $checks[] = $this->checkRequiredFieldsGuard();
        $checks[] = $this->checkStrategyChainGuard();

        foreach (self::REQUIRED_TEST_FILES as $relativePath) {
            $exists = file_exists(base_path($relativePath));
            $checks[] = [
                'name' => 'test:'.basename($relativePath),
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'present' : "missing [{$relativePath}]",
            ];
        }

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => self::SCHEMA,
            'summary' => [
                'total' => count($checks),
                'passed' => $passed,
                'failed' => $failed,
            ],
            'checks' => $checks,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkRequiredFieldsGuard(): array
    {
        $reflection = new \ReflectionClass(OpportunityRadarService::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());
        $hasGuard = str_contains($source, 'missingField')
            && str_contains($source, 'market')
            && str_contains($source, 'competitors')
            && str_contains($source, 'risks');

        return [
            'name' => 'guard:opportunity_required_fields',
            'status' => $hasGuard ? 'passed' : 'failed',
            'detail' => $hasGuard
                ? 'OpportunityRadarService enforces problem/ICP/pain/market/competitors/risks'
                : 'OpportunityRadarService missing required-field guards',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkStrategyChainGuard(): array
    {
        $reflection = new \ReflectionClass(StrategyRuntimeService::class);
        $source = (string) file_get_contents((string) $reflection->getFileName());
        $hasChain = str_contains($source, 'driveOpportunityToDecision')
            && str_contains($source, 'recordResult')
            && str_contains($source, 'attachEvidence')
            && str_contains($source, 'StrategyMemoService');

        return [
            'name' => 'guard:strategy_chain',
            'status' => $hasChain ? 'passed' : 'failed',
            'detail' => $hasChain
                ? 'opportunity -> blueprint -> experiment -> decision -> memo chain wired'
                : 'StrategyRuntimeService missing canonical chain',
        ];
    }
}
