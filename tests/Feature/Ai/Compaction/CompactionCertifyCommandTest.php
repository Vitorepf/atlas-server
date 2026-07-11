<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compaction;

use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

final class CompactionCertifyCommandTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLongHorizonPersistenceTables();
        $this->createCompactionEventTables();

        Storage::fake('local');
        config([
            'atlas.context_budget.must_keep_allocator_enabled' => true,
            'atlas.context_budget.must_keep_allocator_shadow_disk' => 'local',
            'atlas.context_budget.must_keep_allocator_shadow_path' => 'atlas/context-budget/must-keep-shadow.jsonl',
            'atlas.long_horizon.cross_week_recall_lift_gate.scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_LONG_HORIZON,
            'atlas.long_horizon.cross_week_recall_lift_gate.scope_id' => 'fable-lista-6',
            'atlas.long_horizon.cross_week_recall_lift_gate.min_recall_age_days' => 1,
            'atlas.long_horizon.cross_week_recall_lift_gate.recent_window_days' => 1,
            'atlas.long_horizon.cross_week_recall_lift_gate.min_calendar_span_days' => 1,
            'atlas.long_horizon.cross_week_recall_lift_gate.min_recall_events' => 1,
            'atlas.long_horizon.cross_week_recall_lift_gate.min_new_tasks' => 2,
            'atlas.long_horizon.cross_week_recall_lift_gate.min_recall_lift' => 0.01,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropCompactionEventTables();
        $this->dropLongHorizonPersistenceTables();

        parent::tearDown();
    }

    public function test_empty_sources_report_pending_and_never_certify_vacuously(): void
    {
        [$exit, $payload] = $this->runCertify();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertFalse($payload['certified']);
        $this->assertSame('pending', $payload['status']);
        $this->assertSame('insufficient_sample', data_get($payload, 'mechanisms.conversation.status'));
        $this->assertSame('insufficient_sample', data_get($payload, 'mechanisms.handoff.status'));
        $this->assertSame('insufficient_sample', data_get($payload, 'mechanisms.long_horizon.status'));
        $this->assertSame('insufficient_sample', data_get($payload, 'mechanisms.payload.status'));
        $this->assertContains('zero_denominator:conversation', $payload['blocking']);
        $this->assertContains('zero_denominator:payload', $payload['blocking']);
    }

    public function test_real_fixture_sources_certify_when_every_mechanism_matches_receipts_and_soak_is_ready(): void
    {
        $this->writeShadowEvidence();

        $compactionId = (string) Str::uuid();
        $this->insertAiCompaction($compactionId, ['long_horizon_compaction_receipt_hash' => 'conversation-hash']);
        $this->receipt(
            scopeType: AtlasLongHorizonCanon::SCOPE_TYPE_CONVERSATION,
            scopeId: (string) Str::uuid(),
            receiptHash: 'conversation-hash',
            sourceRefs: ['ai_compaction:'.$compactionId],
        );

        $handoffId = (string) Str::uuid();
        $this->insertProviderHandoff($handoffId, ['long_horizon_compaction_receipt_hash' => 'handoff-hash']);
        $this->receipt(
            scopeType: AtlasLongHorizonCanon::SCOPE_TYPE_HANDOFF,
            scopeId: $handoffId,
            receiptHash: 'handoff-hash',
            sourceRefs: ['ai_provider_handoff:'.$handoffId],
        );

        $this->receipt(
            scopeType: AtlasLongHorizonCanon::SCOPE_TYPE_LONG_HORIZON,
            scopeId: 'mission:real',
            receiptHash: 'long-horizon-hash',
            sourceRefs: ['mission:real'],
        );
        $this->seedPassingSoakEvidence(existingReceiptCount: 3);

        [$exit, $payload] = $this->runCertify();

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertTrue($payload['certified']);
        $this->assertSame('certified', $payload['status']);
        $this->assertSame('pass', data_get($payload, 'mechanisms.conversation.status'));
        $this->assertSame('pass', data_get($payload, 'mechanisms.handoff.status'));
        $this->assertSame('pass', data_get($payload, 'mechanisms.long_horizon.status'));
        $this->assertSame('pass', data_get($payload, 'mechanisms.payload.status'));
        $this->assertSame('pass', data_get($payload, 'checks.coverage.status'));
        $this->assertSame('pass', data_get($payload, 'checks.context_retention_score.status'));
        $this->assertSame('pass', data_get($payload, 'checks.cpt_08_shadow_evidence.status'));
        $this->assertSame('pass', data_get($payload, 'checks.cpt_09_soak.status'));
    }

    public function test_fixtures_fail_when_source_event_has_no_matching_receipt(): void
    {
        $this->writeShadowEvidence();
        $this->seedPassingHandoffAndLongHorizon();
        $this->seedPassingSoakEvidence(existingReceiptCount: 2);

        $this->insertAiCompaction((string) Str::uuid());

        [$exit, $payload] = $this->runCertify();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertFalse($payload['certified']);
        $this->assertSame('failed', $payload['status']);
        $this->assertSame('fail', data_get($payload, 'mechanisms.conversation.status'));
        $this->assertSame(1, data_get($payload, 'mechanisms.conversation.unmatched_event_count'));
        $this->assertContains('event_receipt_mismatch:conversation', $payload['blocking']);
    }

    public function test_zero_denominator_in_one_mechanism_blocks_certification_even_when_other_evidence_is_ready(): void
    {
        $this->writeShadowEvidence();
        $this->seedPassingHandoffAndLongHorizon();
        $this->seedPassingSoakEvidence(existingReceiptCount: 2);

        [$exit, $payload] = $this->runCertify();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertFalse($payload['certified']);
        $this->assertSame('pending', $payload['status']);
        $this->assertSame('insufficient_sample', data_get($payload, 'mechanisms.conversation.status'));
        $this->assertSame(0, data_get($payload, 'mechanisms.conversation.event_count'));
        $this->assertContains('zero_denominator:conversation', $payload['blocking']);
    }

    public function test_source_none_receipts_do_not_count_as_coverage_proof(): void
    {
        $this->writeShadowEvidence();

        $compactionId = (string) Str::uuid();
        $this->insertAiCompaction($compactionId, ['long_horizon_compaction_receipt_hash' => 'vacuous-hash']);
        $this->receipt(
            scopeType: AtlasLongHorizonCanon::SCOPE_TYPE_CONVERSATION,
            scopeId: (string) Str::uuid(),
            receiptHash: 'vacuous-hash',
            sourceRefs: ['ai_compaction:'.$compactionId],
            mustKeepItems: [],
        );

        [$exit, $payload] = $this->runCertify();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertFalse($payload['certified']);
        $this->assertSame('insufficient_sample', data_get($payload, 'checks.coverage.status'));
        $this->assertSame(0, data_get($payload, 'checks.coverage.proven_receipt_count'));
        $this->assertContains('coverage_zero_denominator', $payload['blocking']);
    }

    /**
     * @return array{0:int,1:array<string,mixed>}
     */
    private function runCertify(): array
    {
        $exit = Artisan::call('atlas:compaction:certify', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        return [$exit, $payload];
    }

    private function seedPassingHandoffAndLongHorizon(): void
    {
        $handoffId = (string) Str::uuid();
        $this->insertProviderHandoff($handoffId, ['long_horizon_compaction_receipt_hash' => 'handoff-hash']);
        $this->receipt(
            scopeType: AtlasLongHorizonCanon::SCOPE_TYPE_HANDOFF,
            scopeId: $handoffId,
            receiptHash: 'handoff-hash',
            sourceRefs: ['ai_provider_handoff:'.$handoffId],
        );
        $this->receipt(
            scopeType: AtlasLongHorizonCanon::SCOPE_TYPE_LONG_HORIZON,
            scopeId: 'mission:real',
            receiptHash: 'long-horizon-hash',
            sourceRefs: ['mission:real'],
        );
    }

    private function seedPassingSoakEvidence(int $existingReceiptCount): void
    {
        $targetReceiptCount = 50;
        for ($i = $existingReceiptCount; $i < $targetReceiptCount; $i++) {
            $this->receipt(
                scopeType: AtlasLongHorizonCanon::SCOPE_TYPE_LONG_HORIZON,
                scopeId: 'soak:'.$i,
                receiptHash: 'soak-hash-'.$i,
                sourceRefs: ['soak:'.$i],
            );
        }

        $this->insertContinuationPack(
            uuid: 'old-pack',
            evidenceRefs: ['memory:old'],
            safeResumeMode: AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            createdAt: now()->subDays(2),
        );
        $this->insertContinuationPack(
            uuid: 'new-recall-pack',
            evidenceRefs: ['memory:old'],
            safeResumeMode: AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            createdAt: now(),
        );
        $this->insertContinuationPack(
            uuid: 'new-control-pack',
            evidenceRefs: ['memory:new'],
            safeResumeMode: AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY,
            createdAt: now(),
        );
    }

    private function writeShadowEvidence(): void
    {
        Storage::disk('local')->put('atlas/context-budget/must-keep-shadow.jsonl', json_encode([
            'schema_version' => 'atlas.context.must_keep_budget_allocation.shadow.v1',
            'generated_at' => now()->toIso8601String(),
            'token_economy_hash' => 'token-economy-hash',
            'allocation' => [
                'schema_version' => 'atlas.context.must_keep_budget_allocation.v1',
                'must_keep_coverage' => 1.0,
                'blockers' => [],
            ],
        ], JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function insertAiCompaction(string $id, array $metadata = []): void
    {
        \DB::table('ai_compactions')->insert([
            'id' => $id,
            'thread_id' => (string) Str::uuid(),
            'session_id' => null,
            'reason' => 'test',
            'source_position_start' => null,
            'source_position_end' => null,
            'source_message_count' => 1,
            'summary' => 'summary',
            'structured_state' => json_encode([], JSON_THROW_ON_ERROR),
            'token_estimate_before' => 100,
            'token_estimate_after' => 50,
            'quality_gate_status' => 'passed',
            'provider' => 'local',
            'model' => 'test',
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $evidenceRefs
     */
    private function insertContinuationPack(string $uuid, array $evidenceRefs, string $safeResumeMode, mixed $createdAt): void
    {
        \DB::table('atlas_long_horizon_continuation_packs')->insert([
            'id' => (string) Str::uuid(),
            'schema_version' => AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION,
            'uuid' => $uuid,
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_LONG_HORIZON,
            'scope_id' => 'fable-lista-6',
            'objective' => 'Cross-week fixture',
            'current_phase' => 'test',
            'state_summary' => 'Fixture summary',
            'decisions' => json_encode([], JSON_THROW_ON_ERROR),
            'superseded_decisions' => json_encode([], JSON_THROW_ON_ERROR),
            'open_tasks' => json_encode([], JSON_THROW_ON_ERROR),
            'completed_tasks' => json_encode([], JSON_THROW_ON_ERROR),
            'blockers' => json_encode([], JSON_THROW_ON_ERROR),
            'risks' => json_encode([], JSON_THROW_ON_ERROR),
            'evidence_refs' => json_encode($evidenceRefs, JSON_THROW_ON_ERROR),
            'context_manifest' => json_encode([], JSON_THROW_ON_ERROR),
            'context_pack_hash' => null,
            'summary_hash' => hash('sha256', $uuid),
            'source_receipts' => json_encode([], JSON_THROW_ON_ERROR),
            'stale_after' => null,
            'safe_resume_mode' => $safeResumeMode,
            'next_safe_action' => null,
            'human_decisions_required' => json_encode([], JSON_THROW_ON_ERROR),
            'confidence' => 1.0,
            'pack_hash' => hash('sha256', 'pack-'.$uuid),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function insertProviderHandoff(string $id, array $metadata = []): void
    {
        \DB::table('ai_provider_handoffs')->insert([
            'id' => $id,
            'thread_id' => (string) Str::uuid(),
            'session_id' => null,
            'from_provider' => 'claude',
            'to_provider' => 'gpt',
            'reason' => 'provider_switch',
            'brief_text' => 'brief',
            'brief_json' => json_encode([], JSON_THROW_ON_ERROR),
            'compaction_id' => null,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $sourceRefs
     * @param  list<array<string,mixed>>  $mustKeepItems
     */
    private function receipt(
        string $scopeType,
        string $scopeId,
        string $receiptHash,
        array $sourceRefs,
        array $mustKeepItems = [['id' => 'decision:keep', 'kind' => 'decision', 'text' => 'Keep decision']],
        float $coverage = 1.0,
        ?float $retentionScore = 0.99,
    ): AtlasLongHorizonCompactionReceipt {
        return AtlasLongHorizonCompactionReceipt::query()->create([
            'uuid' => (string) Str::uuid(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'source_context_refs' => $sourceRefs,
            'retained_items' => $mustKeepItems,
            'discarded_items' => [],
            'discarded_reason' => null,
            'must_keep_items' => $mustKeepItems,
            'must_keep_coverage' => $coverage,
            'unresolved_loss' => [],
            'loss_risk' => AtlasLongHorizonCanon::LOSS_RISK_LOW,
            'recovery_queries' => [],
            'evidence_refs' => [],
            'summary_hash' => hash('sha256', $receiptHash),
            'context_retention_score' => $retentionScore,
            'quality_score' => 1.0,
            'detected_contradictions' => [],
            'stale_risks' => [],
            'receipt_hash' => $receiptHash,
        ]);
    }

    private function createCompactionEventTables(): void
    {
        $this->dropCompactionEventTables();

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

    private function dropCompactionEventTables(): void
    {
        Schema::dropIfExists('ai_provider_handoffs');
        Schema::dropIfExists('ai_compactions');
    }
}
