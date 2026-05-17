<?php

namespace Tests\Unit\Ai\Router;

use App\Services\Ai\Router\AtlasAiSpecialistFlowExecutionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AtlasAiSpecialistFlowExecutionServiceTest extends TestCase
{
    #[DataProvider('specialistFlowExecutionProvider')]
    public function test_builds_distinct_execution_contract_for_each_specialist_flow(
        string $flowId,
        string $handlerId,
        string $responseShape,
        string $auditCheck,
        string $qualityRubric,
        string $completionCheck,
        string $failureMode,
    ): void {
        $data = $this->service()->apply([
            'input_text' => 'Hyperflow specialist flow contract',
            'payload' => [
                'specialist_flow_runtime' => [
                    'schema_version' => 'atlas.ai.specialist_flow_runtime.v1',
                    'flow_id' => $flowId,
                    'delegation' => ['status' => 'not_delegated'],
                    'receipt' => [
                        'receipt_id' => 'sfr_'.$flowId,
                        'contract_hash' => str_repeat('a', 64),
                    ],
                ],
            ],
        ]);

        $execution = data_get($data, 'payload.specialist_flow_execution');

        $this->assertSame('atlas.ai.specialist_flow_execution.v1', $execution['schema_version']);
        $this->assertSame('ready_for_provider', $execution['status']);
        $this->assertSame($flowId, $execution['flow_id']);
        $this->assertSame($handlerId, $execution['handler_id']);
        $this->assertContains($responseShape, $execution['response_shape']);
        $this->assertContains($auditCheck, $execution['audit_checks']);
        $this->assertContains($qualityRubric, $execution['quality_rubric']);
        $this->assertContains($completionCheck, $execution['completion_checks']);
        $this->assertContains($failureMode, $execution['failure_modes']);
    }

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
        $this->assertContains('scope_boundaries_are_clear', $execution['quality_rubric']);
        $this->assertContains('no_workspace_action_claimed', $execution['completion_checks']);
        $this->assertContains('claiming_files_changed', $execution['failure_modes']);
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
        $this->assertContains('target_flow_is_justified', $execution['quality_rubric']);
        $this->assertContains('operator_next_step_present', $execution['completion_checks']);
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
        $this->assertContains('plan_is_executable', $execution['quality_rubric']);
        $this->assertContains('milestones_have_validation_evidence', $execution['completion_checks']);
        $this->assertContains('planning_as_completed_work', $execution['failure_modes']);
    }

    private function service(): AtlasAiSpecialistFlowExecutionService
    {
        return new AtlasAiSpecialistFlowExecutionService;
    }

    /**
     * @return array<string,array{0:string,1:string,2:string,3:string,4:string,5:string,6:string}>
     */
    public static function specialistFlowExecutionProvider(): array
    {
        return [
            'research' => ['atlas_research', 'atlas_research_grounded_answer_handler', 'claims_table', 'source_refs_or_uncertainty_present', 'claims_are_traceable', 'material_claims_have_source_or_uncertainty', 'fake_citation'],
            'debug' => ['atlas_debug', 'atlas_debug_triage_handler', 'likely_causes', 'no_invented_logs', 'diagnostics_are_reproducible', 'no_fix_claim_without_execution', 'fix_claim_without_test'],
            'review' => ['atlas_review', 'atlas_review_findings_first_handler', 'findings', 'findings_first', 'severity_is_defensible', 'findings_precede_summary', 'summary_before_findings'],
            'explain' => ['atlas_explain', 'atlas_explain_read_only_handler', 'plain_language_explanation', 'no_side_effect_claims', 'scope_boundaries_are_clear', 'no_workspace_action_claimed', 'claiming_files_changed'],
            'conversation' => ['atlas_conversation', 'atlas_conversation_direct_handler', 'direct_answer', 'handoff_when_scope_changes', 'handoff_boundary_is_visible', 'scope_change_gets_handoff_suggestion', 'pretending_workspace_access'],
            'plan' => ['atlas_plan', 'atlas_plan_engineering_plan_handler', 'risk_register', 'execution_flow_recommended', 'plan_is_executable', 'milestones_have_validation_evidence', 'planning_as_completed_work'],
        ];
    }
}
