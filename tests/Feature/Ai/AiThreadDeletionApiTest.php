<?php

namespace Tests\Feature\Ai;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiThreadDeletionApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->dropTables();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_delete_thread_purges_retrievable_content_and_keeps_trace_tombstone(): void
    {
        $threadId = (string) Str::uuid();
        $traceId = (string) Str::uuid();
        $jobId = (string) Str::uuid();
        $snapshotId = (string) Str::uuid();
        $contextBundleId = (string) Str::uuid();

        DB::table('ai_threads')->insert([
            'id' => $threadId,
            'title' => 'Comprar pao',
            'status' => 'active',
            'surface' => 'app',
            'last_trace_id' => $traceId,
            'message_count' => 1,
            'metadata' => json_encode(['mode' => 'general']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_traces')->insert([
            'id' => $traceId,
            'trace_key' => 'trace-delete-test',
            'thread_id' => $threadId,
            'source_type' => 'app',
            'status' => 'succeeded',
            'operator_input' => 'se pressa escreve com ss ou c?',
            'agent_slug' => 'atlas',
            'skill_versions' => json_encode(['runtime' => 'test']),
            'context_refs' => json_encode([['kind' => 'thread', 'id' => $threadId]]),
            'response_text' => 'Depende: pressa com ss.',
            'metadata' => json_encode(['raw' => 'content-bearing']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_messages')->insert([
            'id' => (string) Str::uuid(),
            'thread_id' => $threadId,
            'trace_id' => $traceId,
            'position' => 1,
            'role' => 'user',
            'status' => 'final',
            'content' => 'se pressa escreve com ss ou c?',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_jobs')->insert([
            'id' => $jobId,
            'trace_id' => $traceId,
            'kind' => 'interaction',
            'status' => 'succeeded',
            'priority' => 50,
            'agent_slug' => 'atlas',
            'input_text' => 'se pressa escreve com ss ou c?',
            'prompt' => 'responda a pergunta',
            'context_refs' => json_encode([]),
            'payload' => json_encode(['thread_id' => $threadId]),
            'result_text' => 'pressa',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_context_snapshots')->insert([
            'id' => $snapshotId,
            'trace_id' => $traceId,
            'thread_id' => $threadId,
            'context_pack' => json_encode(['messages' => ['se pressa escreve com ss ou c?']]),
            'messages_included' => json_encode([$threadId]),
            'metadata' => json_encode([]),
            'created_at' => now(),
        ]);

        DB::table('atlas_memory_entry_usages')->insert([
            'id' => (string) Str::uuid(),
            'memory_entry_id' => (string) Str::uuid(),
            'trace_id' => $traceId,
            'context_snapshot_id' => $snapshotId,
            'thread_id' => $threadId,
            'memory_type' => 'conversation',
            'scope_type' => 'thread',
            'source_type' => 'context_pack',
            'included_reason' => 'thread context',
            'source_ref_json' => json_encode([]),
            'context_payload_json' => json_encode(['content' => 'pressa']),
            'metadata' => json_encode([]),
            'used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_attachment_index_entries')->insert([
            'id' => (string) Str::uuid(),
            'trace_id' => $traceId,
            'ai_job_id' => $jobId,
            'thread_id' => $threadId,
            'attachment_id' => 'thread-note',
            'attachment_kind' => 'text',
            'unit_type' => 'message',
            'excerpt' => 'se pressa escreve com ss ou c?',
            'metadata' => json_encode([]),
            'content_hash' => 'delete-test-hash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_context_bundles')->insert([
            'id' => $contextBundleId,
            'body_for_thread' => 'Contexto montado a partir da conversa '.$threadId,
            'summary' => 'Resumo com conteudo da conversa',
            'source_refs' => json_encode([['thread_id' => $threadId]]),
            'trace_refs' => json_encode([$traceId]),
            'job_refs' => json_encode([$jobId]),
            'metric_refs' => json_encode([]),
            'raw_payload' => json_encode(['snapshot_id' => $snapshotId]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_inbox_items')->insert([
            'id' => (string) Str::uuid(),
            'title' => 'Review da conversa',
            'source_id' => $threadId,
            'context_bundle_id' => $contextBundleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/ai/threads/{$threadId}", [], $this->headers)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted_thread_id', $threadId)
            ->assertJsonPath('deletion.content_purged', true)
            ->assertJsonPath('deletion.traces_tombstoned', 1)
            ->assertJsonPath('deletion.counts.messages', 1)
            ->assertJsonPath('deletion.counts.jobs', 1)
            ->assertJsonPath('deletion.counts.context_snapshots', 1)
            ->assertJsonPath('deletion.counts.memory_usages', 1)
            ->assertJsonPath('deletion.counts.attachment_index_entries', 1)
            ->assertJsonPath('deletion.counts.context_bundles', 1)
            ->assertJsonPath('deletion.counts.inbox_items', 1);

        $this->assertDatabaseMissing('ai_threads', ['id' => $threadId]);
        $this->assertDatabaseMissing('ai_messages', ['thread_id' => $threadId]);
        $this->assertDatabaseMissing('ai_jobs', ['id' => $jobId]);
        $this->assertDatabaseMissing('ai_context_snapshots', ['id' => $snapshotId]);
        $this->assertDatabaseMissing('atlas_memory_entry_usages', ['thread_id' => $threadId]);
        $this->assertDatabaseMissing('ai_attachment_index_entries', ['thread_id' => $threadId]);
        $this->assertDatabaseMissing('ai_context_bundles', ['id' => $contextBundleId]);
        $this->assertDatabaseMissing('ai_inbox_items', ['context_bundle_id' => $contextBundleId]);

        $trace = DB::table('ai_traces')->where('id', $traceId)->first();
        $this->assertNotNull($trace);
        $this->assertNull($trace->thread_id);
        $this->assertSame('system', $trace->source_type);
        $this->assertSame('[deleted thread]', $trace->operator_input);
        $this->assertNull($trace->response_text);
        $this->assertSame([], json_decode($trace->context_refs, true));
        $this->assertTrue((bool) data_get(json_decode($trace->metadata, true), 'thread_deleted'));
    }

    private function createTables(): void
    {
        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->default('app');
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('succeeded');
            $table->text('operator_input');
            $table->text('intent')->nullable();
            $table->string('agent_slug');
            $table->json('skill_versions')->default('{}');
            $table->json('context_refs')->default('[]');
            $table->text('prompt_hash')->nullable();
            $table->text('response_hash')->nullable();
            $table->text('response_text')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('title');
            $table->text('summary')->nullable();
            $table->string('status')->default('active');
            $table->string('surface')->default('app');
            $table->text('workspace')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('last_trace_id')->nullable();
            $table->string('last_provider')->nullable();
            $table->integer('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->json('metadata')->default('{}');
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
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->string('kind');
            $table->string('status');
            $table->integer('priority');
            $table->string('agent_slug');
            $table->text('input_text');
            $table->text('prompt');
            $table->json('context_refs')->default('[]');
            $table->json('payload')->default('{}');
            $table->text('result_text')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_context_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->json('context_pack')->default('{}');
            $table->json('messages_included')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('atlas_memory_entry_usages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('memory_entry_id');
            $table->uuid('trace_id')->nullable();
            $table->uuid('context_snapshot_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('memory_type');
            $table->string('scope_type');
            $table->string('source_type');
            $table->text('included_reason')->nullable();
            $table->json('source_ref_json')->default('{}');
            $table->json('context_payload_json')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_attachment_index_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('ai_job_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->text('attachment_id');
            $table->text('attachment_kind');
            $table->text('unit_type');
            $table->text('excerpt')->default('');
            $table->json('metadata')->default('{}');
            $table->text('content_hash')->unique();
            $table->timestamps();
        });

        Schema::create('ai_context_bundles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('body_for_thread');
            $table->text('summary');
            $table->json('source_refs')->default('[]');
            $table->json('trace_refs')->default('[]');
            $table->json('job_refs')->default('[]');
            $table->json('metric_refs')->default('[]');
            $table->json('raw_payload')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_inbox_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('title');
            $table->uuid('source_id')->nullable();
            $table->uuid('context_bundle_id')->nullable();
            $table->timestamps();
        });
    }

    private function dropTables(): void
    {
        foreach ([
            'ai_inbox_items',
            'ai_context_bundles',
            'ai_attachment_index_entries',
            'atlas_memory_entry_usages',
            'ai_context_snapshots',
            'ai_jobs',
            'ai_messages',
            'ai_threads',
            'ai_traces',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
