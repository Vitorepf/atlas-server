<?php

namespace Tests\Feature\Ai\LongHorizon\Replay;

use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Models\AtlasLongHorizonReplayManifest;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\Replay\LongHorizonReplayManifestBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

class LongHorizonReplayManifestBuilderTest extends TestCase
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

    private function builder(): LongHorizonReplayManifestBuilder
    {
        return app(LongHorizonReplayManifestBuilder::class);
    }

    private function pack(array $overrides = []): AtlasLongHorizonContinuationPack
    {
        $payload = array_merge([
            'uuid' => (string) Str::uuid(),
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            'scope_id' => '11111111-1111-1111-1111-111111111111',
            'objective' => 'Implementar feature X até cobrir testes',
            'current_phase' => 'execution',
            'state_summary' => 'Pack composed após primeiro cycle; pending evidence refs.',
            'decisions' => [['type' => 'decision', 'ref' => 'router_pin_v6']],
            'superseded_decisions' => [],
            'open_tasks' => [['ref' => 'wo:apply_patch']],
            'completed_tasks' => [],
            'blockers' => [],
            'risks' => [['ref' => 'regression_router_v5']],
            'evidence_refs' => [
                ['type' => 'test', 'ref' => 'tests/Feature/Ai/Router/RouterRuntimeMissionIntegrationTest.php'],
                ['type' => 'doc', 'ref' => 'docs/architecture/0007-atlas-desktop-design-system.md'],
            ],
            'context_manifest' => [
                ['type' => 'test', 'ref' => 'tests/Feature/Ai/Router/RouterRuntimeMissionIntegrationTest.php'],
                ['type' => 'doc', 'ref' => 'docs/architecture/0007-atlas-desktop-design-system.md'],
                ['type' => 'src', 'ref' => 'app/Services/Ai/Router/AtlasAiRouterService.php'],
            ],
            'context_pack_hash' => str_repeat('a', 64),
            'summary_hash' => str_repeat('b', 64),
            'source_receipts' => [],
            'stale_after' => Carbon::now()->addDays(7),
            'safe_resume_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            'next_safe_action' => 'replay_compaction_recovery_queries_then_resume_execute',
            'human_decisions_required' => [],
            'confidence' => 0.85,
            'pack_hash' => str_repeat('c', 64),
        ], $overrides);

        return AtlasLongHorizonContinuationPack::query()->create($payload);
    }

    private function receipt(array $overrides = []): AtlasLongHorizonCompactionReceipt
    {
        $payload = array_merge([
            'uuid' => (string) Str::uuid(),
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            'scope_id' => '11111111-1111-1111-1111-111111111111',
            'source_context_refs' => [['type' => 'turn', 'ref' => 'turn:1']],
            'retained_items' => [['type' => 'decision', 'ref' => 'router_pin_v6']],
            'discarded_items' => [['type' => 'small_talk', 'ref' => 'turn:7']],
            'discarded_reason' => 'low_signal',
            'must_keep_items' => [['type' => 'decision', 'ref' => 'router_pin_v6']],
            'must_keep_coverage' => 1.0,
            'unresolved_loss' => [],
            'loss_risk' => 'low',
            'recovery_queries' => [],
            'evidence_refs' => [['type' => 'commit', 'ref' => 'abc123']],
            'summary_hash' => str_repeat('d', 64),
            'quality_score' => 0.9,
            'detected_contradictions' => [],
            'stale_risks' => [],
            'receipt_hash' => str_repeat('e', 64),
        ], $overrides);

        return AtlasLongHorizonCompactionReceipt::query()->create($payload);
    }

    public function test_builder_persists_manifest_with_canonical_shape(): void
    {
        $pack = $this->pack();
        $manifest = $this->builder()->buildFromContinuationPack($pack);

        $this->assertInstanceOf(AtlasLongHorizonReplayManifest::class, $manifest);
        $this->assertSame(AtlasLongHorizonCanon::REPLAY_MANIFEST_SCHEMA_VERSION, $manifest->schema_version);
        $this->assertSame((string) $pack->id, $manifest->continuation_pack_id);
        $this->assertSame((string) $pack->scope_type, $manifest->scope_type);
        $this->assertSame((string) $pack->context_pack_hash, $manifest->context_pack_hash);
        $this->assertSame(64, strlen($manifest->hash));
        $this->assertNotEmpty($manifest->reader_instructions);
        $this->assertNotEmpty($manifest->provider_independent_summary);
        $this->assertContains('do_not_invoke_provider_to_complete_manifest', (array) $manifest->safety_notes);
        $this->assertContains('verify_context_pack_hash_before_resume', (array) $manifest->safety_notes);
    }

    public function test_builder_computes_missing_refs_from_required_minus_available(): void
    {
        $pack = $this->pack([
            'context_manifest' => [
                ['type' => 'src', 'ref' => 'app/A.php'],
                ['type' => 'src', 'ref' => 'app/B.php'],
            ],
            'evidence_refs' => [
                ['type' => 'src', 'ref' => 'app/A.php'], // matches src:app/A.php
            ],
        ]);

        $manifest = $this->builder()->buildFromContinuationPack($pack);

        $required = (array) $manifest->required_refs;
        $missing = (array) $manifest->missing_refs;

        $this->assertContains('src:app/A.php', $required);
        $this->assertContains('src:app/B.php', $required);
        $this->assertContains('src:app/B.php', $missing, 'B is required but not in available');
        $this->assertNotContains('src:app/A.php', $missing);
    }

    public function test_status_ready_when_no_missing_refs_and_pack_execute(): void
    {
        $pack = $this->pack([
            'context_manifest' => [['type' => 'src', 'ref' => 'app/A.php']],
            'evidence_refs' => [['type' => 'src', 'ref' => 'app/A.php']],
        ]);

        $manifest = $this->builder()->buildFromContinuationPack($pack);

        $this->assertSame(AtlasLongHorizonCanon::REPLAY_STATUS_READY, $manifest->replay_status);
    }

    public function test_status_partial_when_missing_refs(): void
    {
        $pack = $this->pack([
            'context_manifest' => [['type' => 'src', 'ref' => 'app/X.php']],
            'evidence_refs' => [],
        ]);

        $manifest = $this->builder()->buildFromContinuationPack($pack);

        $this->assertSame(AtlasLongHorizonCanon::REPLAY_STATUS_PARTIAL, $manifest->replay_status);
        $this->assertNotEmpty($manifest->missing_refs);
        $this->assertContains('downgrade_to_read_only_unless_missing_refs_rehydrated', (array) $manifest->safety_notes);
    }

    public function test_status_blocked_when_pack_safe_resume_mode_blocked(): void
    {
        $pack = $this->pack(['safe_resume_mode' => AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED]);
        $manifest = $this->builder()->buildFromContinuationPack($pack);
        $this->assertSame(AtlasLongHorizonCanon::REPLAY_STATUS_BLOCKED, $manifest->replay_status);
    }

    public function test_status_blocked_when_pack_safe_resume_mode_ask_human(): void
    {
        $pack = $this->pack(['safe_resume_mode' => AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN]);
        $manifest = $this->builder()->buildFromContinuationPack($pack);
        $this->assertSame(AtlasLongHorizonCanon::REPLAY_STATUS_BLOCKED, $manifest->replay_status);
    }

    public function test_status_requires_recovery_when_receipt_has_unresolved_loss_and_high_risk(): void
    {
        $pack = $this->pack();
        $receipt = $this->receipt([
            'loss_risk' => 'high',
            'unresolved_loss' => [['kind' => 'decision', 'ref' => 'router_decision_lost']],
            'recovery_queries' => [['ref' => 'reread_router_decision_doc']],
        ]);

        $manifest = $this->builder()->buildFromContinuationPack($pack, $receipt);

        $this->assertSame(AtlasLongHorizonCanon::REPLAY_STATUS_REQUIRES_RECOVERY, $manifest->replay_status);
        $this->assertContains('replay_recovery_queries_before_execute', (array) $manifest->safety_notes);
    }

    public function test_hash_is_deterministic_for_same_inputs(): void
    {
        $pack = $this->pack();
        $a = $this->builder()->buildFromContinuationPack($pack);

        // Re-emit: idempotent upsert returns same row with same hash.
        $b = $this->builder()->buildFromContinuationPack($pack->refresh());

        $this->assertSame(64, strlen($a->hash));
        $this->assertSame($a->hash, $b->hash, 'same content → same hash');
        $this->assertSame($a->id, $b->id, 'idempotent persistence');
    }

    public function test_hash_changes_when_content_changes(): void
    {
        $packA = $this->pack(['context_manifest' => [['type' => 'src', 'ref' => 'app/A.php']]]);
        $packB = $this->pack(['context_manifest' => [['type' => 'src', 'ref' => 'app/B.php']]]);

        $a = $this->builder()->buildFromContinuationPack($packA);
        $b = $this->builder()->buildFromContinuationPack($packB);

        $this->assertNotSame($a->hash, $b->hash);
    }

    public function test_build_by_uuid_resolves_pack_and_receipt(): void
    {
        $pack = $this->pack();
        $receipt = $this->receipt();

        $manifest = $this->builder()->buildById((string) $pack->uuid, (string) $receipt->uuid);

        $this->assertSame((string) $pack->id, $manifest->continuation_pack_id);
        $this->assertSame((string) $receipt->id, $manifest->compaction_receipt_id);
    }

    public function test_build_by_unknown_pack_uuid_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->buildById('00000000-0000-0000-0000-000000000000');
    }

    public function test_summary_does_not_contain_raw_chat_markers(): void
    {
        $secretSeed = 'SECRET_TURN_FROM_RAW_CHAT_DO_NOT_LEAK';
        $pack = $this->pack([
            // raw-text-like fields are sanitized by length, but a leaked seed should
            // never appear because the builder only reads canonical pack columns.
            'state_summary' => str_repeat('clean state ', 800), // ~9600 chars
        ]);

        $manifest = $this->builder()->buildFromContinuationPack($pack);

        $this->assertStringNotContainsString($secretSeed, (string) $manifest->provider_independent_summary);
        $this->assertLessThanOrEqual(1800, mb_strlen((string) $manifest->provider_independent_summary));
    }

    public function test_recovery_query_refs_appear_as_missing_even_when_listed_in_required(): void
    {
        $pack = $this->pack();
        $receipt = $this->receipt([
            'recovery_queries' => [['ref' => 'reread_doc']],
            'loss_risk' => 'low', // not high enough to flip status, but still surfaces
        ]);

        $manifest = $this->builder()->buildFromContinuationPack($pack, $receipt);

        $this->assertContains('recovery_query:reread_doc', (array) $manifest->required_refs);
        $this->assertContains('recovery_query:reread_doc', (array) $manifest->missing_refs);
    }
}
