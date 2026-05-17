<?php

namespace Tests\Feature\Ai;

use App\Http\Resources\AiTraceResource;
use App\Models\AiJob;
use App\Models\AiRouterDecision;
use App\Models\AiSpecialistFlowExecution;
use App\Models\AiTrace;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\AiGatewayService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiRouterRuntimeTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->bootSchema();
    }

    protected function tearDown(): void
    {
        foreach (['ai_trace_metric_summaries', 'ai_specialist_flow_executions', 'ai_jobs', 'ai_router_decisions', 'ai_traces'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_router_decision_model_persists_flow_audit_columns(): void
    {
        $trace = AiTrace::query()->create([
            'agent_slug' => 'orquestrador',
            'operator_input' => 'Implemente endpoint',
            'status' => 'queued',
            'metadata' => [],
        ]);

        $decision = AiRouterDecision::query()->create([
            'trace_id' => $trace->id,
            'schema_version' => 'atlas.ai.router.flow_decision.v1',
            'surface_id' => 'atlas_desktop_ai',
            'flow_id' => 'atlas_dev',
            'flow_origin' => 'router_auto',
            'command_intent' => 'patch',
            'routing_reason' => 'patch_like_with_workspace',
            'routing_confidence' => 'strong',
            'workspace_present' => true,
            'mode' => 'direct',
            'selected_provider' => 'claude_cli',
            'fallback_provider' => null,
            'signals' => ['decision_mode' => 'atlas_decide'],
            'handoff_payload' => ['workspace' => '/repo'],
            'alternative_flow_ids' => ['atlas_explain'],
            'reason' => 'Provider online.',
            'was_overridden' => false,
        ]);

        $this->assertSame('atlas_dev', $decision->fresh()->flow_id);
        $this->assertSame(['workspace' => '/repo'], $decision->fresh()->handoff_payload);
        $this->assertSame(['atlas_explain'], $decision->fresh()->alternative_flow_ids);
    }

    public function test_trace_resource_exposes_specialist_flow_runtime_receipt_from_loaded_job(): void
    {
        $trace = AiTrace::query()->create([
            'agent_slug' => 'orquestrador',
            'operator_input' => 'Explique o router',
            'status' => 'queued',
            'metadata' => [],
        ]);

        AiJob::query()->create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'priority' => 10,
            'agent_slug' => 'orquestrador',
            'input_text' => 'Explique o router',
            'prompt' => 'prompt',
            'context_refs' => [],
            'payload' => [
                'specialist_flow_runtime' => [
                    'schema_version' => 'atlas.ai.specialist_flow_runtime.v1',
                    'flow_id' => 'atlas_explain',
                    'execution_mode' => 'read_only_explanation',
                    'receipt' => [
                        'schema_version' => 'atlas.ai.specialist_flow_receipt.v1',
                        'receipt_id' => 'sfr_1234567890abcdef1234567890abcdef',
                        'contract_hash' => str_repeat('b', 64),
                    ],
                ],
                'specialist_flow_execution' => [
                    'schema_version' => 'atlas.ai.specialist_flow_execution.v1',
                    'status' => 'ready_for_provider',
                    'handler_id' => 'atlas_explain_read_only_handler',
                    'runtime_receipt_id' => 'sfr_1234567890abcdef1234567890abcdef',
                    'runtime_contract_hash' => str_repeat('b', 64),
                    'audit_checks' => ['no_side_effect_claims'],
                ],
            ],
            'available_at' => now(),
            'max_attempts' => 1,
            'timeout_seconds' => 300,
            'metadata' => [],
        ]);

        $resource = (new AiTraceResource($trace->fresh('job')))->resolve();

        $this->assertSame('atlas_explain', data_get($resource, 'specialist_flow_runtime.flow_id'));
        $this->assertSame('atlas.ai.specialist_flow_receipt.v1', data_get($resource, 'specialist_flow_runtime.receipt.schema_version'));
        $this->assertSame(str_repeat('b', 64), data_get($resource, 'specialist_flow_runtime.receipt.contract_hash'));
        $this->assertSame('atlas.ai.specialist_flow_execution.v1', data_get($resource, 'specialist_flow_execution.schema_version'));
        $this->assertSame('atlas_explain_read_only_handler', data_get($resource, 'specialist_flow_execution.handler_id'));
    }

    public function test_flow_status_endpoint_returns_enterprise_audit_read_model(): void
    {
        $trace = AiTrace::query()->create([
            'agent_slug' => 'orquestrador',
            'operator_input' => 'Explique o router',
            'status' => 'queued',
            'metadata' => [],
        ]);

        $routerDecision = AiRouterDecision::query()->create([
            'trace_id' => $trace->id,
            'schema_version' => 'atlas.ai.router.flow_decision.v1',
            'surface_id' => 'atlas_desktop_ai',
            'flow_id' => 'atlas_explain',
            'flow_origin' => 'router_auto',
            'command_intent' => 'explain',
            'routing_reason' => 'explain_like_general',
            'routing_confidence' => 'strong',
            'workspace_present' => false,
            'mode' => 'direct',
            'selected_provider' => 'claude_cli',
            'signals' => [],
            'reason' => 'Provider online.',
            'was_overridden' => false,
        ]);

        AiJob::query()->create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'priority' => 10,
            'agent_slug' => 'orquestrador',
            'input_text' => 'Explique o router',
            'prompt' => 'prompt',
            'context_refs' => [],
            'payload' => [
                'specialist_flow_runtime' => [
                    'schema_version' => 'atlas.ai.specialist_flow_runtime.v1',
                    'flow_id' => 'atlas_explain',
                    'required_evidence' => ['plain_language_scope'],
                    'forbidden_actions' => ['file_write'],
                    'receipt' => [
                        'receipt_id' => 'sfr_1234567890abcdef1234567890abcdef',
                        'contract_hash' => str_repeat('c', 64),
                    ],
                ],
                'specialist_flow_execution' => [
                    'schema_version' => 'atlas.ai.specialist_flow_execution.v1',
                    'status' => 'ready_for_provider',
                    'handler_id' => 'atlas_explain_read_only_handler',
                    'runtime_receipt_id' => 'sfr_1234567890abcdef1234567890abcdef',
                    'runtime_contract_hash' => str_repeat('c', 64),
                    'audit_checks' => ['no_side_effect_claims'],
                ],
            ],
            'available_at' => now(),
            'max_attempts' => 1,
            'timeout_seconds' => 300,
            'metadata' => [],
        ]);

        AiSpecialistFlowExecution::query()->create([
            'trace_id' => $trace->id,
            'router_decision_id' => $routerDecision->id,
            'runtime_schema_version' => 'atlas.ai.specialist_flow_runtime.v1',
            'execution_schema_version' => 'atlas.ai.specialist_flow_execution.v1',
            'flow_id' => 'atlas_explain',
            'handler_id' => 'atlas_explain_read_only_handler',
            'handler_version' => 'v1',
            'status' => 'ready_for_provider',
            'runtime_receipt_id' => 'sfr_1234567890abcdef1234567890abcdef',
            'runtime_contract_hash' => str_repeat('c', 64),
            'delegation_status' => 'not_delegated',
            'receipt' => ['receipt_id' => 'sfr_1234567890abcdef1234567890abcdef'],
            'delegation' => ['status' => 'not_delegated'],
            'audit_checks' => ['no_side_effect_claims'],
            'response_shape' => ['plain_language_explanation'],
            'execution_payload' => [
                'quality_rubric' => ['scope_boundaries_are_clear'],
                'completion_checks' => ['no_workspace_action_claimed'],
                'failure_modes' => ['claiming_files_changed'],
            ],
        ]);

        AiTraceMetricSummary::query()->create([
            'trace_id' => $trace->id,
            'runtime' => 'atlas_ai_router',
            'task_type' => 'atlas_explain',
            'router_mode' => 'direct',
            'router_selected_provider' => 'claude_cli',
            'router_was_overridden' => false,
            'final_quality_score' => 92,
            'final_efficiency_score' => 88,
            'context_efficiency_score' => 95,
            'score_components' => [
                'specialist_flow' => [
                    'source' => 'ai_specialist_flow_executions',
                    'flow_id' => 'atlas_explain',
                ],
            ],
            'metadata' => [],
            'computed_at' => now(),
        ]);

        $response = $this->getJson('/ai/interactions/'.$trace->id.'/flow-status', $this->headers);

        $response->assertOk()
            ->assertJsonPath('flow_status.schema_version', 'atlas.ai.flow_status.v1')
            ->assertJsonPath('flow_status.state', 'audit_recorded')
            ->assertJsonPath('flow_status.router.flow_id', 'atlas_explain')
            ->assertJsonPath('flow_status.specialist_flow.audit_record.handler_id', 'atlas_explain_read_only_handler')
            ->assertJsonPath('flow_status.audit.receipt_id', 'sfr_1234567890abcdef1234567890abcdef')
            ->assertJsonPath('flow_status.audit.contract_hash', str_repeat('c', 64))
            ->assertJsonPath('flow_status.telemetry.scores.final_quality_score', 92)
            ->assertJsonPath('flow_status.telemetry.score_components.specialist_flow.source', 'ai_specialist_flow_executions')
            ->assertJsonPath('flow_status.ui.is_auditable', true)
            ->assertJsonPath('flow_status.ui.next_action', 'wait_provider_response');
    }

    public function test_specialist_flow_execution_model_persists_audit_record(): void
    {
        $trace = AiTrace::query()->create([
            'agent_slug' => 'orquestrador',
            'operator_input' => 'Explique o router',
            'status' => 'queued',
            'metadata' => [],
        ]);

        $routerDecision = AiRouterDecision::query()->create([
            'trace_id' => $trace->id,
            'schema_version' => 'atlas.ai.router.flow_decision.v1',
            'surface_id' => 'atlas_desktop_ai',
            'flow_id' => 'atlas_explain',
            'flow_origin' => 'router_auto',
            'command_intent' => 'explain',
            'routing_reason' => 'explain_like_general',
            'routing_confidence' => 'strong',
            'workspace_present' => false,
            'mode' => 'direct',
            'selected_provider' => 'claude_cli',
            'signals' => [],
            'reason' => 'Provider online.',
            'was_overridden' => false,
        ]);

        $record = AiSpecialistFlowExecution::query()->create([
            'trace_id' => $trace->id,
            'router_decision_id' => $routerDecision->id,
            'runtime_schema_version' => 'atlas.ai.specialist_flow_runtime.v1',
            'execution_schema_version' => 'atlas.ai.specialist_flow_execution.v1',
            'flow_id' => 'atlas_explain',
            'handler_id' => 'atlas_explain_read_only_handler',
            'handler_version' => 'v1',
            'status' => 'ready_for_provider',
            'runtime_receipt_id' => 'sfr_1234567890abcdef1234567890abcdef',
            'runtime_contract_hash' => str_repeat('c', 64),
            'delegation_status' => 'not_delegated',
            'runtime_payload' => ['flow_id' => 'atlas_explain'],
            'execution_payload' => ['handler_id' => 'atlas_explain_read_only_handler'],
            'receipt' => ['receipt_id' => 'sfr_1234567890abcdef1234567890abcdef'],
            'delegation' => ['status' => 'not_delegated'],
            'audit_checks' => ['no_side_effect_claims'],
            'response_shape' => ['plain_language_explanation'],
            'execution_payload' => [
                'quality_rubric' => ['scope_boundaries_are_clear'],
                'completion_checks' => ['no_workspace_action_claimed'],
                'failure_modes' => ['claiming_files_changed'],
            ],
        ]);

        $fresh = $record->fresh();

        $this->assertSame('atlas_explain', $fresh->flow_id);
        $this->assertSame(['no_side_effect_claims'], $fresh->audit_checks);
        $this->assertSame('sfr_1234567890abcdef1234567890abcdef', data_get($fresh->receipt, 'receipt_id'));
        $this->assertSame($routerDecision->id, $fresh->routerDecision->id);
    }

    public function test_trace_resource_exposes_persisted_specialist_flow_execution_record(): void
    {
        $trace = AiTrace::query()->create([
            'agent_slug' => 'orquestrador',
            'operator_input' => 'Explique o router',
            'status' => 'queued',
            'metadata' => [],
        ]);

        AiSpecialistFlowExecution::query()->create([
            'trace_id' => $trace->id,
            'runtime_schema_version' => 'atlas.ai.specialist_flow_runtime.v1',
            'execution_schema_version' => 'atlas.ai.specialist_flow_execution.v1',
            'flow_id' => 'atlas_explain',
            'handler_id' => 'atlas_explain_read_only_handler',
            'handler_version' => 'v1',
            'status' => 'ready_for_provider',
            'runtime_receipt_id' => 'sfr_1234567890abcdef1234567890abcdef',
            'runtime_contract_hash' => str_repeat('d', 64),
            'delegation_status' => 'not_delegated',
            'receipt' => ['receipt_id' => 'sfr_1234567890abcdef1234567890abcdef'],
            'delegation' => ['status' => 'not_delegated'],
            'audit_checks' => ['no_side_effect_claims'],
            'response_shape' => ['plain_language_explanation'],
            'execution_payload' => [
                'quality_rubric' => ['scope_boundaries_are_clear'],
                'completion_checks' => ['no_workspace_action_claimed'],
                'failure_modes' => ['claiming_files_changed'],
            ],
        ]);

        $resource = (new AiTraceResource($trace->fresh('specialistFlowExecution')))->resolve();

        $this->assertSame('atlas_explain', data_get($resource, 'specialist_flow_execution_record.flow_id'));
        $this->assertSame('atlas_explain_read_only_handler', data_get($resource, 'specialist_flow_execution_record.handler_id'));
        $this->assertSame('sfr_1234567890abcdef1234567890abcdef', data_get($resource, 'specialist_flow_execution_record.runtime_receipt_id'));
        $this->assertSame(['no_side_effect_claims'], data_get($resource, 'specialist_flow_execution_record.audit_checks'));
        $this->assertSame(['scope_boundaries_are_clear'], data_get($resource, 'specialist_flow_execution_record.quality_rubric'));
        $this->assertSame(['no_workspace_action_claimed'], data_get($resource, 'specialist_flow_execution_record.completion_checks'));
        $this->assertSame(['claiming_files_changed'], data_get($resource, 'specialist_flow_execution_record.failure_modes'));
    }

    public function test_gateway_persists_specialist_flow_execution_audit_record(): void
    {
        $trace = AiTrace::query()->create([
            'agent_slug' => 'orquestrador',
            'operator_input' => 'Explique o router',
            'status' => 'queued',
            'metadata' => [],
        ]);

        $routerDecision = AiRouterDecision::query()->create([
            'trace_id' => $trace->id,
            'schema_version' => 'atlas.ai.router.flow_decision.v1',
            'surface_id' => 'atlas_desktop_ai',
            'flow_id' => 'atlas_explain',
            'flow_origin' => 'router_auto',
            'command_intent' => 'explain',
            'routing_reason' => 'explain_like_general',
            'routing_confidence' => 'strong',
            'workspace_present' => false,
            'mode' => 'direct',
            'selected_provider' => 'claude_cli',
            'signals' => [],
            'reason' => 'Provider online.',
            'was_overridden' => false,
        ]);

        $method = new \ReflectionMethod(AiGatewayService::class, 'recordSpecialistFlowExecution');
        $method->setAccessible(true);
        $record = $method->invoke(app(AiGatewayService::class), $trace, $routerDecision, [
            'payload' => [
                'specialist_flow_runtime' => [
                    'schema_version' => 'atlas.ai.specialist_flow_runtime.v1',
                    'flow_id' => 'atlas_explain',
                    'delegation' => ['status' => 'not_delegated'],
                    'receipt' => [
                        'receipt_id' => 'sfr_1234567890abcdef1234567890abcdef',
                        'contract_hash' => str_repeat('e', 64),
                    ],
                ],
                'specialist_flow_execution' => [
                    'schema_version' => 'atlas.ai.specialist_flow_execution.v1',
                    'status' => 'ready_for_provider',
                    'flow_id' => 'atlas_explain',
                    'handler_id' => 'atlas_explain_read_only_handler',
                    'handler_version' => 'v1',
                    'runtime_receipt_id' => 'sfr_1234567890abcdef1234567890abcdef',
                    'runtime_contract_hash' => str_repeat('e', 64),
                    'delegation' => ['status' => 'not_delegated'],
                    'audit_checks' => ['no_side_effect_claims'],
                    'response_shape' => ['plain_language_explanation'],
                    'quality_rubric' => ['scope_boundaries_are_clear'],
                    'completion_checks' => ['no_workspace_action_claimed'],
                    'failure_modes' => ['claiming_files_changed'],
                ],
            ],
        ]);

        $this->assertInstanceOf(AiSpecialistFlowExecution::class, $record);
        $this->assertSame($trace->id, $record->trace_id);
        $this->assertSame($routerDecision->id, $record->router_decision_id);
        $this->assertSame('atlas_explain_read_only_handler', $record->handler_id);
        $this->assertSame(str_repeat('e', 64), $record->runtime_contract_hash);
        $this->assertSame(['plain_language_explanation'], $record->response_shape);
        $this->assertSame(['scope_boundaries_are_clear'], data_get($record->execution_payload, 'quality_rubric'));
        $this->assertSame(['no_workspace_action_claimed'], data_get($record->execution_payload, 'completion_checks'));
        $this->assertSame(['claiming_files_changed'], data_get($record->execution_payload, 'failure_modes'));
    }

    private function bootSchema(): void
    {
        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id')->nullable();
            $table->string('agent_slug');
            $table->text('operator_input');
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_router_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('schema_version', 64)->nullable();
            $table->string('surface_id', 80)->nullable();
            $table->string('flow_id', 80)->nullable();
            $table->string('flow_origin', 40)->nullable();
            $table->string('command_intent', 80)->nullable();
            $table->string('routing_reason', 160)->nullable();
            $table->string('routing_confidence', 24)->nullable();
            $table->boolean('workspace_present')->default(false);
            $table->string('mode', 32)->default('direct');
            $table->string('selected_provider', 32);
            $table->string('fallback_provider', 32)->nullable();
            $table->json('signals');
            $table->json('handoff_payload')->nullable();
            $table->json('alternative_flow_ids')->nullable();
            $table->text('reason');
            $table->boolean('was_overridden')->default(false);
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('client_id')->nullable();
            $table->string('kind')->default('interaction');
            $table->string('status')->default('queued');
            $table->smallInteger('priority')->default(50);
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('input_text');
            $table->text('prompt');
            $table->json('context_refs')->default('[]');
            $table->json('payload')->default('{}');
            $table->text('result_text')->nullable();
            $table->json('result_json')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(1);
            $table->integer('timeout_seconds')->default(300);
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_specialist_flow_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('router_decision_id')->nullable()->index();
            $table->string('runtime_schema_version', 80)->nullable();
            $table->string('execution_schema_version', 80)->nullable();
            $table->string('flow_id', 80);
            $table->string('handler_id', 120)->nullable();
            $table->string('handler_version', 32)->nullable();
            $table->string('status', 40)->default('ready_for_provider');
            $table->string('runtime_receipt_id', 80)->nullable();
            $table->string('runtime_contract_hash', 64)->nullable();
            $table->string('delegation_status', 64)->nullable();
            $table->string('delegation_target_flow_id', 80)->nullable();
            $table->json('runtime_payload')->nullable();
            $table->json('execution_payload')->nullable();
            $table->json('receipt')->nullable();
            $table->json('delegation')->nullable();
            $table->json('audit_checks')->nullable();
            $table->json('response_shape')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_trace_metric_summaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('runtime')->nullable();
            $table->string('task_type')->nullable();
            $table->integer('context_efficiency_score')->nullable();
            $table->integer('final_quality_score')->nullable();
            $table->integer('final_efficiency_score')->nullable();
            $table->string('router_mode')->nullable();
            $table->string('router_selected_provider')->nullable();
            $table->boolean('router_was_overridden')->default(false);
            $table->json('score_components')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }
}
