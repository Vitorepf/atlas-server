<?php

namespace Tests\Unit;

use App\Models\AiCompaction;
use App\Models\AiMessage;
use App\Models\AiThread;
use App\Services\Ai\Context\AiConversationContextBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAiThreadsTable;
use Tests\TestCase;

class AiConversationContextBuilderTest extends TestCase
{
    use CreatesAiThreadsTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAiContextTables();
    }

    protected function tearDown(): void
    {
        $this->dropAiContextTables();

        parent::tearDown();
    }

    public function test_context_window_only_includes_messages_after_latest_compaction(): void
    {
        config(['atlas.ai.context_recent_turn_limit' => 10]);

        $thread = AiThread::query()->create([
            'title' => 'Thread compactada',
            'summary' => 'Resumo preservado pela compactacao.',
            'status' => 'active',
            'surface' => 'atlas_ai_sheet',
            'metadata' => [],
        ]);

        foreach (range(1, 5) as $position) {
            AiMessage::query()->create([
                'thread_id' => $thread->id,
                'position' => $position,
                'role' => $position % 2 === 0 ? 'assistant' : 'user',
                'status' => 'final',
                'content' => "turno {$position}",
                'token_estimate' => 3,
                'occurred_at' => now(),
                'metadata' => [],
            ]);
        }

        AiCompaction::query()->create([
            'thread_id' => $thread->id,
            'reason' => 'auto',
            'source_position_start' => 1,
            'source_position_end' => 3,
            'source_message_count' => 3,
            'summary' => 'Resumo ate o turno 3.',
            'structured_state' => [],
            'quality_gate_status' => 'passed',
            'metadata' => [],
        ]);

        $context = app(AiConversationContextBuilder::class)->build([
            'thread_id' => $thread->id,
        ]);

        $this->assertSame('ai_messages_after_compaction', $context['source']);
        $this->assertSame('after_latest_compaction', $context['context_window']['mode']);
        $this->assertSame(3, $context['context_window']['compacted_through_position']);
        $this->assertSame([4, 5], collect($context['recent_turns'])->pluck('position')->all());
        $this->assertStringContainsString('Resumo ate o turno 3.', $context['latest_compaction']['summary']);
        $this->assertNotContains('turno 2', collect($context['recent_turns'])->pluck('text')->all());
    }

    public function test_compaction_only_source_when_no_messages_exist_after_compaction(): void
    {
        $thread = AiThread::query()->create([
            'title' => 'Thread so com compactacao',
            'summary' => 'Resumo suficiente.',
            'status' => 'active',
            'surface' => 'atlas_ai_sheet',
            'metadata' => [],
        ]);

        AiMessage::query()->create([
            'thread_id' => $thread->id,
            'position' => 1,
            'role' => 'user',
            'status' => 'final',
            'content' => 'mensagem ja compactada',
            'token_estimate' => 5,
            'occurred_at' => now(),
            'metadata' => [],
        ]);

        AiCompaction::query()->create([
            'thread_id' => $thread->id,
            'reason' => 'session_resume',
            'source_position_start' => 1,
            'source_position_end' => 1,
            'source_message_count' => 1,
            'summary' => 'Resumo da sessao anterior.',
            'structured_state' => [],
            'quality_gate_status' => 'passed',
            'metadata' => [],
        ]);

        $context = app(AiConversationContextBuilder::class)->build([
            'thread_id' => $thread->id,
        ]);

        $this->assertSame('latest_compaction_only', $context['source']);
        $this->assertSame([], $context['recent_turns']);
        $this->assertSame(0, $context['context_window']['messages_included']);
        $this->assertSame(1, $context['context_window']['compacted_through_position']);
    }

    public function test_rehydratable_turn_refs_compact_recent_turns_without_changing_default_shape(): void
    {
        $thread = AiThread::query()->create([
            'title' => 'Thread com turn refs',
            'summary' => null,
            'status' => 'active',
            'surface' => 'atlas_cli',
            'metadata' => [],
        ]);
        $message = AiMessage::query()->create([
            'thread_id' => $thread->id,
            'position' => 1,
            'role' => 'user',
            'status' => 'final',
            'content' => str_repeat('conteudo recuperavel ', 120),
            'token_estimate' => 400,
            'occurred_at' => now(),
            'metadata' => [],
        ]);

        $default = app(AiConversationContextBuilder::class)->build([
            'thread_id' => $thread->id,
        ]);
        $rehydratable = app(AiConversationContextBuilder::class)->build([
            'thread_id' => $thread->id,
            'rehydratable_turn_refs' => true,
        ]);

        $this->assertArrayNotHasKey('turn_ref', $default['recent_turns'][0]);
        $this->assertStringContainsString('conteudo recuperavel conteudo recuperavel', $default['recent_turns'][0]['text']);
        $this->assertSame('turn:'.$message->id, $rehydratable['recent_turns'][0]['turn_ref']);
        $this->assertSame('rehydratable_turn_ref', $rehydratable['recent_turns'][0]['compression']);
        $this->assertLessThan(mb_strlen($default['recent_turns'][0]['text']), mb_strlen($rehydratable['recent_turns'][0]['text']));
        $this->assertStringStartsWith('rehydrate turn:'.$message->id.' from canonical sources', $rehydratable['recent_turns'][0]['recovery_query']);
    }

    private function createAiContextTables(): void
    {
        $this->dropAiContextTables();

        $this->createAiThreadsTable();

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
    }

    private function dropAiContextTables(): void
    {
        foreach ([
            'ai_provider_handoffs',
            'ai_compactions',
            'ai_session_states',
            'ai_messages',
            'ai_threads',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
