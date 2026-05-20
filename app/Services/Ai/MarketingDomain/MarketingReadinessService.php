<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingApprovalGate;
use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingExperiment;
use App\Models\AiMarketingRun;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;

class MarketingReadinessService
{
    private const REQUIRED_TABLES = [
        'ai_marketing_runs',
        'ai_marketing_artifacts',
        'ai_marketing_experiments',
        'ai_marketing_approval_gates',
    ];

    private const REQUIRED_MODELS = [
        AiMarketingRun::class,
        AiMarketingArtifact::class,
        AiMarketingExperiment::class,
        AiMarketingApprovalGate::class,
    ];

    private const REQUIRED_SERVICES = [
        MarketingDomainManifestSeeder::class,
        MarketingRuntimeService::class,
        ICPPositioningService::class,
        CampaignPlanService::class,
        CopyBriefService::class,
        CreativeBriefService::class,
        FunnelPlanService::class,
        MarketingAnalyticsPlanService::class,
        GrowthExperimentPlanService::class,
        MarketingApprovalGateService::class,
        MarketingLimitedAutonomyPolicyService::class,
        MarketingControlPlaneProjection::class,
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
            } catch (\Throwable $e) {
                $detail = 'exception: '.$e->getMessage();
            }
            $checks[] = [
                'name' => 'service:'.class_basename($service),
                'status' => $resolvable ? 'passed' : 'failed',
                'detail' => $detail,
            ];
        }

        $checks[] = $this->checkCanon();
        $checks[] = $this->checkPolicyBridgeTolerance();

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => 'atlas.ai.marketing_domain.readiness.v1',
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
    private function checkCanon(): array
    {
        $ok = count(MarketingDomainCanon::ARTIFACT_TYPES) >= 5
            && count(MarketingDomainCanon::GATE_TYPES) === 4
            && in_array(MarketingDomainCanon::ARTIFACT_ICP, MarketingDomainCanon::REQUIRED_ARTIFACT_TYPES, true);

        return [
            'name' => 'canon:marketing_enums',
            'status' => $ok ? 'passed' : 'failed',
            'detail' => $ok ? 'canon aligned' : 'canon drifted',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkPolicyBridgeTolerance(): array
    {
        return [
            'name' => 'bridge:policy_approval_request_tolerant',
            'status' => 'passed',
            'detail' => Schema::hasTable('ai_approval_requests')
                ? 'meta3 ai_approval_requests present; approval gate will cross-link'
                : 'meta3 ai_approval_requests absent; approval gate degrades gracefully',
        ];
    }
}
