<?php

namespace App\Services\Ai\Router;

class AtlasAiRouterRuntimeBootstrapService
{
    public const SCHEMA_VERSION = 'atlas.ai.router_runtime_bootstrap.v1';

    public function __construct(
        private readonly AtlasAiRouterRuntimeReadinessService $readiness,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function bootstrap(): array
    {
        $readiness = $this->readiness->inspect();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => ($readiness['status'] ?? null) === 'passed' ? 'ready' : 'blocked',
            'readiness' => [
                'schema_version' => $readiness['schema_version'] ?? null,
                'status' => $readiness['status'] ?? 'unknown',
                'summary' => $readiness['summary'] ?? [],
                'endpoint' => '/ai/router-runtime/readiness',
            ],
            'entrypoints' => [
                'create_interaction' => [
                    'method' => 'POST',
                    'path' => '/ai/interactions',
                    'returns' => 'AiTraceResource',
                ],
                'flow_status' => [
                    'method' => 'GET',
                    'path' => '/ai/interactions/{trace}/flow-status',
                    'returns' => 'atlas.ai.flow_status.v1',
                ],
                'readiness' => [
                    'method' => 'GET',
                    'path' => '/ai/router-runtime/readiness',
                    'returns' => AtlasAiRouterRuntimeReadinessService::SCHEMA_VERSION,
                ],
                'bootstrap' => [
                    'method' => 'GET',
                    'path' => '/ai/router-runtime/bootstrap',
                    'returns' => self::SCHEMA_VERSION,
                ],
            ],
            'flows' => $this->flows(),
            'slash_commands' => $this->slashCommands(),
            'status_states' => [
                'missing_router_decision' => ['severity' => 'blocked', 'next_action' => 'inspect_router_input'],
                'routed' => ['severity' => 'info', 'next_action' => 'wait_runtime_contract'],
                'runtime_contract_ready' => ['severity' => 'info', 'next_action' => 'wait_execution_packet'],
                'ready_for_provider' => ['severity' => 'active', 'next_action' => 'wait_provider_response'],
                'delegated' => ['severity' => 'active', 'next_action' => 'follow_delegation_target'],
                'audit_recorded' => ['severity' => 'ok', 'next_action' => 'render_audit_receipt'],
                'atlas_dev_runtime' => ['severity' => 'active', 'next_action' => 'render_atlas_dev_controls'],
                'forge_required' => ['severity' => 'active', 'next_action' => 'open_forge_surface'],
            ],
            'payload_contract' => [
                'router_slice' => 'payload.atlas_ai_router',
                'specialist_runtime_slice' => 'payload.specialist_flow_runtime',
                'specialist_execution_slice' => 'payload.specialist_flow_execution',
                'atlas_dev_runtime_slice' => 'payload.atlas_dev_runtime',
                'required_client_fields' => ['input_text'],
                'recommended_client_fields' => ['payload.surface_id', 'payload.workspace', 'payload.slash_command'],
            ],
            'ux_contract' => [
                'primary_status_source' => '/ai/interactions/{trace}/flow-status',
                'show_receipt_when' => 'flow_status.ui.is_auditable=true',
                'show_forge_button_when' => 'flow_status.ui.next_action=open_forge_surface',
                'show_dev_controls_when' => 'flow_status.ui.next_action=render_atlas_dev_controls',
                'show_provider_waiting_when' => 'flow_status.ui.next_action=wait_provider_response',
            ],
            'integration_boundaries' => [
                'atlas_ai' => 'conversation_intention_router_and_light_specialist_flows',
                'atlas_dev' => 'workspace_bound_light_mid_programming_runtime',
                'atlas_forge' => 'obra_heavy_long_running_enterprise_runtime',
                'merge_dev_and_forge' => false,
            ],
            'writes' => false,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function flows(): array
    {
        return [
            [
                'id' => AtlasAiRouterDecision::FLOW_DEV,
                'label' => 'Atlas Dev',
                'owner' => 'atlas_dev',
                'execution_surface' => 'atlas_ai_to_atlas_dev_bridge',
                'side_effect_policy' => 'workspace_changes_require_dev_runtime_confirmation',
                'default_next_action' => 'render_atlas_dev_controls',
                'auditable_receipt' => 'atlas_dev_runtime',
            ],
            [
                'id' => AtlasAiRouterDecision::FLOW_RESEARCH,
                'label' => 'Research',
                'owner' => 'atlas_ai_specialist_flow',
                'execution_surface' => 'provider_with_source_grounded_contract',
                'side_effect_policy' => 'read_only',
                'default_next_action' => 'wait_provider_response',
                'auditable_receipt' => AtlasAiSpecialistFlowRuntimeService::RECEIPT_SCHEMA_VERSION,
            ],
            [
                'id' => AtlasAiRouterDecision::FLOW_EXPLAIN,
                'label' => 'Explain',
                'owner' => 'atlas_ai_specialist_flow',
                'execution_surface' => 'provider_with_read_only_contract',
                'side_effect_policy' => 'read_only',
                'default_next_action' => 'wait_provider_response',
                'auditable_receipt' => AtlasAiSpecialistFlowRuntimeService::RECEIPT_SCHEMA_VERSION,
            ],
            [
                'id' => AtlasAiRouterDecision::FLOW_DEBUG,
                'label' => 'Debug',
                'owner' => 'atlas_ai_specialist_flow_or_atlas_dev',
                'execution_surface' => 'triage_without_workspace_or_delegate_to_dev',
                'side_effect_policy' => 'read_only_until_dev_runtime',
                'default_next_action' => 'wait_provider_response_or_render_atlas_dev_controls',
                'auditable_receipt' => AtlasAiSpecialistFlowRuntimeService::RECEIPT_SCHEMA_VERSION,
            ],
            [
                'id' => AtlasAiRouterDecision::FLOW_REVIEW,
                'label' => 'Review',
                'owner' => 'atlas_ai_specialist_flow_or_atlas_dev',
                'execution_surface' => 'findings_first_without_workspace_or_delegate_to_dev',
                'side_effect_policy' => 'read_only_until_dev_runtime',
                'default_next_action' => 'wait_provider_response_or_render_atlas_dev_controls',
                'auditable_receipt' => AtlasAiSpecialistFlowRuntimeService::RECEIPT_SCHEMA_VERSION,
            ],
            [
                'id' => AtlasAiRouterDecision::FLOW_CONVERSATION,
                'label' => 'Conversation',
                'owner' => 'atlas_ai_specialist_flow',
                'execution_surface' => 'direct_provider_answer',
                'side_effect_policy' => 'read_only',
                'default_next_action' => 'wait_provider_response',
                'auditable_receipt' => AtlasAiSpecialistFlowRuntimeService::RECEIPT_SCHEMA_VERSION,
            ],
            [
                'id' => AtlasAiRouterDecision::FLOW_FORGE,
                'label' => 'Atlas Forge',
                'owner' => 'atlas_forge',
                'execution_surface' => 'atlas_code_forge_surface',
                'side_effect_policy' => 'forge_obra_governance',
                'default_next_action' => 'open_forge_surface',
                'auditable_receipt' => 'forge_runtime_receipt',
            ],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function slashCommands(): array
    {
        return [
            ['command' => '/dev', 'flow_id' => AtlasAiRouterDecision::FLOW_DEV],
            ['command' => '/research', 'flow_id' => AtlasAiRouterDecision::FLOW_RESEARCH],
            ['command' => '/explain', 'flow_id' => AtlasAiRouterDecision::FLOW_EXPLAIN],
            ['command' => '/debug', 'flow_id' => AtlasAiRouterDecision::FLOW_DEBUG],
            ['command' => '/review', 'flow_id' => AtlasAiRouterDecision::FLOW_REVIEW],
            ['command' => '/forge', 'flow_id' => AtlasAiRouterDecision::FLOW_FORGE],
            ['command' => '/chat', 'flow_id' => AtlasAiRouterDecision::FLOW_CONVERSATION],
        ];
    }
}
