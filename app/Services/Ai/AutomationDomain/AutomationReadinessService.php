<?php

namespace App\Services\Ai\AutomationDomain;

use App\Models\AiAutomationEvolutionEvent;
use App\Models\AiAutomationPlan;
use App\Models\AiAutomationRun;
use App\Models\AiAutomationToolDecision;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Contracts\Container\Container;

/**
 * Readiness gate for Atlas Automation / Tool Factory Runtime.
 *
 * Reports table/model/service availability plus tolerant bridges to Meta 2
 * (Domain Registry), Meta 3 (Policy/Safety) and Meta 4 (Evidence/Certification).
 * Bridges to Meta 5 (Tool Runtime) are also checked because tool selection
 * and evolution loop consume `ai_tool_definitions` / `ai_tool_invocations`.
 */
class AutomationReadinessService
{
    private const REQUIRED_TABLES = [
        'ai_automation_runs',
        'ai_automation_plans',
        'ai_automation_tool_decisions',
        'ai_automation_evolution_events',
    ];

    private const REQUIRED_MODELS = [
        AiAutomationRun::class,
        AiAutomationPlan::class,
        AiAutomationToolDecision::class,
        AiAutomationEvolutionEvent::class,
    ];

    private const REQUIRED_SERVICES = [
        AutomationDomainManifestSeeder::class,
        AutomationRuntimeService::class,
        AutomationPlanService::class,
        ToolSelectionWorkflowService::class,
        BrowserAutomationPlanningService::class,
        ApiAutomationPlanningService::class,
        TerminalAutomationPlanningService::class,
        ToolBuilderPlanService::class,
        ToolEvolutionLoopService::class,
        AutomationControlPlaneProjection::class,
        AutomationEvidenceBridge::class,
        AutomationPolicyBridge::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [];

        foreach (self::REQUIRED_TABLES as $table) {
            $exists = DatabaseTableAvailability::has($table);
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

        $checks[] = $this->checkCanonEnums();
        $checks[] = $this->checkDomainManifestRegistryTolerance();
        $checks[] = $this->checkPolicyLayerTolerance();
        $checks[] = $this->checkEvidenceRuntimeTolerance();
        $checks[] = $this->checkToolRuntimeTolerance();

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => 'atlas.ai.automation_domain.readiness.v1',
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
    private function checkCanonEnums(): array
    {
        $ok = count(AutomationDomainCanon::RUN_KINDS) >= 6
            && count(AutomationDomainCanon::PLAN_TYPES) === 5
            && count(AutomationDomainCanon::DECISION_KINDS) === 8
            && count(AutomationDomainCanon::EVOLUTION_EVENT_KINDS) === 6;

        return [
            'name' => 'canon:automation_enums',
            'status' => $ok ? 'passed' : 'failed',
            'detail' => $ok ? 'canon enums aligned' : 'canon enums drifted',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDomainManifestRegistryTolerance(): array
    {
        $present = DatabaseTableAvailability::has('ai_domain_manifests');

        return [
            'name' => 'bridge:domain_manifest_registry',
            'status' => 'passed',
            'detail' => $present
                ? 'domain manifest registry present; seeder will idempotently register automation manifest'
                : 'domain manifest registry absent; seeder will skip until Meta 2 ready',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkPolicyLayerTolerance(): array
    {
        $present = DatabaseTableAvailability::has('ai_safety_decisions');

        return [
            'name' => 'bridge:policy_safety_tolerant',
            'status' => 'passed',
            'detail' => $present
                ? 'policy safety decisions table present; AutomationPolicyBridge will record decisions'
                : 'policy safety decisions absent; AutomationPolicyBridge will fall back to local decision',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkEvidenceRuntimeTolerance(): array
    {
        $present = DatabaseTableAvailability::has('ai_receipts');

        return [
            'name' => 'bridge:evidence_runtime_tolerant',
            'status' => 'passed',
            'detail' => $present
                ? 'evidence receipts table present; AutomationEvidenceBridge will emit receipts'
                : 'evidence receipts absent; AutomationEvidenceBridge will skip receipt emission',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkToolRuntimeTolerance(): array
    {
        $present = DatabaseTableAvailability::all(['ai_tool_definitions', 'ai_tool_invocations']);

        return [
            'name' => 'bridge:tool_runtime_tolerant',
            'status' => 'passed',
            'detail' => $present
                ? 'tool runtime tables present; ToolSelection and ToolEvolution will read registry/invocations'
                : 'tool runtime absent; ToolSelection skips registry lookup and ToolEvolution returns empty observations',
        ];
    }
}
