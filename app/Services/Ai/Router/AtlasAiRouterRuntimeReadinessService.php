<?php

namespace App\Services\Ai\Router;

use App\Http\Controllers\AiInteractionController;
use App\Models\AiRouterDecision as AiRouterDecisionModel;
use App\Models\AiSpecialistFlowExecution;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiPromptBuilder;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Illuminate\Support\Facades\File;

class AtlasAiRouterRuntimeReadinessService
{
    public const SCHEMA_VERSION = 'atlas.ai.router_runtime_readiness.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(): array
    {
        $checks = [
            $this->intentKernelCheck(),
            $this->routerFlowsDeclaredCheck(),
            $this->routerDecisionBehaviorCheck(),
            $this->specialistRuntimeContractCheck(),
            $this->specialistExecutionPacketCheck(),
            $this->persistenceArtifactsCheck(),
            $this->apiFlowStatusCheck(),
            $this->apiBootstrapCheck(),
            $this->telemetryIntegrationCheck(),
            $this->promptProjectionCheck(),
            $this->documentationCheck(),
        ];

        $failed = array_values(array_filter($checks, fn (array $check): bool => ($check['status'] ?? null) !== 'passed'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $failed === [] ? 'passed' : 'blocked',
            'summary' => [
                'total' => count($checks),
                'passed' => count($checks) - count($failed),
                'failed' => count($failed),
            ],
            'checks' => $checks,
            'remaining_blockers' => array_map(
                fn (array $check): string => (string) ($check['id'] ?? 'unknown_check'),
                $failed,
            ),
            'surfaces' => [
                'readiness' => '/ai/router-runtime/readiness',
                'flow_status' => '/ai/interactions/{trace}/flow-status',
                'interaction_entrypoint' => '/ai/interactions',
            ],
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function intentKernelCheck(): array
    {
        $kernel = new AtlasAiIntentKernelService;
        $ambiguous = $kernel->classify([
            'input_text' => 'Implemente um sistema de login',
            'payload' => ['surface_id' => 'atlas_desktop_ai'],
        ]);

        return $this->check(
            'intent_kernel.ambiguous_prompt_classification',
            class_exists(AtlasAiIntentKernelService::class)
                && data_get($ambiguous, 'schema_version') === AtlasAiIntentKernelService::SCHEMA_VERSION
                && data_get($ambiguous, 'intent_class') === 'plan'
                && data_get($ambiguous, 'planning_required') === true,
            [
                'service' => AtlasAiIntentKernelService::class,
                'schema_version' => data_get($ambiguous, 'schema_version'),
                'ambiguous_prompt_intent_class' => data_get($ambiguous, 'intent_class'),
                'planning_required' => data_get($ambiguous, 'planning_required'),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function routerFlowsDeclaredCheck(): array
    {
        $required = [
            AtlasAiRouterDecision::FLOW_DEV,
            AtlasAiRouterDecision::FLOW_RESEARCH,
            AtlasAiRouterDecision::FLOW_EXPLAIN,
            AtlasAiRouterDecision::FLOW_DEBUG,
            AtlasAiRouterDecision::FLOW_REVIEW,
            AtlasAiRouterDecision::FLOW_PLAN,
            AtlasAiRouterDecision::FLOW_CONVERSATION,
            AtlasAiRouterDecision::FLOW_FORGE,
        ];
        $missing = array_values(array_diff($required, AtlasAiRouterDecision::FLOWS));

        return $this->check(
            'router.flows_declared',
            $missing === [] && AtlasAiRouterDecision::SCHEMA_VERSION === 'atlas.ai.router.flow_decision.v1',
            [
                'schema_version' => AtlasAiRouterDecision::SCHEMA_VERSION,
                'required_flows' => $required,
                'declared_flows' => AtlasAiRouterDecision::FLOWS,
                'missing_flows' => $missing,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function routerDecisionBehaviorCheck(): array
    {
        $router = new AtlasAiRouterService;
        $cases = [
            'patch_with_workspace' => [
                'input' => [
                    'input_text' => 'implemente endpoint',
                    'payload' => ['workspace' => '/repo'],
                ],
                'expected_flow' => AtlasAiRouterDecision::FLOW_DEV,
            ],
            'explain_without_workspace' => [
                'input' => [
                    'input_text' => 'explique o router',
                    'payload' => [],
                ],
                'expected_flow' => AtlasAiRouterDecision::FLOW_EXPLAIN,
            ],
            'atlas_code_surface' => [
                'input' => [
                    'input_text' => 'obra enterprise',
                    'payload' => ['surface_id' => 'atlas_code'],
                ],
                'expected_flow' => AtlasAiRouterDecision::FLOW_FORGE,
            ],
            'plan_like_intent' => [
                'input' => [
                    'input_text' => 'planeje a refatoracao antes de implementar',
                    'payload' => ['surface_id' => 'atlas_desktop_ai'],
                ],
                'expected_flow' => AtlasAiRouterDecision::FLOW_PLAN,
            ],
        ];

        $results = [];
        foreach ($cases as $id => $case) {
            $decision = $router->decide($case['input']);
            $results[$id] = [
                'expected_flow' => $case['expected_flow'],
                'actual_flow' => $decision->flowId,
                'passed' => $decision->flowId === $case['expected_flow'],
            ];
        }

        return $this->check(
            'router.behavior_smoke',
            ! in_array(false, array_column($results, 'passed'), true),
            ['cases' => $results],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function specialistRuntimeContractCheck(): array
    {
        $service = new AtlasAiSpecialistFlowRuntimeService;
        $input = [
            'input_text' => 'explique',
            'payload' => [
                'atlas_ai_router' => [
                    'flow_id' => AtlasAiRouterDecision::FLOW_EXPLAIN,
                    'flow_origin' => 'router_auto',
                    'command_intent' => 'explain',
                    'routing_reason' => 'explain_like_general',
                    'handoff_payload' => ['workspace_present' => false, 'surface_id' => 'atlas_desktop_ai'],
                ],
            ],
        ];
        $first = $service->apply($input);
        $second = $service->apply($input);
        $runtime = data_get($first, 'payload.specialist_flow_runtime');
        $receipt = is_array($runtime) ? data_get($runtime, 'receipt') : null;

        return $this->check(
            'specialist.runtime_contract',
            is_array($runtime)
                && data_get($runtime, 'schema_version') === AtlasAiSpecialistFlowRuntimeService::SCHEMA_VERSION
                && data_get($receipt, 'schema_version') === AtlasAiSpecialistFlowRuntimeService::RECEIPT_SCHEMA_VERSION
                && data_get($receipt, 'contract_hash') === data_get($second, 'payload.specialist_flow_runtime.receipt.contract_hash'),
            [
                'runtime_schema_version' => data_get($runtime, 'schema_version'),
                'receipt_schema_version' => data_get($receipt, 'schema_version'),
                'receipt_id_present' => is_string(data_get($receipt, 'receipt_id')),
                'contract_hash_deterministic' => data_get($receipt, 'contract_hash') === data_get($second, 'payload.specialist_flow_runtime.receipt.contract_hash'),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function specialistExecutionPacketCheck(): array
    {
        $runtimeService = new AtlasAiSpecialistFlowRuntimeService;
        $executionService = new AtlasAiSpecialistFlowExecutionService;
        $payload = $runtimeService->apply([
            'input_text' => 'explique o flow status',
            'payload' => [
                'atlas_ai_router' => [
                    'flow_id' => AtlasAiRouterDecision::FLOW_EXPLAIN,
                    'flow_origin' => 'router_auto',
                    'command_intent' => 'explain',
                    'routing_reason' => 'explain_like_general',
                    'handoff_payload' => ['workspace_present' => false],
                ],
            ],
        ]);
        $payload = $executionService->apply($payload);
        $execution = data_get($payload, 'payload.specialist_flow_execution');

        return $this->check(
            'specialist.execution_packet',
            is_array($execution)
                && data_get($execution, 'schema_version') === AtlasAiSpecialistFlowExecutionService::SCHEMA_VERSION
                && data_get($execution, 'handler_id') === 'atlas_explain_read_only_handler'
                && is_array(data_get($execution, 'audit_checks'))
                && is_array(data_get($execution, 'quality_rubric'))
                && is_array(data_get($execution, 'completion_checks'))
                && is_array(data_get($execution, 'failure_modes')),
            [
                'execution_schema_version' => data_get($execution, 'schema_version'),
                'handler_id' => data_get($execution, 'handler_id'),
                'status' => data_get($execution, 'status'),
                'audit_checks' => data_get($execution, 'audit_checks'),
                'quality_rubric' => data_get($execution, 'quality_rubric'),
                'completion_checks' => data_get($execution, 'completion_checks'),
                'failure_modes' => data_get($execution, 'failure_modes'),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function persistenceArtifactsCheck(): array
    {
        $files = [
            'router_flow_migration' => database_path('migrations/2026_05_17_131700_add_flow_columns_to_ai_router_decisions_table.php'),
            'specialist_execution_migration' => database_path('migrations/2026_05_17_132100_create_ai_specialist_flow_executions_table.php'),
        ];
        $missingFiles = array_keys(array_filter($files, fn (string $path): bool => ! is_file($path)));

        $gatewaySource = $this->source(app_path('Services/Ai/AiGatewayService.php'));

        return $this->check(
            'persistence.artifacts',
            $missingFiles === []
                && class_exists(AiRouterDecisionModel::class)
                && class_exists(AiSpecialistFlowExecution::class)
                && method_exists(AiTrace::class, 'routerDecision')
                && method_exists(AiTrace::class, 'specialistFlowExecution')
                && str_contains($gatewaySource, 'recordSpecialistFlowExecution'),
            [
                'missing_files' => $missingFiles,
                'models' => [
                    AiRouterDecisionModel::class => class_exists(AiRouterDecisionModel::class),
                    AiSpecialistFlowExecution::class => class_exists(AiSpecialistFlowExecution::class),
                ],
                'gateway_records_specialist_flow_execution' => str_contains($gatewaySource, 'recordSpecialistFlowExecution'),
                'gateway_class' => AiGatewayService::class,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function apiFlowStatusCheck(): array
    {
        $routesSource = $this->source(base_path('routes/api.php'));

        return $this->check(
            'api.flow_status_read_model',
            class_exists(AtlasAiFlowStatusReadModel::class)
                && method_exists(AiInteractionController::class, 'flowStatus')
                && str_contains($routesSource, '/ai/interactions/{trace}/flow-status'),
            [
                'read_model' => AtlasAiFlowStatusReadModel::class,
                'controller_method_present' => method_exists(AiInteractionController::class, 'flowStatus'),
                'route_present' => str_contains($routesSource, '/ai/interactions/{trace}/flow-status'),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function apiBootstrapCheck(): array
    {
        $routesSource = $this->source(base_path('routes/api.php'));

        return $this->check(
            'api.router_runtime_bootstrap',
            class_exists(AtlasAiRouterRuntimeBootstrapService::class)
                && str_contains($routesSource, '/ai/router-runtime/bootstrap'),
            [
                'bootstrap_service' => AtlasAiRouterRuntimeBootstrapService::class,
                'schema_version' => class_exists(AtlasAiRouterRuntimeBootstrapService::class)
                    ? AtlasAiRouterRuntimeBootstrapService::SCHEMA_VERSION
                    : null,
                'route_present' => str_contains($routesSource, '/ai/router-runtime/bootstrap'),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function telemetryIntegrationCheck(): array
    {
        $source = $this->source(app_path('Services/Ai/Telemetry/AiTraceMetricAggregator.php'));

        return $this->check(
            'telemetry.specialist_flow_score_components',
            class_exists(AiTraceMetricAggregator::class)
                && str_contains($source, 'specialistFlowDiagnostics')
                && str_contains($source, 'ai_specialist_flow_executions'),
            [
                'aggregator' => AiTraceMetricAggregator::class,
                'specialist_flow_diagnostics_present' => str_contains($source, 'specialistFlowDiagnostics'),
                'prefers_persisted_execution_record' => str_contains($source, 'ai_specialist_flow_executions'),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function promptProjectionCheck(): array
    {
        $source = $this->source(app_path('Services/Ai/AiPromptBuilder.php'));

        return $this->check(
            'prompt.specialist_flow_projection',
            class_exists(AiPromptBuilder::class)
                && str_contains($source, 'specialist_flow_execution')
                && str_contains($source, 'provider_prompt_contract'),
            [
                'prompt_builder' => AiPromptBuilder::class,
                'projects_execution_packet' => str_contains($source, 'specialist_flow_execution'),
                'projects_provider_prompt_contract' => str_contains($source, 'provider_prompt_contract'),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function documentationCheck(): array
    {
        $path = base_path('docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md');
        $source = $this->source($path);

        return $this->check(
            'docs.canonical_router_runtime_upgrade',
            is_file($path)
                && str_contains($source, 'atlas.ai.router_runtime_readiness.v1')
                && str_contains($source, 'atlas.ai.router_runtime_bootstrap.v1')
                && str_contains($source, 'GET /ai/interactions/{trace}/flow-status'),
            [
                'doc' => 'docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md',
                'doc_present' => is_file($path),
                'mentions_readiness_schema' => str_contains($source, 'atlas.ai.router_runtime_readiness.v1'),
                'mentions_bootstrap_schema' => str_contains($source, 'atlas.ai.router_runtime_bootstrap.v1'),
                'mentions_flow_status_endpoint' => str_contains($source, 'GET /ai/interactions/{trace}/flow-status'),
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, bool $passed, array $evidence): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'failed',
            'evidence' => $evidence,
        ];
    }

    private function source(string $path): string
    {
        return is_file($path) ? File::get($path) : '';
    }
}
