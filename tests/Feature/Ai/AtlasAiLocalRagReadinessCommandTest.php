<?php

namespace Tests\Feature\Ai;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasAiLocalRagReadinessCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_attachment_index_entries');
        Schema::dropIfExists('semantic_notes');

        parent::tearDown();
    }

    public function test_command_blocks_when_semantic_memory_tables_are_missing(): void
    {
        Schema::dropIfExists('ai_attachment_index_entries');
        Schema::dropIfExists('semantic_notes');
        config()->set('atlas.semantic_memory.embedding_provider', 'local_hash');

        $exit = Artisan::call('atlas:ai:local-rag-readiness', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.local_rag_readiness.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('semantic_notes_table', $payload['blocking_gates']);
        $this->assertTrue(data_get($payload, 'guardrails.kernel_decides'));
        $this->assertFalse(data_get($payload, 'guardrails.provider_bypass_allowed'));
        $this->assertTrue(data_get($payload, 'guardrails.python_graph_rag_is_future_runtime_not_parallel_brain'));
    }

    public function test_command_reports_ready_when_local_rag_substrate_exists(): void
    {
        $this->createLocalRagTables();
        config()->set('atlas.semantic_memory.embedding_provider', 'local_hash');

        $exit = Artisan::call('atlas:ai:local-rag-readiness', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('local_hash', data_get($payload, 'embedding.provider'));
        $this->assertTrue(data_get($payload, 'stores.semantic_notes.table_exists'));
        $this->assertTrue(data_get($payload, 'stores.semantic_notes.embedding_column_exists'));
        $this->assertTrue(data_get($payload, 'stores.ai_attachment_index_entries.embedding_column_exists'));
        $this->assertTrue(data_get($payload, 'gates.vector_retrieval_governed'));
        $this->assertTrue(data_get($payload, 'gates.graph_retrieval_future_governed'));
        $this->assertFalse(data_get($payload, 'gates.provider_bypass_allowed'));
        $this->assertSame('run_controlled_local_rag_benchmark_before_promoting_python_graph_rag', $payload['next_action']);
    }

    private function createLocalRagTables(): void
    {
        Schema::dropIfExists('ai_attachment_index_entries');
        Schema::dropIfExists('semantic_notes');

        Schema::create('semantic_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title')->nullable();
            $table->string('type')->nullable();
            $table->string('status')->nullable();
            $table->text('summary')->nullable();
            $table->text('body_excerpt')->nullable();
            $table->json('domains')->nullable();
            $table->json('trigger_signals')->nullable();
            $table->text('embedding')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_attachment_index_entries', function (Blueprint $table): void {
            $table->id();
            $table->text('embedding')->nullable();
            $table->timestamps();
        });
    }
}
