<?php

namespace Tests\Unit;

use App\Models\AiCompaction;
use App\Models\AiJob;
use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\AiCompactionService;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiSessionManager;
use App\Services\Ai\AiThreadResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AiSessionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createAiRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropAiRuntimeTables();

        parent::tearDown();
    }

    public function test_idle_session_is_paused_and_resumed_inside_same_thread(): void
    {
        config(['atlas.ai.session_idle_minutes' => 60]);

        $thread = AiThread::query()->create([
            'title' => 'Sessao longa Atlas',
            'status' => 'active',
            'surface' => 'atlas_ai_sheet',
            'metadata' => [],
        ]);

        $manager = app(AiSessionManager::class);
        $first = $manager->ensureActive($thread, 'claude_cli', 'começar implementação', [
            'payload' => ['app_surface' => 'atlas_ai_sheet'],
        ]);

        $this->travel(61)->minutes();

        $second = $manager->ensureActive($thread->refresh(), 'codex_cli', 'continua', [
            'payload' => ['app_surface' => 'atlas_ai_sheet'],
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('paused', $first->refresh()->status);
        $this->assertSame('active', $second->status);
        $this->assertSame('session_idle_resume', $second->metadata['creation_reason']);
        $this->assertSame($first->id, $second->metadata['resumed_from_session_id']);
    }

    public function test_gateway_compacts_thread_when_resuming_idle_session(): void
    {
        config([
            'atlas.ai.enabled' => true,
            'atlas.ai.session_idle_minutes' => 60,
        ]);

        $thread = AiThread::query()->create([
            'title' => 'Dev Atlas Harness',
            'status' => 'active',
            'surface' => 'atlas_ai_sheet',
            'metadata' => [],
        ]);

        $manager = app(AiSessionManager::class);
        $first = $manager->ensureActive($thread, 'claude_cli', 'planejar arquitetura', [
            'payload' => ['app_surface' => 'atlas_ai_sheet'],
        ]);

        AiMessage::query()->create([
            'thread_id' => $thread->id,
            'position' => 1,
            'role' => 'user',
            'status' => 'final',
            'content' => 'Objetivo: Atlas precisa preservar continuidade melhor que Claude e Codex isolados.',
            'provider' => null,
            'token_estimate' => 20,
            'occurred_at' => now(),
            'metadata' => [],
        ]);

        $this->travel(61)->minutes();

        $trace = app(AiGatewayService::class)->enqueueInteraction('continua', [
            'thread_id' => $thread->id,
            'provider' => 'codex_cli',
            'source_type' => 'app',
            'include_semantic_context' => false,
            'payload' => [
                'app_surface' => 'atlas_ai_sheet',
                'atlas_workflow_mode' => 'direct',
            ],
        ]);

        $newSession = AiSession::query()
            ->where('thread_id', $thread->id)
            ->where('status', 'active')
            ->firstOrFail();

        $this->assertNotSame($first->id, $newSession->id);
        $this->assertSame($newSession->id, $trace->session_id);
        $this->assertDatabaseHas('ai_compactions', [
            'thread_id' => $thread->id,
            'session_id' => $newSession->id,
            'reason' => 'session_resume',
        ]);
        $this->assertNotNull(AiCompaction::query()->where('thread_id', $thread->id)->first()?->summary);
    }

    public function test_gateway_records_council_runtime_as_atlas_council_provider(): void
    {
        config([
            'atlas.ai.enabled' => true,
        ]);

        $clientId = '11111111-1111-4111-8111-111111111111';

        $trace = app(AiGatewayService::class)->enqueueInteraction('Compare Claude e Codex para esta decisão.', [
            'client_id' => $clientId,
            'source_type' => 'app',
            'kind' => 'council',
            'include_semantic_context' => false,
            'payload' => [
                'app_surface' => 'atlas_ai_sheet',
                'atlas_workflow_mode' => 'plan',
                'requested_provider' => 'claude_codex',
                'execution_policy' => 'dual_review',
                'council_providers' => ['claude_cli', 'codex_cli'],
            ],
        ]);

        $session = AiSession::query()->whereKey($trace->session_id)->firstOrFail();

        $this->assertSame('claude_codex', $trace->provider);
        $this->assertSame('claude_codex', $session->provider_primary);
        $this->assertSame('claude_codex', $session->provider_last);
        $this->assertSame($clientId, $trace->metadata['client_id']);
        $this->assertSame(2, $trace->jobs()->count());
        $this->assertSame($clientId, $trace->jobs()->where('provider', 'claude_cli')->firstOrFail()->client_id);
    }

    public function test_gateway_records_open_brain_injection_for_dev_app_interaction(): void
    {
        config([
            'atlas.ai.enabled' => true,
        ]);
        $this->createOpenBrainAuditTable();

        $trace = app(AiGatewayService::class)->enqueueInteraction('implemente uma melhoria no Atlas', [
            'source_type' => 'app',
            'provider' => 'codex_cli',
            'include_semantic_context' => false,
            'payload' => [
                'app_surface' => 'atlas_ai_sheet',
                'atlas_workflow_mode' => 'dev',
                'workspace' => base_path(),
            ],
        ]);

        $this->assertContains(data_get($trace->metadata, 'open_brain_injection.status'), ['injected', 'degraded']);
        $this->assertSame('app_ai', data_get($trace->metadata, 'open_brain_injection.surface'));
        $this->assertNotEmpty(data_get($trace->metadata, 'open_brain_injection.context_pack_hash'));
        $this->assertStringContainsString('Atlas Open Brain Context', (string) $trace->job->prompt);
        $this->assertDatabaseHas('atlas_open_brain_access_logs', [
            'surface' => 'app_ai',
            'action' => 'context_injection',
        ]);
    }

    public function test_gateway_reuses_existing_trace_for_duplicate_client_id(): void
    {
        config([
            'atlas.ai.enabled' => true,
        ]);

        $clientId = '22222222-2222-4222-8222-222222222222';
        $gateway = app(AiGatewayService::class);

        $first = $gateway->enqueueInteraction('primeira tentativa antes de sair do app', [
            'client_id' => $clientId,
            'source_type' => 'app',
            'kind' => 'interaction',
            'include_semantic_context' => false,
            'payload' => [
                'app_surface' => 'atlas_ai_sheet',
                'atlas_workflow_mode' => 'direct',
            ],
        ]);

        $second = $gateway->enqueueInteraction('retry local depois de reabrir o app', [
            'client_id' => $clientId,
            'source_type' => 'app',
            'kind' => 'interaction',
            'include_semantic_context' => false,
            'payload' => [
                'app_surface' => 'atlas_ai_sheet',
                'atlas_workflow_mode' => 'direct',
            ],
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AiTrace::query()->count());
        $this->assertSame(1, AiJob::query()->where('client_id', $clientId)->count());
    }

    public function test_thread_resolver_uses_explicit_session_id_when_thread_id_is_absent(): void
    {
        $thread = AiThread::query()->create([
            'title' => 'Thread original',
            'status' => 'active',
            'surface' => 'atlas_ai_sheet',
            'metadata' => [],
        ]);

        $session = AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'purpose' => 'Retomar sessão',
            'started_at' => now(),
            'metadata' => [],
        ]);

        $resolution = app(AiThreadResolver::class)->resolve('continua', [
            'session_id' => $session->id,
        ]);

        $this->assertSame($thread->id, $resolution->thread->id);
        $this->assertSame('explicit_session_id', $resolution->strategy);
        $this->assertFalse($resolution->created);
    }

    public function test_thread_resolver_creates_new_thread_by_default_even_for_short_continuation_text(): void
    {
        $workspace = (string) config('atlas.ai.workdir');
        $existingThread = AiThread::query()->create([
            'title' => 'Thread que nao deve ser reutilizada',
            'status' => 'active',
            'surface' => 'atlas_ai_sheet',
            'workspace' => $workspace,
            'last_message_at' => now(),
            'metadata' => [],
        ]);

        $resolution = app(AiThreadResolver::class)->resolve('continua', [
            'source_type' => 'app',
            'payload' => [
                'app_surface' => 'atlas_ai_sheet',
                'conversation_context' => [
                    'turns' => [
                        ['role' => 'user', 'content' => 'mensagem de outra conversa'],
                    ],
                ],
            ],
        ]);

        $this->assertNotSame($existingThread->id, $resolution->thread->id);
        $this->assertSame('implicit_new_thread', $resolution->strategy);
        $this->assertTrue($resolution->created);
        $this->assertSame(2, AiThread::query()->count());
    }

    public function test_thread_resolver_only_uses_latest_candidate_when_implicit_continuation_is_enabled(): void
    {
        $workspace = (string) config('atlas.ai.workdir');
        $existingThread = AiThread::query()->create([
            'title' => 'Thread retomada explicitamente',
            'status' => 'active',
            'surface' => 'atlas_ai_sheet',
            'workspace' => $workspace,
            'last_message_at' => now(),
            'metadata' => [],
        ]);

        $resolution = app(AiThreadResolver::class)->resolve('continua', [
            'source_type' => 'app',
            'allow_implicit_thread_continuation' => true,
            'payload' => [
                'app_surface' => 'atlas_ai_sheet',
            ],
        ]);

        $this->assertSame($existingThread->id, $resolution->thread->id);
        $this->assertSame('latest_continuation_candidate', $resolution->strategy);
        $this->assertFalse($resolution->created);
    }

    public function test_explicit_session_must_belong_to_resolved_thread(): void
    {
        $firstThread = AiThread::query()->create([
            'title' => 'Primeira thread',
            'status' => 'active',
            'surface' => 'atlas_ai_sheet',
            'metadata' => [],
        ]);
        $secondThread = AiThread::query()->create([
            'title' => 'Segunda thread',
            'status' => 'active',
            'surface' => 'atlas_ai_sheet',
            'metadata' => [],
        ]);

        $foreignSession = AiSession::query()->create([
            'thread_id' => $firstThread->id,
            'status' => 'active',
            'purpose' => 'Sessão de outra thread',
            'started_at' => now(),
            'metadata' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI session does not belong to the resolved thread.');

        app(AiSessionManager::class)->ensureActive($secondThread, 'codex_cli', 'continua', [
            'session_id' => $foreignSession->id,
        ]);
    }

    public function test_explicit_new_thread_ignores_stale_session_id(): void
    {
        $existingThread = AiThread::query()->create([
            'title' => 'Thread existente',
            'status' => 'active',
            'surface' => 'atlas_ai_sheet',
            'metadata' => [],
        ]);
        $newThread = AiThread::query()->create([
            'title' => 'Thread nova',
            'status' => 'active',
            'surface' => 'atlas_ai_sheet',
            'metadata' => [],
        ]);

        $staleSession = AiSession::query()->create([
            'thread_id' => $existingThread->id,
            'status' => 'active',
            'purpose' => 'Sessão antiga',
            'started_at' => now(),
            'metadata' => [],
        ]);

        $session = app(AiSessionManager::class)->ensureActive($newThread, 'claude_cli', 'novo assunto', [
            'new_thread' => true,
            'session_id' => $staleSession->id,
        ]);

        $this->assertSame($newThread->id, $session->thread_id);
        $this->assertSame('new_active_session', $session->metadata['creation_reason']);
    }

    public function test_auto_compaction_is_idempotent_without_new_messages(): void
    {
        config([
            'atlas.ai.auto_compaction_message_threshold' => 2,
            'atlas.ai.auto_compaction_messages_since_last' => 2,
        ]);

        $thread = AiThread::query()->create([
            'title' => 'Thread para auto compactacao',
            'status' => 'active',
            'surface' => 'atlas_ai_sheet',
            'metadata' => [],
        ]);

        $session = AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'purpose' => 'Auto compactar',
            'started_at' => now(),
            'metadata' => [],
        ]);

        foreach (range(1, 3) as $position) {
            AiMessage::query()->create([
                'thread_id' => $thread->id,
                'position' => $position,
                'role' => $position % 2 === 0 ? 'assistant' : 'user',
                'status' => 'final',
                'content' => "mensagem {$position}",
                'token_estimate' => 4,
                'occurred_at' => now(),
                'metadata' => [],
            ]);
        }

        $service = app(AiCompactionService::class);
        $first = $service->maybeAutoCompact($thread, $session);
        $second = $service->maybeAutoCompact($thread->refresh(), $session);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, AiCompaction::query()->where('thread_id', $thread->id)->count());
        $this->assertSame(3, $first->source_position_end);
    }

    public function test_compaction_tracks_protected_skill_content_without_summarizing_it_as_turns(): void
    {
        $thread = AiThread::query()->create([
            'title' => 'Thread com skill ativa',
            'status' => 'active',
            'surface' => 'atlas_cli',
            'metadata' => [],
        ]);

        $session = AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'purpose' => 'Compactar skill',
            'started_at' => now(),
            'metadata' => [],
        ]);

        foreach (range(1, 4) as $position) {
            AiMessage::query()->create([
                'thread_id' => $thread->id,
                'position' => $position,
                'role' => $position % 2 === 0 ? 'assistant' : 'user',
                'status' => 'final',
                'content' => $position === 2
                    ? '<skill_content name="code-reviewer">body interno da skill</skill_content>'
                    : "mensagem normal {$position}",
                'token_estimate' => 5,
                'occurred_at' => now(),
                'metadata' => [],
            ]);
        }

        $compaction = app(AiCompactionService::class)->compact($thread, $session, 'manual');

        $this->assertSame(3, $compaction->source_message_count);
        $this->assertSame(1, $compaction->metadata['protected_skill_message_count']);
        $this->assertSame(['code-reviewer'], $compaction->metadata['protected_skill_names']);
        $this->assertSame(['code-reviewer'], $compaction->structured_state['protected_skill_context']['skill_names']);
        $this->assertStringNotContainsString('body interno da skill', $compaction->summary);
    }

    private function createAiRuntimeTables(): void
    {
        $this->dropAiRuntimeTables();

        Schema::create('ai_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('title');
            $table->text('summary')->nullable();
            $table->string('status')->default('active');
            $table->string('surface')->default('app');
            $table->string('workspace')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('last_trace_id')->nullable();
            $table->string('last_provider')->nullable();
            $table->integer('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->string('status')->default('active');
            $table->text('purpose')->nullable();
            $table->string('provider_primary')->nullable();
            $table->string('provider_last')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('message_count')->default(0);
            $table->integer('token_estimate')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('trace_id')->nullable();
            $table->integer('position');
            $table->string('role');
            $table->string('status')->default('final');
            $table->text('content');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('agent_slug')->nullable();
            $table->integer('token_estimate')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_session_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('session_id')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('active')->default(true);
            $table->text('objective')->nullable();
            $table->text('current_phase')->nullable();
            $table->text('current_topic')->nullable();
            $table->text('user_position')->nullable();
            $table->json('decisions')->nullable();
            $table->json('open_loops')->nullable();
            $table->json('next_steps')->nullable();
            $table->json('relevant_artifacts')->nullable();
            $table->json('constraints')->nullable();
            $table->json('provider_context')->nullable();
            $table->json('quality_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_compactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('session_id')->nullable();
            $table->string('reason');
            $table->integer('source_position_start')->nullable();
            $table->integer('source_position_end')->nullable();
            $table->integer('source_message_count')->default(0);
            $table->text('summary');
            $table->json('structured_state')->nullable();
            $table->integer('token_estimate_before')->nullable();
            $table->integer('token_estimate_after')->nullable();
            $table->string('quality_gate_status')->default('passed');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_provider_handoffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('session_id')->nullable();
            $table->string('from_provider')->nullable();
            $table->string('to_provider');
            $table->string('reason')->default('provider_switch');
            $table->text('brief_text');
            $table->json('brief_json')->nullable();
            $table->uuid('compaction_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

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
            $table->smallInteger('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('client_id')->nullable();
            $table->string('kind')->default('interaction');
            $table->string('status')->default('queued');
            $table->smallInteger('priority')->default(50);
            $table->string('agent_slug');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('input_text');
            $table->text('prompt');
            $table->json('context_refs')->nullable();
            $table->json('payload')->nullable();
            $table->text('result_text')->nullable();
            $table->json('result_json')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(2);
            $table->integer('timeout_seconds')->default(300);
            $table->string('worker_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_context_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->json('context_pack')->nullable();
            $table->json('messages_included')->nullable();
            $table->uuid('compaction_id')->nullable();
            $table->uuid('provider_handoff_id')->nullable();
            $table->integer('token_estimate')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function dropAiRuntimeTables(): void
    {
        foreach ([
            'atlas_open_brain_access_logs',
            'ai_context_snapshots',
            'ai_jobs',
            'ai_traces',
            'ai_provider_handoffs',
            'ai_compactions',
            'ai_session_states',
            'ai_messages',
            'ai_sessions',
            'ai_threads',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createOpenBrainAuditTable(): void
    {
        Schema::dropIfExists('atlas_open_brain_access_logs');

        $migration = require database_path('migrations/2026_05_03_130000_create_atlas_open_brain_access_logs_table.php');
        $migration->up();
    }
}
