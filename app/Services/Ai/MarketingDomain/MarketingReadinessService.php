<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingApprovalGate;
use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingExperiment;
use App\Models\AiMarketingRun;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Contracts\Container\Container;

class MarketingReadinessService
{
    use \App\Services\Ai\Support\BuildsReadinessChecks;

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

        $checks = array_merge($checks, $this->tableChecks(self::REQUIRED_TABLES));

        $checks = array_merge($checks, $this->modelChecks(self::REQUIRED_MODELS));

        $checks = array_merge($checks, $this->serviceChecks($this->container, self::REQUIRED_SERVICES));

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
            'detail' => DatabaseTableAvailability::has('ai_approval_requests')
                ? 'meta3 ai_approval_requests present; approval gate will cross-link'
                : 'meta3 ai_approval_requests absent; approval gate degrades gracefully',
        ];
    }
}
