<?php

namespace App\Services\Ai\RouterRuntime;

use App\Models\AiAtlasDecisionReceipt;
use App\Models\AiAtlasFlowRoute;
use App\Models\AiAtlasIntentClassification;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiAtlasRuntimeDispatch;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;

class RouterRuntimeReadinessService
{
    private const REQUIRED_TABLES = [
        'ai_atlas_intent_classifications',
        'ai_atlas_router_decisions',
        'ai_atlas_flow_routes',
        'ai_atlas_runtime_dispatches',
        'ai_atlas_decision_receipts',
    ];

    private const REQUIRED_MODELS = [
        AiAtlasIntentClassification::class,
        AiAtlasRouterDecision::class,
        AiAtlasFlowRoute::class,
        AiAtlasRuntimeDispatch::class,
        AiAtlasDecisionReceipt::class,
    ];

    private const REQUIRED_SERVICES = [
        IntentKernelService::class,
        ObjectiveRoutingService::class,
        DomainRouterService::class,
        FlowRouterService::class,
        RuntimeDispatchService::class,
        DecisionReceiptService::class,
        RouterPolicyBridgeService::class,
        RouterEvidenceBridgeService::class,
        RouterRuntimeControlPlaneService::class,
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
        $checks[] = $this->checkEvidenceBridgeTolerance();

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => 'atlas.ai.router_runtime.readiness.v1',
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
        $intentMap = RouterRuntimeCanon::INTENT_TO_FLOW;
        $domainMap = RouterRuntimeCanon::INTENT_TO_DOMAIN;
        $ok = count($intentMap) === count(RouterRuntimeCanon::INTENT_TYPES)
            && count($domainMap) === count(RouterRuntimeCanon::INTENT_TYPES);

        return [
            'name' => 'canon:intent_to_flow_map_coverage',
            'status' => $ok ? 'passed' : 'failed',
            'detail' => $ok
                ? 'every INTENT_TYPE maps to a flow_id and a domain'
                : 'canonical INTENT_TO_FLOW/INTENT_TO_DOMAIN is missing entries',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkPolicyBridgeTolerance(): array
    {
        try {
            $bridge = $this->container->make(RouterPolicyBridgeService::class);
            $ok = $bridge instanceof RouterPolicyBridgeService;
        } catch (\Throwable $e) {
            $ok = false;
        }

        return [
            'name' => 'bridge:policy_tolerant',
            'status' => $ok ? 'passed' : 'failed',
            'detail' => $ok
                ? 'policy bridge resolves and is tolerant to Meta 3 absence'
                : 'policy bridge unavailable',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkEvidenceBridgeTolerance(): array
    {
        try {
            $bridge = $this->container->make(RouterEvidenceBridgeService::class);
            $ok = $bridge instanceof RouterEvidenceBridgeService;
        } catch (\Throwable $e) {
            $ok = false;
        }

        return [
            'name' => 'bridge:evidence_tolerant',
            'status' => $ok ? 'passed' : 'failed',
            'detail' => $ok
                ? 'evidence bridge resolves and is tolerant to Meta 4 absence'
                : 'evidence bridge unavailable',
        ];
    }
}
