<?php

namespace Tests\Feature\Ai;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasAiCaptureInboxPipelineReportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['capture_links', 'ai_inbox_items', 'ai_memory_deltas', 'semantic_curation_proposals', 'captures'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createTables();
    }

    protected function tearDown(): void
    {
        foreach (['capture_links', 'ai_inbox_items', 'ai_memory_deltas', 'semantic_curation_proposals', 'captures'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_command_reports_healthy_capture_inbox_pipeline_without_writes(): void
    {
        $captureId = (string) Str::uuid();
        $proposalId = (string) Str::uuid();

        DB::table('captures')->insert([
            'id' => $captureId,
            'client_id' => (string) Str::uuid(),
            'kind' => 'text',
            'domain' => 'outro',
            'content_text' => 'A useful capture',
            'transcription_status' => 'na',
            'captured_at' => now()->subHour(),
            'captured_timezone' => 'UTC',
            'metadata' => json_encode([
                'cognitive_quarantine' => $this->quarantine(),
                'content_intelligence' => ['schema_version' => 'atlas.capture.content_intelligence.v1'],
                'semantic_curation' => ['proposal_id' => $proposalId, 'status' => 'proposal_pending'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        DB::table('semantic_curation_proposals')->insert([
            'id' => $proposalId,
            'source_type' => 'capture',
            'source_refs' => json_encode(['capture_id' => $captureId], JSON_THROW_ON_ERROR),
            'proposed_note_type' => 'synthesis',
            'proposed_title' => 'Useful capture',
            'proposed_summary' => 'Useful capture',
            'score' => 0.8,
            'reason' => 'review',
            'status' => 'pending',
            'metadata' => json_encode(['cognitive_quarantine' => $this->quarantine()], JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);
        DB::table('ai_memory_deltas')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'technical_context',
            'claim' => 'Candidate memory',
            'evidence' => json_encode([], JSON_THROW_ON_ERROR),
            'scope' => 'capture:'.$captureId,
            'confidence' => 0.8,
            'requires_confirmation' => true,
            'status' => 'pending',
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ]);
        DB::table('ai_inbox_items')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => 'vitor',
            'type' => 'proposal',
            'severity' => 'info',
            'status' => 'unread',
            'title' => 'Review proposal',
            'source_type' => 'semantic_curation_proposal',
            'source_id' => $proposalId,
            'available_actions' => json_encode([], JSON_THROW_ON_ERROR),
            'payload' => json_encode([], JSON_THROW_ON_ERROR),
            'push_policy' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);
        DB::table('capture_links')->insert([
            'id' => (string) Str::uuid(),
            'capture_id' => $captureId,
            'target_type' => 'semantic_curation_proposal',
            'target_id' => $proposalId,
            'relation_type' => 'triage_destination',
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $exit = Artisan::call('atlas:ai:capture-inbox-pipeline-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.capture_inbox_pipeline_report.v1', data_get($payload, 'capture_inbox_pipeline.schema_version'));
        $this->assertFalse(data_get($payload, 'capture_inbox_pipeline.writes'));
        $this->assertSame(1, data_get($payload, 'capture_inbox_pipeline.capture_count'));
        $this->assertSame(1, data_get($payload, 'capture_inbox_pipeline.quarantined_capture_count'));
        $this->assertSame(0, data_get($payload, 'capture_inbox_pipeline.unsafe_capture_count'));
        $this->assertSame(1, data_get($payload, 'capture_inbox_pipeline.pending_proposal_count'));
        $this->assertSame(1, data_get($payload, 'capture_inbox_pipeline.pending_memory_delta_count'));
        $this->assertSame('atlas.capture_inbox_pipeline.promotion_gate.v1', data_get($payload, 'capture_inbox_pipeline.promotion_gate.schema_version'));
        $this->assertSame('review_required', data_get($payload, 'capture_inbox_pipeline.promotion_gate.status'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'capture_inbox_pipeline.promotion_gate.gate_hash'));
        $this->assertFalse(data_get($payload, 'capture_inbox_pipeline.promotion_gate.memory_write_allowed_by_report'));
        $this->assertFalse(data_get($payload, 'capture_inbox_pipeline.promotion_gate.context_injection_allowed_by_report'));
        $this->assertFalse(data_get($payload, 'capture_inbox_pipeline.promotion_gate.embedding_allowed_by_report'));
        $this->assertFalse(data_get($payload, 'capture_inbox_pipeline.promotion_gate.provider_export_allowed_by_report'));
        $this->assertTrue(data_get($payload, 'capture_inbox_pipeline.promotion_gate.operator_review_required'));
        $this->assertContains('promotion_receipt_hash', data_get($payload, 'capture_inbox_pipeline.promotion_gate.required_before_memory_or_context_promotion'));
        $this->assertSame('continue_capture_inbox_pipeline_monitoring', data_get($payload, 'capture_inbox_pipeline.review_signal.recommended_action'));
    }

    public function test_command_warns_when_recent_capture_lacks_contracts_and_has_broken_lineage(): void
    {
        DB::table('captures')->insert([
            'id' => (string) Str::uuid(),
            'client_id' => (string) Str::uuid(),
            'kind' => 'text',
            'domain' => 'outro',
            'content_text' => 'Legacy capture',
            'transcription_status' => 'na',
            'captured_at' => now()->subHour(),
            'captured_timezone' => 'UTC',
            'metadata' => json_encode([
                'semantic_curation' => ['proposal_id' => (string) Str::uuid()],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $exit = Artisan::call('atlas:ai:capture-inbox-pipeline-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', $payload['status']);
        $this->assertSame(1, data_get($payload, 'capture_inbox_pipeline.missing_quarantine_count'));
        $this->assertSame(1, data_get($payload, 'capture_inbox_pipeline.missing_content_intelligence_count'));
        $this->assertSame(1, data_get($payload, 'capture_inbox_pipeline.broken_semantic_curation_ref_count'));
        $this->assertSame('review_capture_inbox_pipeline_gaps_before_memory_promotion', data_get($payload, 'capture_inbox_pipeline.review_signal.recommended_action'));
    }

    public function test_command_fails_closed_when_capture_storage_is_missing(): void
    {
        Schema::dropIfExists('captures');

        $exit = Artisan::call('atlas:ai:capture-inbox-pipeline-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('storage_unavailable', $payload['status']);
        $this->assertSame('run_migrations_before_capture_pipeline_review', data_get($payload, 'capture_inbox_pipeline.review_signal.recommended_action'));
    }

    public function test_backfill_command_repairs_legacy_capture_contracts_only_when_write_is_enabled(): void
    {
        $captureId = (string) Str::uuid();
        $proposalId = (string) Str::uuid();

        DB::table('captures')->insert([
            'id' => $captureId,
            'client_id' => (string) Str::uuid(),
            'kind' => 'audio',
            'domain' => 'outro',
            'content_text' => null,
            'content_sha256' => 'audiohash',
            'content_mime_type' => 'audio/wav',
            'content_duration_ms' => 1200,
            'content_size_bytes' => 42,
            'transcription_status' => 'done',
            'captured_at' => now()->subHour(),
            'captured_timezone' => 'UTC',
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        DB::table('semantic_curation_proposals')->insert([
            'id' => $proposalId,
            'source_type' => 'capture',
            'source_refs' => json_encode(['capture_id' => $captureId], JSON_THROW_ON_ERROR),
            'proposed_note_type' => 'principle',
            'proposed_title' => 'Legacy proposal',
            'proposed_summary' => 'Legacy proposal',
            'score' => 0.7,
            'reason' => 'review',
            'status' => 'pending',
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        $dryRunExit = Artisan::call('atlas:ai:capture-inbox-pipeline-backfill-contracts', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $dryRunPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $dryRunExit);
        $this->assertSame(1, data_get($dryRunPayload, 'capture_inbox_pipeline_backfill.planned_count'));
        $this->assertSame(0, data_get($dryRunPayload, 'capture_inbox_pipeline_backfill.applied_count'));
        $this->assertNull(data_get(json_decode((string) DB::table('captures')->where('id', $captureId)->value('metadata'), true), 'cognitive_quarantine'));

        $writeExit = Artisan::call('atlas:ai:capture-inbox-pipeline-backfill-contracts', [
            '--hours' => 24,
            '--write' => true,
            '--json' => true,
        ]);
        $writePayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $metadata = json_decode((string) DB::table('captures')->where('id', $captureId)->value('metadata'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $writeExit);
        $this->assertSame(1, data_get($writePayload, 'capture_inbox_pipeline_backfill.applied_count'));
        $this->assertSame('atlas.capture.cognitive_quarantine.v1', data_get($metadata, 'cognitive_quarantine.schema_version'));
        $this->assertSame('atlas.capture.content_intelligence.v1', data_get($metadata, 'content_intelligence.schema_version'));
        $this->assertSame($proposalId, data_get($metadata, 'semantic_curation.proposal_id'));
        $this->assertFalse(data_get($metadata, 'cognitive_quarantine.provider_export_allowed'));
        $this->assertFalse(data_get($metadata, 'content_intelligence.privacy.open_brain_context_allowed'));
    }

    /**
     * @return array<string,mixed>
     */
    private function quarantine(): array
    {
        return [
            'schema_version' => 'atlas.capture.cognitive_quarantine.v1',
            'memory_eligible' => false,
            'context_eligible' => false,
            'embedding_allowed' => false,
            'provider_export_allowed' => false,
            'open_brain_context_allowed' => false,
            'promotion_status' => 'unclassified',
        ];
    }

    private function createTables(): void
    {
        Schema::create('captures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('client_id')->unique();
            $table->string('kind');
            $table->string('domain');
            $table->text('content_text')->nullable();
            $table->string('content_sha256')->nullable();
            $table->string('content_mime_type')->nullable();
            $table->integer('content_duration_ms')->nullable();
            $table->integer('content_size_bytes')->nullable();
            $table->string('transcription_status')->default('na');
            $table->timestamp('captured_at');
            $table->string('captured_timezone');
            $table->json('metadata')->default('{}');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('semantic_curation_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_type');
            $table->json('source_refs');
            $table->string('proposed_note_type');
            $table->string('proposed_title');
            $table->text('proposed_summary');
            $table->float('score')->nullable();
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_memory_deltas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->text('claim');
            $table->json('evidence');
            $table->string('scope');
            $table->float('confidence')->default(0.5);
            $table->boolean('requires_confirmation')->default(true);
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('ai_inbox_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor');
            $table->string('type');
            $table->string('category')->nullable();
            $table->string('severity')->default('info');
            $table->string('status')->default('unread');
            $table->string('title');
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->json('available_actions')->default('[]');
            $table->json('payload')->default('{}');
            $table->json('push_policy')->default('{}');
            $table->timestamps();
        });

        Schema::create('capture_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('capture_id');
            $table->string('target_type');
            $table->uuid('target_id')->nullable();
            $table->string('relation_type')->default('triage_destination');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }
}
