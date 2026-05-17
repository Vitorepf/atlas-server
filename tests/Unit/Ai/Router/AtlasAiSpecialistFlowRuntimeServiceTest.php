<?php

namespace Tests\Unit\Ai\Router;

use App\Services\Ai\Router\AtlasAiSpecialistFlowRuntimeService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AtlasAiSpecialistFlowRuntimeServiceTest extends TestCase
{
    #[DataProvider('specialistFlowRuntimeProvider')]
    public function test_emits_distinct_runtime_contract_for_each_specialist_flow(
        string $flowId,
        string $executionMode,
        string $outputContract,
        string $requiredEvidence,
        string $forbiddenAction,
    ): void {
        $data = $this->service()->apply([
            'input_text' => 'Hyperflow specialist runtime',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_ai_router' => [
                    'flow_id' => $flowId,
                    'flow_origin' => 'router_auto',
                    'command_intent' => str_replace('atlas_', '', $flowId),
                    'routing_reason' => 'hyperflow_test',
                    'handoff_payload' => [
                        'surface_id' => 'atlas_desktop_ai',
                        'workspace_present' => false,
                    ],
                ],
            ],
        ]);

        $runtime = data_get($data, 'payload.specialist_flow_runtime');

        $this->assertSame('atlas.ai.specialist_flow_runtime.v1', $runtime['schema_version']);
        $this->assertSame($flowId, $runtime['flow_id']);
        $this->assertSame($executionMode, $runtime['execution_mode']);
        $this->assertContains($outputContract, $runtime['output_contract']);
        $this->assertContains($requiredEvidence, $runtime['required_evidence']);
        $this->assertContains($forbiddenAction, $runtime['forbidden_actions']);
        $this->assertSame('atlas.ai.specialist_flow_receipt.v1', data_get($runtime, 'receipt.schema_version'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($runtime, 'receipt.contract_hash'));
    }

    public function test_emits_auditable_research_runtime_contract(): void
    {
        $data = $this->service()->apply([
            'input_text' => 'pesquise alternativas',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_ai_router' => [
                    'flow_id' => 'atlas_research',
                    'flow_origin' => 'router_auto',
                    'command_intent' => 'research',
                    'routing_reason' => 'research_like_request',
                    'handoff_payload' => [
                        'surface_id' => 'atlas_desktop_ai',
                        'workspace_present' => false,
                    ],
                ],
            ],
        ]);

        $runtime = data_get($data, 'payload.specialist_flow_runtime');

        $this->assertSame('atlas.ai.specialist_flow_runtime.v1', $runtime['schema_version']);
        $this->assertSame('atlas_research', $runtime['flow_id']);
        $this->assertSame('source_grounded_answer', $runtime['execution_mode']);
        $this->assertSame(['answer_summary', 'claims_table', 'source_refs', 'uncertainty', 'open_questions'], $runtime['output_contract']);
        $this->assertSame('not_delegated', data_get($runtime, 'delegation.status'));
        $this->assertSame('atlas.ai.specialist_flow_receipt.v1', data_get($runtime, 'receipt.schema_version'));
        $this->assertStringStartsWith('sfr_', data_get($runtime, 'receipt.receipt_id'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($runtime, 'receipt.contract_hash'));
        $this->assertSame('atlas_research', data_get($runtime, 'receipt.flow_id'));
        $this->assertSame('not_delegated', data_get($runtime, 'receipt.delegation_status'));
    }

    public function test_receipt_is_deterministic_for_same_contract(): void
    {
        $payload = [
            'input_text' => 'pesquise alternativas',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_ai_router' => [
                    'flow_id' => 'atlas_research',
                    'flow_origin' => 'router_auto',
                    'command_intent' => 'research',
                    'routing_reason' => 'research_like_request',
                    'handoff_payload' => [
                        'surface_id' => 'atlas_desktop_ai',
                        'workspace_present' => false,
                    ],
                ],
            ],
        ];

        $first = $this->service()->apply($payload);
        $second = $this->service()->apply($payload);

        $this->assertSame(
            data_get($first, 'payload.specialist_flow_runtime.receipt.contract_hash'),
            data_get($second, 'payload.specialist_flow_runtime.receipt.contract_hash'),
        );
        $this->assertSame(
            data_get($first, 'payload.specialist_flow_runtime.receipt.receipt_id'),
            data_get($second, 'payload.specialist_flow_runtime.receipt.receipt_id'),
        );
    }

    public function test_skips_dev_when_atlas_dev_runtime_already_owns_execution(): void
    {
        $data = $this->service()->apply([
            'payload' => [
                'atlas_dev_runtime' => ['schema_version' => 'atlas.dev_runtime.v1'],
                'atlas_ai_router' => [
                    'flow_id' => 'atlas_debug',
                    'handoff_payload' => ['workspace_present' => true],
                ],
            ],
        ]);

        $this->assertNull(data_get($data, 'payload.specialist_flow_runtime'));
    }

    public function test_emits_auditable_plan_runtime_contract(): void
    {
        $data = $this->service()->apply([
            'input_text' => 'Planeje a refatoracao',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'atlas_ai_router' => [
                    'flow_id' => 'atlas_plan',
                    'flow_origin' => 'router_auto',
                    'command_intent' => 'plan',
                    'routing_reason' => 'plan_like_intent',
                    'handoff_payload' => [
                        'surface_id' => 'atlas_desktop_ai',
                        'workspace_present' => true,
                    ],
                ],
            ],
        ]);

        $runtime = data_get($data, 'payload.specialist_flow_runtime');

        $this->assertSame('atlas_plan', $runtime['flow_id']);
        $this->assertSame('engineering_plan', $runtime['execution_mode']);
        $this->assertContains('risk_assessment', $runtime['required_evidence']);
        $this->assertContains('execution_recommendation', $runtime['output_contract']);
        $this->assertContains('claim_implementation_completed', $runtime['forbidden_actions']);
    }

    private function service(): AtlasAiSpecialistFlowRuntimeService
    {
        return new AtlasAiSpecialistFlowRuntimeService;
    }

    /**
     * @return array<string,array{0:string,1:string,2:string,3:string,4:string}>
     */
    public static function specialistFlowRuntimeProvider(): array
    {
        return [
            'research' => ['atlas_research', 'source_grounded_answer', 'claims_table', 'source_refs_or_uncertainty_statement', 'hide_uncertainty'],
            'debug' => ['atlas_debug', 'diagnostic_triage', 'next_debug_steps', 'logs_or_missing_logs_statement', 'invent_log_lines'],
            'review' => ['atlas_review', 'diff_or_artifact_review', 'findings_first', 'diff_or_review_scope', 'bury_findings_after_summary'],
            'explain' => ['atlas_explain', 'read_only_explanation', 'plain_language_explanation', 'context_refs_or_scope_statement', 'claim_code_was_changed'],
            'conversation' => ['atlas_conversation', 'conversation', 'direct_answer', 'router_decision', 'pretend_workspace_access'],
            'plan' => ['atlas_plan', 'engineering_plan', 'risk_register', 'risk_assessment', 'claim_implementation_completed'],
        ];
    }
}
