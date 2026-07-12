<?php

namespace Tests\Feature\Ai\Compaction;

use App\Models\AiSessionState;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\AiCompactionService;
use App\Services\Ai\Compaction\CompactionMustKeepExtractor;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

/**
 * Covers TEOS-I1 M4: AiCompactionService::compactForScope() — adds
 * scope-aware compaction without spawning a parallel LongHorizon engine.
 */
class AiCompactionServiceCompactForScopeTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    private AiCompactionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLongHorizonPersistenceTables();
        $this->createSessionStateTable();
        $this->service = app(AiCompactionService::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_session_states');
        $this->dropLongHorizonPersistenceTables();
        parent::tearDown();
    }

    private function createSessionStateTable(): void
    {
        Schema::dropIfExists('ai_session_states');
        Schema::create('ai_session_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('active')->default(true);
            $table->text('objective')->nullable();
            $table->string('current_phase')->nullable();
            $table->json('decisions')->nullable();
            $table->json('open_loops')->nullable();
            $table->json('next_steps')->nullable();
            $table->json('constraints')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @param  list<array{text:string}>  $decisions
     */
    private function seedThreadSessionState(string $threadId, array $decisions): void
    {
        AiSessionState::query()->create([
            'thread_id' => $threadId,
            'active' => true,
            'objective' => 'CPT-01 extraction fixture',
            'decisions' => $decisions,
            'open_loops' => [],
            'next_steps' => [],
            'constraints' => [],
        ]);
    }

    public function test_compact_for_scope_creates_canonical_receipt_with_full_must_keep_coverage(): void
    {
        $out = $this->service->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA,
            'scope_id' => 'obra-router-refactor',
            'source_context_refs' => [
                ['kind' => 'forge_intake', 'ref' => 'intake://router-refactor'],
                ['kind' => 'evidence_ledger_entry', 'ref' => 'ledger://e-001'],
            ],
            'must_keep_items' => [
                ['id' => 'mk-decision-1', 'kind' => 'decision', 'digest' => 'Router fallback policy = governed'],
                ['id' => 'mk-blocker-1', 'kind' => 'blocker', 'digest' => 'Awaiting policy approval'],
                ['id' => 'mk-dod-1', 'kind' => 'dod', 'digest' => 'All 5 milestones certified'],
            ],
            'evidence_refs' => ['evidence://e-001', 'evidence://e-002'],
        ]);

        $this->assertSame(AtlasLongHorizonCanon::COMPACTION_RECEIPT_SCHEMA_VERSION, $out['schema_version']);
        $this->assertSame(1.0, $out['must_keep_coverage']);
        $this->assertSame(AtlasLongHorizonCanon::LOSS_RISK_LOW, $out['loss_risk']);
        $this->assertSame([], $out['unresolved_loss']);
        $this->assertTrue($out['write_allowed']);
        $this->assertCount(3, $out['retained_items']);
        $this->assertCount(0, $out['discarded_items']);
        $this->assertSame(64, strlen((string) $out['summary_hash']));
        $this->assertSame(64, strlen((string) $out['receipt_hash']));
        $this->assertStringContainsString('Scope: forge_obra/obra-router-refactor', $out['summary']);
        $this->assertStringContainsString('Retained must_keep: 3', $out['summary']);
        $this->assertStringContainsString('Unresolved loss: 0', $out['summary']);

        $this->assertDatabaseHas('atlas_long_horizon_compaction_receipts', [
            'uuid' => $out['receipt_uuid'],
            'scope_type' => 'forge_obra',
            'scope_id' => 'obra-router-refactor',
            'loss_risk' => AtlasLongHorizonCanon::LOSS_RISK_LOW,
        ]);
    }

    public function test_forced_discard_of_critical_kind_marks_unresolved_loss_and_blocks_write(): void
    {
        $out = $this->service->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
            'scope_id' => 'dev-session-123',
            'must_keep_items' => [
                ['id' => 'mk-1', 'kind' => 'decision', 'digest' => 'Use kernel canonical RAG'],
                ['id' => 'mk-2', 'kind' => 'blocker', 'digest' => 'Provider quota exhausted'],
                ['id' => 'mk-3', 'kind' => 'fact', 'digest' => 'Repo tracks Postgres + SQLite'],
            ],
            'forced_discards' => [
                ['id' => 'mk-2', 'reason' => AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE],
            ],
        ]);

        $this->assertSame(round(2 / 3, 3), $out['must_keep_coverage']);
        $this->assertFalse($out['write_allowed'], 'must_keep_coverage<1.0 must block write');
        $this->assertSame(AtlasLongHorizonCanon::LOSS_RISK_HIGH, $out['loss_risk'], 'critical kind discard => high risk');
        $this->assertCount(1, $out['unresolved_loss']);
        $this->assertSame('mk-2', $out['unresolved_loss'][0]['id']);
        $this->assertSame('blocker', $out['unresolved_loss'][0]['kind']);
        $this->assertSame(AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE, $out['discarded_reason']);
        $this->assertContains('rehydrate blocker:mk-2 from canonical sources', $out['recovery_queries']);
        $this->assertStringContainsString('write bloqueado', $out['summary']);
    }

    public function test_low_signal_non_critical_discard_drops_coverage_but_yields_medium_risk(): void
    {
        $out = $this->service->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_LONG_HORIZON,
            'scope_id' => 'workspace-atlas',
            'must_keep_items' => [
                ['id' => 'mk-1', 'kind' => 'fact', 'digest' => 'Project uses Laravel 11'],
                ['id' => 'mk-2', 'kind' => 'fact', 'digest' => 'Tests run with sqlite in-memory'],
                ['id' => 'mk-3', 'kind' => 'fact', 'digest' => 'Pint enforces PSR-12'],
                ['id' => 'mk-4', 'kind' => 'fact', 'digest' => 'Composer scripts include atlas:cli'],
                ['id' => 'mk-5', 'kind' => 'fact', 'digest' => 'Storage volume mounted at /storage'],
                ['id' => 'mk-6', 'kind' => 'fact', 'digest' => 'Provider drivers blocked by default'],
                ['id' => 'mk-7', 'kind' => 'fact', 'digest' => 'Telescope is disabled in tests'],
            ],
            'forced_discards' => [
                ['id' => 'mk-7', 'reason' => AtlasLongHorizonCanon::DISCARDED_REASON_LOW_SIGNAL],
            ],
        ]);

        $this->assertSame(round(6 / 7, 3), $out['must_keep_coverage']);
        $this->assertFalse($out['write_allowed']);
        $this->assertSame(
            AtlasLongHorizonCanon::LOSS_RISK_MEDIUM,
            $out['loss_risk'],
            'non-critical discard with coverage>=0.85 should stay medium',
        );
    }

    public function test_legacy_compact_path_is_untouched_by_compact_for_scope(): void
    {
        // 1. Public surface preservation: legacy entry points must still
        // exist on AiCompactionService with the same signatures used by
        // existing callers (`compact(AiThread, ?AiSession, ...)`,
        // `maybeAutoCompact(AiThread, AiSession)`).
        $reflection = new \ReflectionClass($this->service);
        $this->assertTrue($reflection->hasMethod('compact'), 'legacy compact() must remain');
        $this->assertTrue($reflection->hasMethod('maybeAutoCompact'), 'legacy maybeAutoCompact() must remain');
        $compactSig = $reflection->getMethod('compact');
        $autoSig = $reflection->getMethod('maybeAutoCompact');
        $this->assertSame('compact', $compactSig->getName());
        $this->assertGreaterThanOrEqual(2, $compactSig->getNumberOfParameters(), 'compact() keeps thread+session signature');
        $this->assertSame(2, $autoSig->getNumberOfParameters(), 'maybeAutoCompact(thread, session)');

        // 2. compactForScope writes ONLY to the canonical long-horizon
        // receipt table; the legacy `ai_compactions` table is not in the
        // test fixture trait, proving the new entry point does not touch
        // it (otherwise we would see a no-such-table error here).
        $beforeReceipts = AtlasLongHorizonCompactionReceipt::query()->count();
        $this->service->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            'scope_id' => 'mission-back-compat',
            'must_keep_items' => [['id' => 'mk-1', 'kind' => 'fact', 'digest' => 'noop']],
        ]);
        $afterReceipts = AtlasLongHorizonCompactionReceipt::query()->count();
        $this->assertSame($beforeReceipts + 1, $afterReceipts);
        $this->assertFalse(
            Schema::hasTable('ai_compactions'),
            'fixture must not create the legacy table; absence proves compactForScope did not implicitly require it',
        );
    }

    public function test_receipt_hash_is_deterministic_for_equivalent_inputs(): void
    {
        $payloadInput = [
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_WORK_PACKET,
            'scope_id' => 'wp-001',
            'must_keep_items' => [
                ['id' => 'mk-1', 'kind' => 'decision', 'digest' => 'reuse first'],
            ],
        ];

        $a = $this->service->compactForScope($payloadInput);
        $b = $this->service->compactForScope($payloadInput);

        // uuid + receipt id differ; receipt_hash recomputed from canonical
        // payload must collide because none of the volatile fields enter it.
        $this->assertNotSame($a['receipt_uuid'], $b['receipt_uuid']);

        $rowA = AtlasLongHorizonCompactionReceipt::query()->where('uuid', $a['receipt_uuid'])->firstOrFail();
        $rowB = AtlasLongHorizonCompactionReceipt::query()->where('uuid', $b['receipt_uuid'])->firstOrFail();

        $hashA = AtlasLongHorizonCompactionReceipt::canonicalReceiptHash(array_merge($rowA->toArray(), [
            // Mirror the in-memory payload shape: uuid is part of canonical
            // hash on both sides, so equivalence is over (payload \ uuid).
        ]));
        $payloadA = $rowA->toArray();
        $payloadB = $rowB->toArray();
        unset($payloadA['uuid'], $payloadB['uuid']);
        $this->assertSame(
            AtlasLongHorizonCompactionReceipt::canonicalReceiptHash($payloadA),
            AtlasLongHorizonCompactionReceipt::canonicalReceiptHash($payloadB),
            'canonical hash over identical content (minus uuid) must collide',
        );
    }

    public function test_receipt_round_trips_through_json_with_stable_casts(): void
    {
        $out = $this->service->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA,
            'scope_id' => 'obra-json-stable',
            'source_context_refs' => ['intake://x', 'ledger://y'],
            'must_keep_items' => [
                ['id' => 'mk-1', 'kind' => 'decision', 'digest' => 'choose A'],
            ],
            'evidence_refs' => ['evidence://x'],
            'stale_risks' => [['ref' => 'doc://stale', 'age_days' => 12]],
        ]);

        $row = AtlasLongHorizonCompactionReceipt::query()->where('uuid', $out['receipt_uuid'])->firstOrFail();
        $this->assertIsArray($row->retained_items);
        $this->assertIsArray($row->discarded_items);
        $this->assertIsArray($row->must_keep_items);
        $this->assertIsArray($row->source_context_refs);
        $this->assertIsArray($row->evidence_refs);
        $this->assertIsArray($row->stale_risks);
        $this->assertIsFloat($row->must_keep_coverage);
        $this->assertIsFloat($row->quality_score);

        $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json, 'compactForScope output must be json encodable');
        $decoded = json_decode((string) $json, true);
        $this->assertSame(AtlasLongHorizonCanon::COMPACTION_RECEIPT_SCHEMA_VERSION, $decoded['schema_version']);
        $this->assertSame($out['receipt_hash'], $decoded['receipt_hash']);
    }

    public function test_missing_receipts_table_fails_open_without_persisting(): void
    {
        Schema::dropIfExists('atlas_long_horizon_compaction_receipts');

        $out = $this->service->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA,
            'scope_id' => 'obra-table-missing',
            'must_keep_items' => [
                ['id' => 'mk-1', 'kind' => 'decision', 'digest' => 'fail open, never throw'],
            ],
        ]);

        $this->assertSame('failed_open', $out['status']);
        $this->assertSame('atlas_long_horizon_compaction_receipts_table_missing', $out['reason']);
        $this->assertFalse($out['persisted']);
        $this->assertNull($out['compaction_receipt_id']);
        $this->assertNotSame('', (string) $out['receipt_uuid']);
        $this->assertSame(64, strlen((string) $out['receipt_hash']));
        $this->assertSame(1.0, $out['must_keep_coverage']);
        $this->assertTrue($out['write_allowed']);
    }

    public function test_invalid_scope_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->compactForScope([
            'scope_type' => 'not_a_real_scope',
            'must_keep_items' => [],
        ]);
    }

    public function test_missing_scope_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->compactForScope([
            'must_keep_items' => [],
        ]);
    }

    public function test_extracted_must_keep_from_live_session_when_caller_omits_items(): void
    {
        $threadId = 'thread-cpt01-extracted';
        $this->seedThreadSessionState($threadId, [
            ['text' => 'Use kernel canonical RAG'],
            ['text' => 'Reject provider-only memory'],
            ['text' => 'Emit compaction receipts on every compact'],
        ]);

        $out = $this->service->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_THREAD,
            'scope_id' => $threadId,
        ]);

        $this->assertSame(CompactionMustKeepExtractor::MUST_KEEP_SOURCE_EXTRACTED, $out['must_keep_source']);
        $this->assertSame(CompactionMustKeepExtractor::COVERAGE_STATUS_VERIFIED, $out['must_keep_coverage_status']);
        $this->assertGreaterThanOrEqual(3, count($out['must_keep_items']));
        $this->assertSame(1.0, $out['must_keep_coverage']);
        $this->assertTrue($out['write_allowed']);
        $this->assertCount(3, array_filter(
            $out['must_keep_items'],
            static fn (array $item): bool => ($item['kind'] ?? null) === 'decision',
        ));
    }

    public function test_adversarial_caller_dummy_item_does_not_bypass_extracted_must_keep(): void
    {
        $threadId = 'thread-cpt01-adversarial';
        $this->seedThreadSessionState($threadId, [
            ['text' => 'Decision alpha'],
            ['text' => 'Decision beta'],
            ['text' => 'Decision gamma'],
        ]);

        $out = $this->service->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_THREAD,
            'scope_id' => $threadId,
            'must_keep_items' => [
                ['id' => 'mk-dummy', 'kind' => 'fact', 'digest' => 'trivial bypass attempt'],
            ],
        ]);

        $this->assertSame(CompactionMustKeepExtractor::MUST_KEEP_SOURCE_CALLER, $out['must_keep_source']);
        $this->assertGreaterThanOrEqual(4, count($out['must_keep_items']));
        $this->assertSame(1.0, $out['must_keep_coverage']);
        $this->assertTrue($out['write_allowed']);
    }

    public function test_scope_without_live_state_marks_vacuous_coverage_not_verified(): void
    {
        $out = $this->service->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            'scope_id' => 'mission-no-live-state',
        ]);

        $this->assertSame(CompactionMustKeepExtractor::MUST_KEEP_SOURCE_NONE, $out['must_keep_source']);
        $this->assertSame(CompactionMustKeepExtractor::COVERAGE_STATUS_VACUOUS, $out['must_keep_coverage_status']);
        $this->assertNotSame(1.0, $out['must_keep_coverage']);
        $this->assertFalse($out['write_allowed']);
        $this->assertSame([], $out['must_keep_items']);
    }

    public function test_live_thread_without_state_is_vacuous_not_one_point_zero_verified(): void
    {
        $out = $this->service->compactForScope([
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_THREAD,
            'scope_id' => 'thread-empty-no-state',
        ]);

        $this->assertSame(CompactionMustKeepExtractor::MUST_KEEP_SOURCE_NONE, $out['must_keep_source']);
        $this->assertSame(CompactionMustKeepExtractor::COVERAGE_STATUS_VACUOUS, $out['must_keep_coverage_status']);
        $this->assertNotSame(1.0, $out['must_keep_coverage']);
        $this->assertFalse($out['write_allowed']);
    }
}
