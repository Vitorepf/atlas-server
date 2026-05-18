<?php

namespace Tests\Feature\Ai\LongHorizon;

use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

class AtlasLongHorizonPersistenceTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLongHorizonPersistenceTables();
    }

    protected function tearDown(): void
    {
        $this->dropLongHorizonPersistenceTables();
        parent::tearDown();
    }

    public function test_continuation_pack_and_compaction_receipt_tables_exist_with_canonical_columns(): void
    {
        $this->assertTrue(Schema::hasTable('atlas_long_horizon_continuation_packs'));
        $this->assertTrue(Schema::hasTable('atlas_long_horizon_compaction_receipts'));

        foreach ([
            'id', 'schema_version', 'uuid', 'scope_type', 'scope_id', 'objective', 'current_phase',
            'state_summary', 'decisions', 'superseded_decisions', 'open_tasks', 'completed_tasks',
            'blockers', 'risks', 'evidence_refs', 'context_manifest', 'context_pack_hash',
            'summary_hash', 'source_receipts', 'stale_after', 'safe_resume_mode', 'next_safe_action',
            'human_decisions_required', 'confidence', 'pack_hash', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('atlas_long_horizon_continuation_packs', $column),
                "continuation_pack column {$column} missing",
            );
        }

        foreach ([
            'id', 'schema_version', 'uuid', 'scope_type', 'scope_id', 'source_context_refs',
            'retained_items', 'discarded_items', 'discarded_reason', 'must_keep_items',
            'must_keep_coverage', 'unresolved_loss', 'loss_risk', 'recovery_queries',
            'evidence_refs', 'summary_hash', 'quality_score', 'detected_contradictions',
            'stale_risks', 'receipt_hash', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('atlas_long_horizon_compaction_receipts', $column),
                "compaction_receipt column {$column} missing",
            );
        }
    }

    public function test_continuation_pack_round_trips_json_and_datetime_casts(): void
    {
        $payload = $this->continuationPackPayload();
        $hash = AtlasLongHorizonContinuationPack::canonicalPackHash($payload);
        $row = AtlasLongHorizonContinuationPack::query()->create(array_merge($payload, [
            'pack_hash' => $hash,
        ]));

        $fresh = AtlasLongHorizonContinuationPack::query()->whereKey($row->id)->firstOrFail();

        $this->assertSame(AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION, $fresh->schema_version);
        $this->assertSame('dev_run', $fresh->scope_type);
        $this->assertIsArray($fresh->decisions);
        $this->assertSame(['dec-001'], $fresh->decisions);
        $this->assertIsArray($fresh->human_decisions_required);
        $this->assertSame(0.72, $fresh->confidence);
        $this->assertNotNull($fresh->stale_after);
        $this->assertSame(64, strlen((string) $fresh->pack_hash));
        $this->assertSame($hash, $fresh->pack_hash);
    }

    public function test_continuation_pack_hash_is_stable_for_equivalent_payloads(): void
    {
        $payload = $this->continuationPackPayload();
        $hashA = AtlasLongHorizonContinuationPack::canonicalPackHash($payload);
        $hashB = AtlasLongHorizonContinuationPack::canonicalPackHash($payload);
        $this->assertSame($hashA, $hashB);
        $this->assertSame(64, strlen($hashA));

        $mutated = $payload;
        $mutated['decisions'] = ['dec-001', 'dec-002'];
        $this->assertNotSame(
            $hashA,
            AtlasLongHorizonContinuationPack::canonicalPackHash($mutated),
            'Mutating any field must change the hash.',
        );

        $withVolatile = array_merge($payload, [
            'id' => (string) Str::uuid(),
            'pack_hash' => 'pending',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);
        $this->assertSame(
            $hashA,
            AtlasLongHorizonContinuationPack::canonicalPackHash($withVolatile),
            'Volatile fields must be excluded from the canonical hash.',
        );
    }

    public function test_compaction_receipt_round_trips_with_canonical_enums(): void
    {
        $payload = $this->compactionReceiptPayload();
        $hash = AtlasLongHorizonCompactionReceipt::canonicalReceiptHash($payload);
        $row = AtlasLongHorizonCompactionReceipt::query()->create(array_merge($payload, [
            'receipt_hash' => $hash,
        ]));
        $fresh = AtlasLongHorizonCompactionReceipt::query()->whereKey($row->id)->firstOrFail();

        $this->assertSame(AtlasLongHorizonCanon::COMPACTION_RECEIPT_SCHEMA_VERSION, $fresh->schema_version);
        $this->assertSame(AtlasLongHorizonCanon::DISCARDED_REASON_LOW_SIGNAL, $fresh->discarded_reason);
        $this->assertSame(AtlasLongHorizonCanon::LOSS_RISK_MEDIUM, $fresh->loss_risk);
        $this->assertSame(0.82, $fresh->must_keep_coverage);
        $this->assertIsArray($fresh->recovery_queries);
        $this->assertSame(['SELECT * FROM atlas_memory_entries WHERE tag = foo'], $fresh->recovery_queries);
        $this->assertSame($hash, $fresh->receipt_hash);
        $this->assertSame(64, strlen((string) $fresh->receipt_hash));
    }

    public function test_compaction_receipt_hash_is_stable_and_excludes_volatile_fields(): void
    {
        $payload = $this->compactionReceiptPayload();
        $hashA = AtlasLongHorizonCompactionReceipt::canonicalReceiptHash($payload);
        $hashB = AtlasLongHorizonCompactionReceipt::canonicalReceiptHash(array_merge($payload, [
            'id' => (string) Str::uuid(),
            'receipt_hash' => 'pending',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-02-02T00:00:00Z',
        ]));

        $this->assertSame($hashA, $hashB);
        $this->assertSame(64, strlen($hashA));

        $diverging = array_merge($payload, ['loss_risk' => AtlasLongHorizonCanon::LOSS_RISK_HIGH]);
        $this->assertNotSame($hashA, AtlasLongHorizonCompactionReceipt::canonicalReceiptHash($diverging));
    }

    public function test_canonical_enum_lists_match_their_constants(): void
    {
        $this->assertContains('dev_run', AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES);
        $this->assertContains('forge_run', AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES);
        $this->assertContains(
            AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN,
            AtlasLongHorizonCanon::ALLOWED_SAFE_RESUME_MODES,
        );
        $this->assertContains(
            AtlasLongHorizonCanon::DISCARDED_REASON_SUPERSEDED,
            AtlasLongHorizonCanon::ALLOWED_DISCARDED_REASONS,
        );
        $this->assertContains(
            AtlasLongHorizonCanon::LOSS_RISK_HIGH,
            AtlasLongHorizonCanon::ALLOWED_LOSS_RISKS,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function continuationPackPayload(): array
    {
        return [
            'uuid' => 'pack-'.bin2hex(random_bytes(8)),
            'schema_version' => AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION,
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN,
            'scope_id' => 'run-001',
            'objective' => 'corrigir test fallback no provider router',
            'current_phase' => 'verification',
            'state_summary' => 'patch applied; awaiting human approval for regression-suite gap',
            'decisions' => ['dec-001'],
            'superseded_decisions' => [],
            'open_tasks' => ['tests/Feature/Provider/RouterTest.php::test_fallback'],
            'completed_tasks' => ['apply patch to provider router'],
            'blockers' => [['kind' => 'missing_test', 'description' => 'regression suite missing']],
            'risks' => ['provider topology drift'],
            'evidence_refs' => ['dev_repair_run:run-001'],
            'context_manifest' => [['ref' => 'spec:auth-flow-v2']],
            'context_pack_hash' => hash('sha256', 'context-pack-fixture'),
            'summary_hash' => hash('sha256', 'state-summary-fixture'),
            'source_receipts' => ['atlas.programming.dev_repair_receipt.v1:run-001'],
            'stale_after' => now()->addDays(7),
            'safe_resume_mode' => AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN,
            'next_safe_action' => 'request_human_decision_on_missing_ref',
            'human_decisions_required' => [['id' => 'hd-001', 'question' => 'Approve plan?']],
            'confidence' => 0.72,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function compactionReceiptPayload(): array
    {
        return [
            'uuid' => 'comp-'.bin2hex(random_bytes(8)),
            'schema_version' => AtlasLongHorizonCanon::COMPACTION_RECEIPT_SCHEMA_VERSION,
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_THREAD,
            'scope_id' => 'thread-42',
            'source_context_refs' => ['atlas_memory_entries:1', 'ai_audit_event:5'],
            'retained_items' => [['ref' => 'mem:1', 'reason' => 'high_signal']],
            'discarded_items' => [['ref' => 'mem:2', 'reason' => 'low_signal']],
            'discarded_reason' => AtlasLongHorizonCanon::DISCARDED_REASON_LOW_SIGNAL,
            'must_keep_items' => [['ref' => 'mem:1']],
            'must_keep_coverage' => 0.82,
            'unresolved_loss' => [['ref' => 'mem:3', 'why' => 'budget']],
            'loss_risk' => AtlasLongHorizonCanon::LOSS_RISK_MEDIUM,
            'recovery_queries' => ['SELECT * FROM atlas_memory_entries WHERE tag = foo'],
            'evidence_refs' => ['ai_audit_event:5'],
            'summary_hash' => hash('sha256', 'comp-summary-fixture'),
            'quality_score' => 0.78,
            'detected_contradictions' => [],
            'stale_risks' => [['ref' => 'edge:42', 'stale_after' => '2026-06-01T00:00:00Z']],
        ];
    }
}
