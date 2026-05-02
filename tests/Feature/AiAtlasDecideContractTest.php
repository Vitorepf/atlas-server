<?php

namespace Tests\Feature;

use App\Models\AiDecision;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiPrompt;
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
            ->assertJsonPath('decision.was_overridden', false);
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

    private function recordDecision(AiTrace $trace, array $options, string $provider, ?string $model, AiPrompt $prompt): void
    {
        $service = app(AiGatewayService::class);
        $method = (new ReflectionClass($service))->getMethod('recordAtlasDecision');
        $method->setAccessible(true);

        $method->invoke($service, $trace, app(\App\Services\Ai\AtlasDecideService::class)->normalizeOptions($options), $provider, $model, $prompt, [
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
            $table->text('reason');
            $table->timestamps();
        });
    }
}
