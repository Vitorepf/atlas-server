<?php

namespace Tests\Feature;

use App\Models\AiDecision;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiPrompt;
use App\Services\Ai\AtlasDecideService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

class AiAtlasDecideContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createDecisionTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_decisions');
        Schema::dropIfExists('ai_router_decisions');
        Schema::dropIfExists('ai_traces');

        parent::tearDown();
    }

    public function test_atlas_decide_persists_auditable_decision_for_auto_research(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
        ]);

        $trace = $this->trace('Faca uma pesquisa sobre memoria de IA', 'gemini_cli', 'gemini-3.1-pro-preview');
        $this->recordDecision($trace, [
            'input_text' => 'Faca uma pesquisa sobre memoria de IA',
            'source_type' => 'app',
            'payload' => [
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
                'context_strategy_hint' => 'long_context_or_multimodal',
            ],
        ], 'gemini_cli', 'gemini-3.1-pro-preview', $this->prompt('research', 'low'));

        $this->assertDatabaseHas('ai_decisions', [
            'trace_id' => $trace->id,
            'decision_mode' => 'atlas_decide',
            'selected_provider' => 'gemini_cli',
            'operator_requested_provider' => 'auto',
            'was_overridden' => false,
        ]);

        $decision = AiDecision::query()->where('trace_id', $trace->id)->firstOrFail();
        $this->assertSame('gemini_cli', $decision->selected_provider);
        $this->assertSame('gemini_long_context_or_multimodal', $decision->context_strategy);
        $this->assertSame('single_provider_long_context', $decision->execution_strategy);
        $this->assertSame('research', data_get($decision->task_profile, 'task_type'));
        $this->assertSame('gemini_cli', data_get($decision->execution_graph, 'nodes.0.provider'));
        $this->assertSame('long_context_or_multimodal', data_get($decision->signals, 'context_strategy_hint'));
        $this->assertIsArray($decision->candidates);
        $this->assertNotEmpty($decision->candidates);
    }

    public function test_manual_override_stays_explicit_and_auditable(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_manual' => true,
        ]);

        $trace = $this->trace('Revise este plano de refatoracao', 'codex_cli', 'gpt-5.5');
        $this->recordDecision($trace, [
            'input_text' => 'Revise este plano de refatoracao',
            'source_type' => 'app',
            'provider' => 'codex_cli',
            'payload' => [
                'decision_mode' => 'manual_override',
                'operator_requested_provider' => 'codex_cli',
                'requested_provider' => 'codex_cli',
            ],
        ], 'codex_cli', 'gpt-5.5', $this->prompt('review', 'medium'));

        $this->assertDatabaseHas('ai_decisions', [
            'trace_id' => $trace->id,
            'decision_mode' => 'manual_override',
            'selected_provider' => 'codex_cli',
            'requested_provider' => 'codex_cli',
            'was_overridden' => true,
        ]);

        $decision = AiDecision::query()->where('trace_id', $trace->id)->firstOrFail();
        $this->assertSame('manual_provider_context', $decision->context_strategy);
        $this->assertSame('manual_single_provider', $decision->execution_strategy);
    }

    public function test_decision_preview_uses_codex_for_dev_when_auto_is_allowed(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/decisions/preview', [
                'input_text' => 'Corrija os testes quebrados no modulo de memoria',
                'payload' => [
                    'decision_mode' => 'atlas_decide',
                    'operator_requested_provider' => 'auto',
                    'atlas_workflow_mode' => 'dev',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('decision.decision_mode', 'atlas_decide')
            ->assertJsonPath('decision.selected_provider', 'codex_cli')
            ->assertJsonPath('decision.selected_model', fn (mixed $value): bool => is_string($value) && $value !== '')
            ->assertJsonPath('decision.context_strategy', 'repo_focused_context')
            ->assertJsonPath('decision.execution_strategy', 'single_provider_code_execution')
            ->assertJsonPath('decision.execution_graph.nodes.0.provider', 'codex_cli')
            ->assertJsonPath('decision.was_overridden', false);
    }

    public function test_decision_preview_infers_programming_from_natural_language_refactor_request(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
        ]);

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/decisions/preview', [
                'input_text' => 'Refatore o fluxo de runtime AI, revise varias partes do codigo e identifique arquivos relacionados antes de implementar',
                'payload' => [
                    'decision_mode' => 'atlas_decide',
                    'operator_requested_provider' => 'auto',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('decision.candidate_provider', 'codex_cli')
            ->assertJsonPath('decision.selected_provider', 'codex_cli')
            ->assertJsonPath('decision.task_profile.task_type', 'programming')
            ->assertJsonPath('decision.task_profile.context_pressure', 'long')
            ->assertJsonPath('decision.context_strategy', 'gemini_scout_then_executor')
            ->assertJsonPath('decision.execution_strategy', 'scout_then_execute_planned')
            ->assertJsonPath('decision.execution_graph.nodes.0.provider', 'gemini_cli')
            ->assertJsonPath('decision.execution_graph.nodes.1.provider', 'codex_cli');
    }

    public function test_persisted_decision_uses_atlas_decide_task_profile_over_stale_prompt_task_type(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
        ]);

        $trace = $this->trace(
            'Refatore o fluxo de runtime AI, revise varias partes do codigo e corrija bugs encontrados',
            'codex_cli',
            'gpt-5.5',
        );

        $this->recordDecision($trace, [
            'input_text' => 'Refatore o fluxo de runtime AI, revise varias partes do codigo e corrija bugs encontrados',
            'source_type' => 'app',
            'payload' => [
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ], 'codex_cli', 'gpt-5.5', $this->prompt('chat', 'low'));

        $decision = AiDecision::query()->where('trace_id', $trace->id)->firstOrFail();

        $this->assertSame('programming', $decision->task_type);
        $this->assertSame('high', $decision->risk_level);
        $this->assertSame('programming', data_get($decision->task_profile, 'task_type'));
        $this->assertSame('high', data_get($decision->task_profile, 'risk_level'));
        $this->assertSame('gemini_scout_then_executor', $decision->context_strategy);
    }

    public function test_persisted_research_decision_uses_research_profile_over_generic_prompt_task_type(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
        ]);

        $trace = $this->trace('Faca uma pesquisa profunda sobre memoria de IA', 'gemini_cli', 'gemini-3.1-pro-preview');

        $this->recordDecision($trace, [
            'input_text' => 'Faca uma pesquisa profunda sobre memoria de IA',
            'source_type' => 'app',
            'payload' => [
                'decision_mode' => 'atlas_decide',
                'operator_requested_provider' => 'auto',
            ],
        ], 'gemini_cli', 'gemini-3.1-pro-preview', $this->prompt('chat', 'low'));

        $decision = AiDecision::query()->where('trace_id', $trace->id)->firstOrFail();

        $this->assertSame('research', $decision->task_type);
        $this->assertSame('medium', $decision->risk_level);
        $this->assertSame('research', data_get($decision->task_profile, 'task_type'));
        $this->assertSame('source_grounding_review', data_get($decision->task_profile, 'quality_gate'));
        $this->assertSame('gemini_long_context_or_multimodal', $decision->context_strategy);
    }

    public function test_decision_preview_plans_gemini_scout_before_expensive_code_executor_for_large_dev_context(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
        ]);

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/decisions/preview', [
                'input_text' => 'Refatore o modulo inteiro de memoria depois de revisar o contexto completo',
                'payload' => [
                    'decision_mode' => 'atlas_decide',
                    'operator_requested_provider' => 'auto',
                    'atlas_workflow_mode' => 'dev',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('decision.selected_provider', 'codex_cli')
            ->assertJsonPath('decision.context_strategy', 'gemini_scout_then_executor')
            ->assertJsonPath('decision.execution_strategy', 'scout_then_execute_planned')
            ->assertJsonPath('decision.execution_graph.activation_status', 'planned_contract_only')
            ->assertJsonPath('decision.execution_graph.nodes.0.provider', 'gemini_cli')
            ->assertJsonPath('decision.execution_graph.nodes.1.provider', 'codex_cli');
    }

    public function test_decision_preview_uses_gemini_scout_for_wide_code_context_when_codex_auto_is_disabled(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => false,
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
        ]);

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/decisions/preview', [
                'input_text' => 'Refatore o fluxo de runtime AI, revise varias partes do codigo e identifique arquivos relacionados antes de implementar',
                'payload' => [
                    'decision_mode' => 'atlas_decide',
                    'operator_requested_provider' => 'auto',
                    'atlas_workflow_mode' => 'dev',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('decision.candidate_provider', 'codex_cli')
            ->assertJsonPath('decision.selected_provider', 'claude_cli')
            ->assertJsonPath('decision.fallback_reason', 'candidate_auto_disabled')
            ->assertJsonPath('decision.task_profile.context_pressure', 'long')
            ->assertJsonPath('decision.task_profile.wide_code_context_signal', true)
            ->assertJsonPath('decision.context_strategy', 'gemini_scout_then_executor')
            ->assertJsonPath('decision.execution_strategy', 'scout_then_execute_planned')
            ->assertJsonPath('decision.execution_graph.nodes.0.provider', 'gemini_cli')
            ->assertJsonPath('decision.execution_graph.nodes.1.provider', 'claude_cli');
    }

    public function test_decision_preview_ignores_empty_attachment_envelopes(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
        ]);

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/decisions/preview', [
                'input_text' => 'Me diga se esta funcionando',
                'payload' => [
                    'decision_mode' => 'atlas_decide',
                    'operator_requested_provider' => 'auto',
                    'attachments' => [
                        'images' => [],
                        'files' => [],
                    ],
                    'visual_input' => ['image_count' => 0],
                    'file_input' => ['file_count' => 0],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('decision.selected_provider', 'claude_cli')
            ->assertJsonPath('decision.context_strategy', 'direct_context_pack')
            ->assertJsonPath('decision.task_profile.task_type', 'general')
            ->assertJsonPath('decision.task_profile.attachment_count', 0)
            ->assertJsonPath('decision.signals.has_visual_input', false)
            ->assertJsonPath('decision.signals.has_file_input', false)
            ->assertJsonPath('decision.constraints.needs_long_context_or_multimodal_provider', false);
    }

    public function test_decision_preview_falls_back_when_gemini_auto_is_disabled(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.gemini_cli.allow_auto' => false,
        ]);

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/decisions/preview', [
                'input_text' => 'Faca uma pesquisa profunda sobre memoria de IA',
                'payload' => [
                    'decision_mode' => 'atlas_decide',
                    'operator_requested_provider' => 'auto',
                    'context_strategy_hint' => 'long_context',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('decision.candidate_provider', 'gemini_cli')
            ->assertJsonPath('decision.selected_provider', 'claude_cli')
            ->assertJsonPath('decision.fallback_provider', 'claude_cli')
            ->assertJsonPath('decision.fallback_reason', 'candidate_auto_disabled')
            ->assertJsonPath('decision.reason', 'Atlas Decide avaliou gemini_cli, mas selecionou claude_cli por fallback (candidate_auto_disabled).')
            ->assertJsonPath('decision.was_overridden', false);
    }

    public function test_cli_decide_outputs_machine_readable_preview(): void
    {
        config([
            'atlas.ai.default_provider' => 'claude_cli',
            'atlas.ai.providers.gemini_cli.allow_auto' => true,
        ]);

        $exitCode = Artisan::call('atlas:ai:decide', [
            'input' => 'Faca uma pesquisa profunda sobre memoria de IA',
            '--mode' => 'research',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('atlas_decide', data_get($payload, 'decision.decision_mode'));
        $this->assertSame('gemini_cli', data_get($payload, 'decision.selected_provider'));
        $this->assertSame('gemini-3.1-pro-preview', data_get($payload, 'decision.selected_model'));
    }

    public function test_cli_decide_outputs_domain_catalog_selection_preview(): void
    {
        config([
            'atlas.ai.default_provider' => 'codex_cli',
            'atlas.ai.providers.codex_cli.allow_auto' => true,
        ]);

        $exitCode = Artisan::call('atlas:ai:decide', [
            'input' => 'Corrija a falha do fluxo de onboarding',
            '--mode' => 'debug',
            '--flow' => 'programming.repair',
            '--routing-domain' => 'blackink',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('ok', data_get($payload, 'decision.domain_catalog_selection.status'));
        $this->assertSame('atlas_cli', data_get($payload, 'decision.domain_catalog_selection.surface_id'));
        $this->assertSame('explicit_flow', data_get($payload, 'decision.domain_catalog_selection.selection_source'));
        $this->assertSame('programming', data_get($payload, 'decision.domain_catalog_selection.domain.id'));
        $this->assertSame('ready', data_get($payload, 'decision.domain_catalog_selection.domain.onboarding_status'));
        $this->assertSame('programming.repair', data_get($payload, 'decision.domain_catalog_selection.flow.id'));
        $this->assertSame('dev_repair_executor', data_get($payload, 'decision.domain_catalog_selection.flow.executor_preference'));
        $this->assertSame('blackink', data_get($payload, 'decision.domain_catalog_selection.ux.product_domain'));
        $this->assertSame('medium', data_get($payload, 'decision.domain_catalog_selection.safety.autonomy'));
        $this->assertTrue(data_get($payload, 'decision.domain_catalog_selection.safety.surface_must_confirm_destructive'));
    }

    private function recordDecision(AiTrace $trace, array $options, string $provider, ?string $model, AiPrompt $prompt): void
    {
        $service = app(AiGatewayService::class);
        $method = (new ReflectionClass($service))->getMethod('recordAtlasDecision');
        $method->setAccessible(true);

        $method->invoke($service, $trace, app(AtlasDecideService::class)->normalizeOptions($options), $provider, $model, $prompt, [
            'model' => $model,
            'source' => 'test',
            'model_label' => $model,
            'model_tier' => $provider === 'gemini_cli' ? 'premium' : 'daily',
            'allow_auto' => true,
            'allow_manual' => true,
        ]);
    }

    private function trace(string $input, string $provider, ?string $model): AiTrace
    {
        return AiTrace::query()->create([
            'trace_key' => 'trace_'.str()->uuid(),
            'source_type' => 'app',
            'status' => 'queued',
            'operator_input' => $input,
            'agent_slug' => 'orquestrador',
            'provider' => $provider,
            'model' => $model,
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => [],
        ]);
    }

    private function prompt(string $taskType, string $riskLevel): AiPrompt
    {
        return new AiPrompt(
            prompt: 'prompt',
            agentSlug: 'orquestrador',
            intent: 'analysis',
            skillVersions: [],
            contextRefs: [],
            taskRequest: [
                'task_type' => $taskType,
                'risk_level' => $riskLevel,
            ],
            executionPlan: [
                'workflow' => $taskType,
            ],
        );
    }

    private function createDecisionTables(): void
    {
        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->default('manual');
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input');
            $table->string('intent')->nullable();
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->string('response_hash')->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_router_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('mode', 32)->default('direct');
            $table->string('selected_provider', 32);
            $table->string('fallback_provider', 32)->nullable();
            $table->json('signals');
            $table->text('reason');
            $table->boolean('was_overridden')->default(false);
            $table->timestamps();
        });

        Schema::create('ai_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('router_decision_id')->nullable()->index();
            $table->string('policy_version', 32)->default('atlas-decide-v1');
            $table->string('decision_mode', 32)->default('atlas_decide');
            $table->string('route_mode', 64)->nullable();
            $table->string('task_type', 80)->nullable();
            $table->string('risk_level', 40)->nullable();
            $table->string('context_strategy', 64)->nullable();
            $table->string('execution_strategy', 64)->nullable();
            $table->string('selected_provider', 32);
            $table->string('selected_model', 120)->nullable();
            $table->string('fallback_provider', 32)->nullable();
            $table->string('operator_requested_provider', 32)->default('auto');
            $table->string('requested_provider', 32)->nullable();
            $table->boolean('was_overridden')->default(false);
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->json('signals');
            $table->json('candidates')->nullable();
            $table->json('constraints')->nullable();
            $table->json('metrics_snapshot')->nullable();
            $table->json('task_profile')->nullable();
            $table->json('execution_graph')->nullable();
            $table->text('reason');
            $table->timestamps();
        });
    }
}
