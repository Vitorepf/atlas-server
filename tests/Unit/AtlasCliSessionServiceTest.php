<?php

namespace Tests\Unit;

use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\AiThread;
use App\Services\Ai\Cli\AtlasCliSessionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasCliSessionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
    }

    protected function tearDown(): void
    {
        foreach ([
            'ai_provider_handoffs',
            'ai_compactions',
            'ai_session_states',
            'ai_messages',
            'ai_sessions',
            'ai_threads',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_updates_compacts_and_handoffs_cli_session_state(): void
    {
        $workspace = realpath(base_path()) ?: base_path();
        $thread = AiThread::query()->create([
            'title' => 'Implementar Atlas CLI',
            'status' => 'active',
            'surface' => 'atlas_cli',
            'workspace' => $workspace,
            'last_provider' => 'claude_cli',
            'message_count' => 2,
            'last_message_at' => now(),
            'metadata' => [],
        ]);
        $session = AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'provider_primary' => 'claude_cli',
            'provider_last' => 'claude_cli',
            'started_at' => now(),
            'metadata' => [],
        ]);
        AiMessage::query()->create([
            'thread_id' => $thread->id,
            'position' => 1,
            'role' => 'user',
            'status' => 'final',
            'content' => 'Objetivo: criar sessao longa profissional.',
            'token_estimate' => 12,
            'occurred_at' => now(),
            'metadata' => [],
        ]);
        AiMessage::query()->create([
            'thread_id' => $thread->id,
            'position' => 2,
            'role' => 'assistant',
            'status' => 'final',
            'content' => 'Proximo passo: implementar state command.',
            'provider' => 'claude_cli',
            'token_estimate' => 10,
            'occurred_at' => now(),
            'metadata' => [],
        ]);

        $service = app(AtlasCliSessionService::class);
        $updated = $service->update($workspace, $thread->id, [
            'objective' => 'Atlas CLI estado da arte',
            'phase' => 'session_state',
            'next_steps' => ['Criar comando /state'],
            'notes' => ['O terminal e o produto principal no Mac.'],
        ]);

        $this->assertSame($session->id, $updated['session']['id']);
        $this->assertSame('Atlas CLI estado da arte', $updated['state']['objective']);
        $this->assertSame('session_state', $updated['state']['current_phase']);
        $this->assertSame('Criar comando /state', $updated['state']['next_steps'][0]['text']);
        $this->assertSame('O terminal e o produto principal no Mac.', $updated['state']['operator_notes'][0]['text']);

        $compacted = $service->compact($workspace, $thread->id);
        $this->assertNotNull($compacted['created_compaction']['id']);
        $this->assertStringContainsString('Atlas CLI estado da arte', $compacted['created_compaction']['summary']);

        $handoff = $service->handoff($workspace, $thread->id, 'codex_cli');
        $this->assertSame('codex_cli', $handoff['created_provider_handoff']['to_provider']);
        $this->assertStringContainsString('Atlas CLI estado da arte', $handoff['created_provider_handoff']['brief_text']);
    }

    public function test_state_command_sets_and_clears_pending_steer(): void
    {
        $workspace = realpath(base_path()) ?: base_path();
        $thread = AiThread::query()->create([
            'title' => 'Trace longo',
            'status' => 'active',
            'surface' => 'atlas_cli',
            'workspace' => $workspace,
            'metadata' => [],
        ]);
        AiSession::query()->create([
            'thread_id' => $thread->id,
            'status' => 'active',
            'started_at' => now(),
            'metadata' => [],
        ]);

        $this->artisan('atlas:cli:state', [
            'action' => 'pending-steer',
            'value' => ['set', 'foque nos testes'],
            '--workspace' => $workspace,
            '--thread' => $thread->id,
            '--json' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('ai_session_states', [
            'thread_id' => $thread->id,
            'pending_steer' => 'foque nos testes',
        ]);

        $this->artisan('atlas:cli:state', [
            'action' => 'pending-steer',
            'value' => ['clear'],
            '--workspace' => $workspace,
            '--thread' => $thread->id,
            '--json' => true,
        ])->assertSuccessful();

        $this->assertNull($thread->activeState()->first()?->pending_steer);
    }

    private function createTables(): void
    {
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
            $table->text('pending_steer')->nullable();
            $table->json('provider_context')->nullable();
            $table->json('quality_notes')->nullable();
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
    }
}
