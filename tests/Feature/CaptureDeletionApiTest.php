<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CaptureDeletionApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        Storage::fake('atlas');
        $this->dropTables();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_delete_capture_hard_purges_content_file_and_learning_surfaces(): void
    {
        $captureId = (string) Str::uuid();
        $clientId = (string) Str::uuid();
        $contextBundleId = (string) Str::uuid();
        $filePath = 'audio/delete-test.m4a';

        Storage::disk('atlas')->put($filePath, 'audio-bytes');

        DB::table('captures')->insert([
            'id' => $captureId,
            'client_id' => $clientId,
            'kind' => 'text',
            'domain' => 'outro',
            'content_text' => 'comprar pao',
            'content_file_path' => $filePath,
            'captured_at' => now(),
            'captured_timezone' => 'America/Sao_Paulo',
            'metadata' => json_encode(['raw' => 'capture']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transcription_jobs')->insert([
            'id' => (string) Str::uuid(),
            'capture_id' => $captureId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('capture_links')->insert([
            'id' => (string) Str::uuid(),
            'capture_id' => $captureId,
            'target_type' => 'semantic_note',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('semantic_curation_proposals')->insert([
            'id' => (string) Str::uuid(),
            'source_refs' => json_encode(['capture_id' => $captureId, 'capture_client_id' => $clientId]),
            'proposed_body' => 'conteudo vindo da captura comprar pao',
            'proposed_summary' => 'comprar pao',
            'reason' => 'from capture',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_context_bundles')->insert([
            'id' => $contextBundleId,
            'source_refs' => json_encode([['capture_id' => $captureId]]),
            'trace_refs' => json_encode([]),
            'job_refs' => json_encode([]),
            'metric_refs' => json_encode([]),
            'file_refs' => json_encode([$filePath]),
            'raw_payload' => json_encode(['capture_client_id' => $clientId]),
            'body_for_thread' => 'contexto de comprar pao',
            'summary' => 'comprar pao',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_inbox_items')->insert([
            'id' => (string) Str::uuid(),
            'source_id' => $captureId,
            'context_bundle_id' => $contextBundleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('atlas_memory_entry_usages')->insert([
            'id' => (string) Str::uuid(),
            'source_ref_json' => json_encode(['capture_id' => $captureId]),
            'context_payload_json' => json_encode(['content' => 'comprar pao']),
            'metadata' => json_encode([]),
            'included_reason' => 'capture context',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('atlas_memory_entries')->insert([
            'id' => (string) Str::uuid(),
            'source_id' => $captureId,
            'body' => 'comprar pao',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['atlas_tasks', 'atlas_projects', 'atlas_routines', 'behavior_logs'] as $table) {
            DB::table($table)->insert([
                'id' => (string) Str::uuid(),
                'source_capture_id' => $captureId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('digital_sessions')->insert([
            'id' => (string) Str::uuid(),
            'linked_capture_id' => $captureId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/captures/{$captureId}", [], $this->headers)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted_capture_id', $captureId)
            ->assertJsonPath('deletion.content_purged', true)
            ->assertJsonPath('deletion.file_deleted', true)
            ->assertJsonPath('deletion.counts.transcription_jobs', 1)
            ->assertJsonPath('deletion.counts.capture_links', 1)
            ->assertJsonPath('deletion.counts.semantic_curation_proposals', 1)
            ->assertJsonPath('deletion.counts.context_bundles', 1)
            ->assertJsonPath('deletion.counts.inbox_items', 1)
            ->assertJsonPath('deletion.counts.memory_usages', 1)
            ->assertJsonPath('deletion.counts.memory_entries', 1);

        $this->assertDatabaseMissing('captures', ['id' => $captureId]);
        $this->assertDatabaseMissing('transcription_jobs', ['capture_id' => $captureId]);
        $this->assertDatabaseMissing('capture_links', ['capture_id' => $captureId]);
        $this->assertDatabaseMissing('semantic_curation_proposals', ['proposed_summary' => 'comprar pao']);
        $this->assertDatabaseMissing('ai_context_bundles', ['id' => $contextBundleId]);
        $this->assertDatabaseMissing('ai_inbox_items', ['context_bundle_id' => $contextBundleId]);
        $this->assertDatabaseMissing('atlas_memory_entries', ['source_id' => $captureId]);
        $this->assertDatabaseMissing('atlas_memory_entry_usages', ['included_reason' => 'capture context']);
        $this->assertNull(DB::table('atlas_tasks')->value('source_capture_id'));
        $this->assertNull(DB::table('atlas_projects')->value('source_capture_id'));
        $this->assertNull(DB::table('atlas_routines')->value('source_capture_id'));
        $this->assertNull(DB::table('behavior_logs')->value('source_capture_id'));
        $this->assertNull(DB::table('digital_sessions')->value('linked_capture_id'));
        Storage::disk('atlas')->assertMissing($filePath);
    }

    private function createTables(): void
    {
        Schema::create('captures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('client_id')->unique();
            $table->string('kind');
            $table->string('domain');
            $table->text('content_text')->nullable();
            $table->text('content_file_path')->nullable();
            $table->timestamp('captured_at');
            $table->string('captured_timezone');
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transcription_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('capture_id');
            $table->timestamps();
        });

        Schema::create('capture_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('capture_id');
            $table->string('target_type');
            $table->timestamps();
        });

        Schema::create('semantic_curation_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->json('source_refs')->default('{}');
            $table->text('proposed_body')->nullable();
            $table->text('proposed_summary')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_context_bundles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->json('source_refs')->default('[]');
            $table->json('trace_refs')->default('[]');
            $table->json('job_refs')->default('[]');
            $table->json('metric_refs')->default('[]');
            $table->json('file_refs')->default('[]');
            $table->json('raw_payload')->default('{}');
            $table->text('body_for_thread')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_inbox_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_id')->nullable();
            $table->uuid('context_bundle_id')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_memory_entry_usages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->json('source_ref_json')->default('{}');
            $table->json('context_payload_json')->default('{}');
            $table->json('metadata')->default('{}');
            $table->text('included_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_memory_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_id')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
        });

        foreach (['atlas_tasks', 'atlas_projects', 'atlas_routines', 'behavior_logs'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('source_capture_id')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('digital_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('linked_capture_id')->nullable();
            $table->timestamps();
        });
    }

    private function dropTables(): void
    {
        foreach ([
            'digital_sessions',
            'behavior_logs',
            'atlas_routines',
            'atlas_projects',
            'atlas_tasks',
            'atlas_memory_entries',
            'atlas_memory_entry_usages',
            'ai_inbox_items',
            'ai_context_bundles',
            'semantic_curation_proposals',
            'capture_links',
            'transcription_jobs',
            'captures',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
