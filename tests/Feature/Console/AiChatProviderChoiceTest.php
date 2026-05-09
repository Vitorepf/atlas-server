<?php

namespace Tests\Feature\Console;

use App\Models\AiJob;
use App\Models\AiSession;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\AiProviderChoiceException;
use App\Services\Ai\AiProviderChoiceResolver;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineContract;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineStage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAiJobChoiceTables;
use Tests\TestCase;

class AiChatProviderChoiceTest extends TestCase
{
    use CreatesAiJobChoiceTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAiJobChoiceTables();
        $this->migrateLedger();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        $this->dropAiJobChoiceTables();

        parent::tearDown();
    }

    public function test_operator_picking_switch_provider_requeues_job(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'processing',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'awaiting_user_choice',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->addYear(),
            'metadata' => [
                'provider_choice_state' => 'pending',
                'provider_choice_error_code' => 'rate_limited',
                'reset_hint' => 'May 5th, 2026 10:24 AM',
                'choice_options' => [
                    ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'claude_cli', 'model' => null, 'label' => 'Migrar para Claude', 'description' => ''],
                    ['id' => 'cancel', 'action' => 'cancel', 'label' => 'Cancelar', 'description' => ''],
                ],
            ],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job->refresh(), 'switch_provider');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame('claude_cli', $job->provider);
        $this->assertNull(data_get($job->metadata, 'provider_choice_state'));
    }

    public function test_operator_picking_retry_same_resets_choice_state(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'processing',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'awaiting_user_choice',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->addYear(),
            'metadata' => [
                'provider_choice_state' => 'pending',
                'choice_options' => [
                    ['id' => 'retry_same', 'action' => 'retry_same', 'provider' => 'codex_cli', 'model' => null, 'label' => 'Tentar de novo', 'description' => ''],
                ],
            ],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job->refresh(), 'retry_same');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertNull(data_get($job->metadata, 'provider_choice_state'),
            'retry_same deve resetar o flag para permitir nova pausa.');
    }

    public function test_fair_mode_rejects_tampered_switch_provider_choice(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'processing',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'awaiting_user_choice',
            'agent_slug' => 'orquestrador',
            'provider' => 'claude_cli',
            'model' => 'claude-opus-4-7',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->addYear(),
            'payload' => [
                'fair_mode' => app(FairClaudePolicy::class)->metadata(),
            ],
            'metadata' => [
                'provider_choice_state' => 'pending',
                'choice_options' => [
                    ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'codex_cli', 'model' => null],
                    ['id' => 'retry_same', 'action' => 'retry_same', 'provider' => 'claude_cli', 'model' => null],
                ],
            ],
        ]);

        $this->expectException(AiProviderChoiceException::class);

        app(AiProviderChoiceResolver::class)->resolve($job->refresh(), 'switch_provider');
    }

    public function test_chat_manual_provider_fails_before_enqueue_when_app_blocks_manual_use(): void
    {
        config([
            'atlas.ai.providers.gemini_cli.allow_manual' => false,
        ]);

        $exitCode = Artisan::call('atlas:ai:chat', [
            'input' => 'analise sem executar',
            '--provider' => 'gemini_cli',
            '--no-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame(false, data_get($payload, 'ok'));
        $this->assertSame('atlas_manual_provider_blocked', data_get($payload, 'error'));
        $this->assertSame('gemini_cli', data_get($payload, 'provider'));
    }

    public function test_chat_claude_only_rejects_codex_provider_before_enqueue(): void
    {
        $exitCode = Artisan::call('atlas:ai:chat', [
            'input' => 'implemente sem executar',
            '--claude-only' => true,
            '--provider' => 'codex_cli',
            '--no-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse(data_get($payload, 'ok'));
        $this->assertSame('fair_mode_violation', data_get($payload, 'error'));
        $this->assertSame('codex_cli', data_get($payload, 'details.provider'));
    }

    public function test_chat_accepts_manual_claude_with_image_attachment(): void
    {
        $workspace = storage_path('framework/testing/image-provider-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workspace);
        $imagePath = $workspace.'/screen.png';
        File::put($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/l8Jv6wAAAABJRU5ErkJggg=='));

        $exitCode = Artisan::call('atlas:ai:chat', [
            'input' => 'analise esta imagem',
            '--provider' => 'claude_cli',
            '--image' => [$imagePath],
            '--no-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $job = AiJob::query()->latest('created_at')->first();

        $this->assertSame(0, $exitCode);
        $this->assertSame('claude_cli', data_get($payload, 'provider'));
        $this->assertNotNull($job);
        $images = (array) data_get($job?->payload, 'attachments.images', []);
        $this->assertCount(1, $images);
        $this->assertSame($imagePath, data_get($images[0] ?? [], 'path'));
    }

    public function test_chat_dev_without_explicit_dev_plan_generates_programming_contract(): void
    {
        $workspace = storage_path('framework/testing/chat-dev-plan-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workspace);
        config()->set('atlas.ai.tool_permissions.allowed_roots', [dirname($workspace)]);

        $exitCode = Artisan::call('atlas:ai:chat', [
            'input' => 'corrija o teste falhando no login',
            '--workspace' => $workspace,
            '--dev' => true,
            '--permission' => 'write',
            '--allow-write' => true,
            '--no-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $job = AiJob::query()->latest('created_at')->first();

        $this->assertSame(0, $exitCode);
        $this->assertSame('dev', data_get($job?->payload, 'atlas_workflow_mode'));
        $this->assertSame('AtlasProgrammingOrchestrator', data_get($job?->payload, 'dev_execution_plan.orchestrator'));
        $this->assertSame('AiChatCommand', data_get($job?->payload, 'dev_execution_plan.operator_options.generated_by'));
        $this->assertSame('atlas.kernel.pipeline.scaffold.v1', data_get($job?->payload, 'dev_execution_plan.kernel_pipeline.schema_version'));
        $this->assertSame('atlas_ai_chat', data_get($job?->payload, 'dev_execution_plan.kernel_pipeline.input.surface_id'));
        $this->assertSame('programming.repair', data_get($job?->payload, 'dev_execution_plan.kernel_pipeline.input.safe_hints.flow'));
        $this->assertSame('chat_dev_auto_plan', data_get($job?->payload, 'dev_execution_plan.kernel_pipeline.surface_binding.input_mode'));
        $this->assertFalse(data_get($job?->payload, 'dev_execution_plan.kernel_pipeline.provider_execution_allowed'));
        $this->assertSame('atlas.ai_chat.model_selection_contract.v1', data_get($job?->payload, 'model_selection_contract.schema_version'));
        $this->assertSame('atlas_ai_chat', data_get($job?->payload, 'model_selection_contract.surface'));
        $this->assertSame('atlas_decide', data_get($job?->payload, 'model_selection_contract.authority'));
        $this->assertSame('auto_best_allowed', data_get($job?->payload, 'model_selection_contract.selection_mode'));
        $this->assertSame(['auto_best_allowed', 'auto_best_available', 'manual_override'], data_get($job?->payload, 'model_selection_contract.available_selection_modes'));
        $this->assertSame('auto', data_get($job?->payload, 'model_selection_contract.operator_requested_provider'));
        $this->assertSame('programming', data_get($job?->payload, 'model_selection_contract.domain'));
        $this->assertSame('programming.repair', data_get($job?->payload, 'model_selection_contract.flow'));
        $this->assertNull(data_get($job?->payload, 'model_selection_contract.specialist_profile'));
        $this->assertSame('atlas.provider_governance.v1', data_get($job?->payload, 'provider_governance.schema_version'));
        $this->assertSame('atlas_decide', data_get($job?->payload, 'provider_governance.decision_mode'));
        $this->assertSame('atlas_decide', data_get($job?->payload, 'provider_governance.decision_authority'));
        $this->assertSame('auto', data_get($job?->payload, 'provider_governance.operator_requested_provider'));
        $this->assertNotEmpty(data_get($job?->payload, 'provider_governance.execution_provider'));
        $this->assertTrue(data_get($job?->payload, 'provider_governance.separation_contract.provider_is_executor_only'));
        $this->assertTrue(data_get($job?->payload, 'provider_governance.separation_contract.provider_may_not_be_treated_as_atlas_identity'));
        $this->assertSame(data_get($job?->payload, 'model_selection_contract'), data_get($payload, 'model_selection_contract'));
        $this->assertSame(data_get($job?->payload, 'dev_execution_plan.kernel_pipeline'), data_get($job?->payload, 'kernel_pipeline'));
        $this->assertSame('AtlasProgrammingOrchestrator', data_get($job?->payload, 'programming_message_plan.orchestrator'));
        $this->assertSame(
            data_get($job?->payload, 'dev_execution_plan.plan_id'),
            data_get($job?->payload, 'programming_message_plan.parent_plan_id')
        );
        $this->assertSame('dev_repair_executor', data_get($job?->payload, 'programming_dispatch.executor'));
        $this->assertSame('repair', data_get($job?->payload, 'programming_message_plan.operator_intent.kind'));
        $this->assertSame('atlas.ai_chat.programming_contract.v1', data_get($job?->payload, 'programming_chat_contract.schema_version'));
        $this->assertSame('atlas_ai_chat', data_get($job?->payload, 'programming_chat_contract.surface'));
        $this->assertSame('AtlasProgrammingOrchestrator', data_get($job?->payload, 'programming_chat_contract.orchestrator'));
        $this->assertSame('programming.repair', data_get($job?->payload, 'programming_chat_contract.programming_flow'));
        $this->assertSame('repair', data_get($job?->payload, 'programming_chat_contract.operator_intent'));
        $this->assertSame('dev_repair_executor', data_get($job?->payload, 'programming_chat_contract.executor'));
        $this->assertSame('ai_gateway_provider', data_get($job?->payload, 'programming_chat_contract.dispatch_path'));
        $this->assertSame('atlas_ai_chat', data_get($job?->payload, 'programming_chat_contract.kernel_pipeline_surface'));
        $this->assertSame('programming.repair', data_get($job?->payload, 'programming_chat_contract.kernel_pipeline_flow'));
        $this->assertSame('chat_dev_auto_plan', data_get($job?->payload, 'programming_chat_contract.kernel_pipeline_input_mode'));
        $this->assertFalse(data_get($job?->payload, 'programming_chat_contract.kernel_pipeline_provider_execution_allowed'));
        $this->assertTrue(data_get($job?->payload, 'programming_chat_contract.kernel_pipeline_contract_required'));
        $this->assertSame(data_get($job?->payload, 'programming_dispatch'), data_get($payload, 'programming_dispatch'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'KERNEL_PIPELINE_ACCEPTED',
            'emitter_stage' => 'atlas.ai_chat.kernel_pipeline_guard',
        ]);

        File::deleteDirectory($workspace);
    }

    public function test_chat_dev_attaches_kernel_pipeline_to_legacy_declared_dev_plan(): void
    {
        $workspace = storage_path('framework/testing/chat-declared-dev-plan-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workspace);
        config()->set('atlas.ai.tool_permissions.allowed_roots', [dirname($workspace)]);

        $declaredPlan = [
            'schema_version' => 1,
            'plan_id' => 'legacy-plan-1',
            'orchestrator' => 'AtlasProgrammingOrchestrator',
            'programming_profile' => 'dev',
            'operator_options' => [
                'complete' => true,
                'max_iterations' => 3,
            ],
        ];

        $exitCode = Artisan::call('atlas:ai:chat', [
            'input' => 'implemente uma melhoria pequena',
            '--workspace' => $workspace,
            '--dev' => true,
            '--dev-plan' => json_encode($declaredPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '--permission' => 'write',
            '--allow-write' => true,
            '--no-run' => true,
            '--json' => true,
        ]);
        $job = AiJob::query()->latest('created_at')->first();

        $this->assertSame(0, $exitCode);
        $this->assertSame('legacy-plan-1', data_get($job?->payload, 'dev_execution_plan.plan_id'));
        $this->assertSame('atlas.kernel.pipeline.scaffold.v1', data_get($job?->payload, 'dev_execution_plan.kernel_pipeline.schema_version'));
        $this->assertSame('declared_dev_plan', data_get($job?->payload, 'dev_execution_plan.kernel_pipeline.surface_binding.input_mode'));
        $this->assertSame('programming.dev', data_get($job?->payload, 'dev_execution_plan.kernel_pipeline.input.safe_hints.flow'));
        $this->assertTrue(data_get($job?->payload, 'dev_execution_plan.kernel_pipeline_contract.required'));
        $this->assertSame('atlas.ai_chat.programming_contract.v1', data_get($job?->payload, 'programming_chat_contract.schema_version'));
        $this->assertSame('programming.dev', data_get($job?->payload, 'programming_chat_contract.programming_flow'));
        $this->assertSame('programming.dev', data_get($job?->payload, 'programming_chat_contract.kernel_pipeline_flow'));
        $this->assertSame('declared_dev_plan', data_get($job?->payload, 'programming_chat_contract.kernel_pipeline_input_mode'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'KERNEL_PIPELINE_ACCEPTED',
            'emitter_stage' => 'atlas.ai_chat.kernel_pipeline_guard',
        ]);

        File::deleteDirectory($workspace);
    }

    public function test_chat_dev_rejects_tampered_declared_kernel_pipeline_before_enqueue(): void
    {
        $workspace = storage_path('framework/testing/chat-bad-kernel-pipeline-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workspace);
        config()->set('atlas.ai.tool_permissions.allowed_roots', [dirname($workspace)]);

        $declaredPlan = [
            'schema_version' => 1,
            'plan_id' => 'tampered-plan-1',
            'orchestrator' => 'AtlasProgrammingOrchestrator',
            'programming_profile' => 'dev',
            'kernel_pipeline' => [
                'schema_version' => KernelPipelineContract::SCHEMA_VERSION,
                'mode' => KernelPipelineContract::MODE,
                'status' => KernelPipelineContract::STATUS,
                'canonical_flow_hash' => 'tampered',
                'stage_order' => array_reverse(KernelPipelineStage::orderedValues()),
                'stage_count' => count(KernelPipelineStage::orderedValues()),
                'provider_execution_allowed' => true,
                'runtime_execution_allowed' => false,
                'execution_guards' => [
                    'dry_run_effective' => true,
                    'provider_execution_allowed' => true,
                    'runtime_execution_allowed' => false,
                    'surface_runtime_migration_allowed' => false,
                ],
                'input' => [
                    'surface_id' => 'atlas_ai_chat',
                    'safe_hints' => [
                        'flow' => 'programming.dev',
                    ],
                ],
                'surface_binding' => [
                    'surface' => 'atlas_ai_chat',
                    'input_mode' => 'declared_dev_plan',
                ],
            ],
        ];

        $exitCode = Artisan::call('atlas:ai:chat', [
            'input' => 'implemente sem rodar',
            '--workspace' => $workspace,
            '--dev' => true,
            '--dev-plan' => json_encode($declaredPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '--permission' => 'write',
            '--allow-write' => true,
            '--no-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertFalse(data_get($payload, 'ok'));
        $this->assertSame('atlas_kernel_pipeline_contract_violation', data_get($payload, 'error'));
        $this->assertNotEmpty(data_get($payload, 'violations'));
        $this->assertSame(0, AiJob::query()->count());
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'KERNEL_PIPELINE_REJECTED',
            'emitter_stage' => 'atlas.ai_chat.kernel_pipeline_guard',
        ]);

        File::deleteDirectory($workspace);
    }

    public function test_chat_dev_rejects_tampered_declared_kernel_pipeline_contract_before_enqueue(): void
    {
        $workspace = storage_path('framework/testing/chat-bad-kernel-contract-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workspace);
        config()->set('atlas.ai.tool_permissions.allowed_roots', [dirname($workspace)]);

        $declaredPlan = [
            'schema_version' => 1,
            'plan_id' => 'tampered-contract-plan-1',
            'orchestrator' => 'AtlasProgrammingOrchestrator',
            'programming_profile' => 'dev',
            'kernel_pipeline' => [
                'schema_version' => KernelPipelineContract::SCHEMA_VERSION,
                'mode' => KernelPipelineContract::MODE,
                'status' => KernelPipelineContract::STATUS,
                'canonical_flow_hash' => KernelPipelineContract::canonicalFlowHash(),
                'stage_order' => KernelPipelineStage::orderedValues(),
                'stage_count' => count(KernelPipelineStage::orderedValues()),
                'provider_execution_allowed' => false,
                'runtime_execution_allowed' => false,
                'execution_guards' => [
                    'dry_run_effective' => true,
                    'provider_execution_allowed' => false,
                    'runtime_execution_allowed' => false,
                    'surface_runtime_migration_allowed' => false,
                ],
                'input' => [
                    'surface_id' => 'atlas_ai_chat',
                    'safe_hints' => [
                        'flow' => 'programming.dev',
                    ],
                ],
                'surface_binding' => [
                    'surface' => 'atlas_ai_chat',
                    'command' => 'atlas:ai:chat',
                    'input_mode' => 'declared_dev_plan',
                ],
            ],
            'kernel_pipeline_contract' => [
                'required' => true,
                'source' => 'SurfaceCommand',
                'surface_must_not_decide' => false,
                'provider_execution_blocked_until_runtime_migration' => true,
                'runtime_execution_blocked_until_runtime_migration' => true,
            ],
        ];

        $exitCode = Artisan::call('atlas:ai:chat', [
            'input' => 'implemente sem rodar',
            '--workspace' => $workspace,
            '--dev' => true,
            '--dev-plan' => json_encode($declaredPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '--permission' => 'write',
            '--allow-write' => true,
            '--no-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertFalse(data_get($payload, 'ok'));
        $this->assertSame('atlas_kernel_pipeline_contract_violation', data_get($payload, 'error'));
        $this->assertContains('kernel_pipeline_contract.source is not recognized.', data_get($payload, 'violations'));
        $this->assertContains('kernel_pipeline_contract.surface_must_not_decide must be true.', data_get($payload, 'violations'));
        $this->assertSame(0, AiJob::query()->count());
        $this->assertDatabaseHas('atlas_ledger_events', [
            'event_type' => 'KERNEL_PIPELINE_REJECTED',
            'emitter_stage' => 'atlas.ai_chat.kernel_pipeline_guard',
        ]);

        File::deleteDirectory($workspace);
    }

    public function test_chat_claude_only_projects_claude_only_session_policy_override(): void
    {
        config([
            'atlas.ai.providers.claude_cli.premium_model' => 'claude-opus-test',
            'atlas.ai.providers.claude_cli.premium_model_label' => 'Claude Opus Test',
        ]);

        $exitCode = Artisan::call('atlas:ai:chat', [
            'input' => 'implemente sem executar',
            '--claude-only' => true,
            '--model' => 'opus',
            '--no-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $job = AiJob::query()->latest('created_at')->first();

        $this->assertSame(0, $exitCode);
        $this->assertSame('claude_cli', data_get($payload, 'provider'));
        $this->assertSame('claude-opus-test', data_get($payload, 'model'));
        $this->assertSame(['claude_cli'], data_get($job?->payload, 'ai_policy_override.enabled_providers'));
        $this->assertSame(['codex_cli', 'gemini_cli'], data_get($job?->payload, 'ai_policy_override.disabled_providers'));
        $this->assertSame(['claude_cli'], data_get($job?->payload, 'ai_policy_override.fallback_order'));
        $this->assertSame(['claude-opus-test'], data_get($job?->payload, 'ai_policy_override.allowed_models.claude_cli'));
        $this->assertSame([], data_get($job?->payload, 'ai_policy_override.allowed_models.codex_cli'));
        $this->assertFalse(data_get($job?->payload, 'ai_policy_override.providers.codex_cli.allow_manual'));
        $this->assertTrue(data_get($job?->payload, 'fair_mode.fair_mode'));
    }

    private function migrateLedger(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    public function test_chat_claude_only_does_not_create_provider_handoff_when_thread_last_provider_differs(): void
    {
        config([
            'atlas.ai.providers.claude_cli.premium_model' => 'claude-opus-test',
            'atlas.ai.providers.claude_cli.premium_model_label' => 'Claude Opus Test',
        ]);

        $thread = AiThread::create([
            'title' => 'Fair benchmark thread',
            'status' => 'active',
            'surface' => 'atlas_cli',
            'workspace' => getcwd(),
            'last_provider' => 'codex_cli',
        ]);
        AiSession::create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'purpose' => 'atlas_cli',
            'provider_primary' => 'codex_cli',
            'provider_last' => 'codex_cli',
            'started_at' => now(),
        ]);

        $exitCode = Artisan::call('atlas:ai:chat', [
            'input' => 'continue sem handoff',
            '--thread' => $thread->id,
            '--claude-only' => true,
            '--model' => 'opus',
            '--no-run' => true,
            '--json' => true,
        ]);
        $job = AiJob::query()->latest('created_at')->first();

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, DB::table('ai_provider_handoffs')->count());
        $this->assertNull(data_get($job?->payload, 'provider_handoff_id'));
        $this->assertTrue(data_get($job?->payload, 'provider_handoff_disabled_by_fair_mode'));
        $this->assertTrue(data_get($job?->payload, 'fair_mode.fair_mode'));
    }

    public function test_chat_ai_model_flags_project_session_policy_override_to_job_payload(): void
    {
        $exitCode = Artisan::call('atlas:ai:chat', [
            'input' => 'implemente sem executar',
            '--ai' => 'codex',
            '--model' => '5.5',
            '--no-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $job = AiJob::query()->latest('created_at')->first();

        $this->assertSame(0, $exitCode);
        $this->assertSame('codex_cli', data_get($payload, 'provider'));
        $this->assertSame('gpt-5.5', data_get($payload, 'model'));
        $this->assertSame('atlas.ai_chat.model_selection_contract.v1', data_get($job?->payload, 'model_selection_contract.schema_version'));
        $this->assertSame('atlas_decide', data_get($job?->payload, 'model_selection_contract.authority'));
        $this->assertSame('manual_override', data_get($job?->payload, 'model_selection_contract.selection_mode'));
        $this->assertSame('codex_cli', data_get($job?->payload, 'model_selection_contract.operator_requested_provider'));
        $this->assertSame('gpt-5.5', data_get($job?->payload, 'model_selection_contract.requested_model'));
        $this->assertSame('codex-premium', data_get($job?->payload, 'model_selection_contract.requested_model_alias'));
        $this->assertSame('codex_cli', data_get($job?->payload, 'ai_policy_override.default_provider'));
        $this->assertSame('gpt-5.5', data_get($job?->payload, 'ai_policy_override.providers.codex_cli.model'));
        $this->assertSame(['gpt-5.5'], data_get($job?->payload, 'ai_policy_override.allowed_models.codex_cli'));
        $this->assertSame('manual_override', data_get($job?->payload, 'provider_governance.decision_mode'));
        $this->assertSame('operator_override', data_get($job?->payload, 'provider_governance.decision_authority'));
        $this->assertSame('codex_cli', data_get($job?->payload, 'provider_governance.operator_requested_provider'));
        $this->assertSame('codex_cli', data_get($job?->payload, 'provider_governance.execution_provider'));
        $this->assertTrue(data_get($job?->payload, 'provider_governance.separation_contract.manual_override_must_remain_visible'));
    }
}
