<?php

namespace Tests\Unit\Ai\Router;

use App\Services\Ai\Router\AtlasAiSpecialistFlowExecutionService;
use Tests\TestCase;

class AtlasAiSpecialistFlowExecutionServiceTest extends TestCase
{
    public function test_builds_ready_for_provider_execution_packet(): void
    {
        $data = $this->service()->apply([
            'input_text' => 'Explique o router',
            'payload' => [
                'specialist_flow_runtime' => [
                    'schema_version' => 'atlas.ai.specialist_flow_runtime.v1',
                    'flow_id' => 'atlas_explain',
                    'execution_mode' => 'read_only_explanation',
                    'delegation' => ['status' => 'not_delegated'],
                    'receipt' => [
                        'receipt_id' => 'sfr_123',
                        'contract_hash' => str_repeat('c', 64),
                    ],
                ],
            ],
        ]);

        $execution = data_get($data, 'payload.specialist_flow_execution');

        $this->assertSame('atlas.ai.specialist_flow_execution.v1', $execution['schema_version']);
        $this->assertSame('ready_for_provider', $execution['status']);
        $this->assertSame('atlas_explain_read_only_handler', $execution['handler_id']);
        $this->assertSame('sfr_123', $execution['runtime_receipt_id']);
        $this->assertContains('no_side_effect_claims', $execution['audit_checks']);
    }

    public function test_builds_delegation_execution_packet_without_executing_wrong_flow(): void
    {
        $data = $this->service()->apply([
            'input_text' => 'Debug no workspace',
            'payload' => [
                'specialist_flow_runtime' => [
                    'schema_version' => 'atlas.ai.specialist_flow_runtime.v1',
                    'flow_id' => 'atlas_debug',
                    'delegation' => [
                        'status' => 'delegate_to_other_flow',
                        'target_flow_id' => 'atlas_dev',
                        'reason' => 'workspace_debug_belongs_to_atlas_dev_repair',
                    ],
                    'receipt' => [
                        'receipt_id' => 'sfr_456',
                        'contract_hash' => str_repeat('d', 64),
                    ],
                ],
            ],
        ]);

        $execution = data_get($data, 'payload.specialist_flow_execution');

        $this->assertSame('delegated', $execution['status']);
        $this->assertSame('atlas_specialist_delegation_handler', $execution['handler_id']);
        $this->assertSame('atlas_dev', data_get($execution, 'delegation.target_flow_id'));
        $this->assertContains('no_work_executed_in_wrong_flow', $execution['audit_checks']);
    }

    public function test_builds_plan_execution_packet(): void
    {
        $data = $this->service()->apply([
            'input_text' => 'Planeje o fluxo',
            'payload' => [
                'specialist_flow_runtime' => [
                    'schema_version' => 'atlas.ai.specialist_flow_runtime.v1',
                    'flow_id' => 'atlas_plan',
                    'execution_mode' => 'engineering_plan',
                    'delegation' => ['status' => 'not_delegated'],
                    'receipt' => [
                        'receipt_id' => 'sfr_789',
                        'contract_hash' => str_repeat('e', 64),
                    ],
                ],
            ],
        ]);

        $execution = data_get($data, 'payload.specialist_flow_execution');

        $this->assertSame('ready_for_provider', $execution['status']);
        $this->assertSame('atlas_plan_engineering_plan_handler', $execution['handler_id']);
        $this->assertContains('execution_flow_recommended', $execution['audit_checks']);
        $this->assertContains('execution_recommendation', $execution['response_shape']);
    }

    private function service(): AtlasAiSpecialistFlowExecutionService
    {
        return new AtlasAiSpecialistFlowExecutionService;
    }
}
