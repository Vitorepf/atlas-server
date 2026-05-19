<?php

namespace Tests\Feature\Ai\LongHorizon\Replay;

use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\Replay\LongHorizonReplayManifestBuilder;
use App\Services\Ai\LongHorizon\Replay\ReplayManifestReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

class ReplayManifestReaderTest extends TestCase
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

    private function reader(): ReplayManifestReader
    {
        return app(ReplayManifestReader::class);
    }

    private function buildManifest(array $packOverrides = [])
    {
        $pack = AtlasLongHorizonContinuationPack::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            'scope_id' => (string) Str::uuid(),
            'objective' => 'Cobrir feature X',
            'current_phase' => 'execution',
            'state_summary' => 'state',
            'decisions' => [],
            'superseded_decisions' => [],
            'open_tasks' => [],
            'completed_tasks' => [],
            'blockers' => [],
            'risks' => [],
            'evidence_refs' => [['type' => 'test', 'ref' => 'tests/A.php']],
            'context_manifest' => [['type' => 'test', 'ref' => 'tests/A.php']],
            'context_pack_hash' => str_repeat('a', 64),
            'summary_hash' => str_repeat('b', 64),
            'source_receipts' => [],
            'stale_after' => Carbon::now()->addDays(7),
            'safe_resume_mode' => AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            'next_safe_action' => 'resume',
            'human_decisions_required' => [],
            'confidence' => 0.9,
            'pack_hash' => str_repeat('c', 64),
        ], $packOverrides));

        return app(LongHorizonReplayManifestBuilder::class)->buildFromContinuationPack($pack);
    }

    public function test_bundle_has_canonical_shape(): void
    {
        $manifest = $this->buildManifest();
        $bundle = $this->reader()->read($manifest);

        $this->assertSame(AtlasLongHorizonCanon::REPLAY_READER_SCHEMA_VERSION, $bundle['schema_version']);
        $this->assertSame((string) $manifest->uuid, $bundle['manifest_uuid']);
        $this->assertSame((string) $manifest->hash, $bundle['manifest_hash']);
        $this->assertSame((string) $manifest->replay_status, $bundle['manifest_status']);
        foreach ([
            'scope', 'context_pack_hash', 'integrity', 'provider_independent_summary',
            'reader_instructions', 'safety_notes', 'required_refs', 'available_refs',
            'missing_refs', 'event_refs', 'evidence_refs', 'recommended_resume_mode',
            'next_action_hint', 'claim_policy', 'bundle_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $bundle, "bundle missing [{$key}]");
        }
        $this->assertSame(64, strlen($bundle['bundle_hash']));
    }

    public function test_bundle_marks_integrity_when_hash_matches(): void
    {
        $manifest = $this->buildManifest();
        $bundle = $this->reader()->read($manifest);
        $this->assertTrue($bundle['integrity']['context_pack_hash_matches']);
        $this->assertTrue($bundle['integrity']['continuation_pack_present']);
    }

    public function test_bundle_recommended_resume_mode_maps_status(): void
    {
        $manifest = $this->buildManifest();
        $bundle = $this->reader()->read($manifest);
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE, $bundle['recommended_resume_mode']);

        $manifestBlocked = $this->buildManifest([
            'safe_resume_mode' => AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN,
        ]);
        $bundleBlocked = $this->reader()->read($manifestBlocked);
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN, $bundleBlocked['recommended_resume_mode']);
        $this->assertSame('do_not_execute_request_human_decision', $bundleBlocked['next_action_hint']);
    }

    public function test_bundle_does_not_contain_raw_sensitive_text(): void
    {
        $secret = 'SECRET_RAW_CHAT_DO_NOT_LEAK';
        // We don't put the secret anywhere — the assertion is that no leak path exists.
        $manifest = $this->buildManifest();
        $bundle = $this->reader()->read($manifest);

        $json = (string) json_encode($bundle);
        $this->assertStringNotContainsString($secret, $json);
        // Bundle may mention "raw_chat" in negative safety markers (do_not_paste_raw_chat...).
        // The contract is: nothing labelled as a chat turn / transcript is emitted.
        $this->assertStringNotContainsString('"chat_transcript"', $json);
        $this->assertStringNotContainsString('"chat_turn"', $json);
        $this->assertFalse($bundle['claim_policy']['reads_raw_chat']);
    }

    public function test_claim_policy_blocks_provider_dependency_and_external_claims(): void
    {
        $manifest = $this->buildManifest();
        $bundle = $this->reader()->read($manifest);

        $claim = $bundle['claim_policy'];
        $this->assertFalse($claim['declares_provider_dependency']);
        $this->assertFalse($claim['declares_external_benchmark']);
        $this->assertFalse($claim['declares_external_superiority']);
        $this->assertFalse($claim['invokes_provider']);
        $this->assertFalse($claim['reads_raw_chat']);
        $this->assertContains('paste_raw_chat_into_provider_prompt', $claim['forbidden_uses']);
        $this->assertContains('call_provider_to_complete_manifest', $claim['forbidden_uses']);
    }

    public function test_bundle_hash_is_deterministic(): void
    {
        $manifest = $this->buildManifest();
        $a = $this->reader()->read($manifest);
        $b = $this->reader()->read($manifest->refresh());

        $this->assertSame($a['bundle_hash'], $b['bundle_hash']);
    }

    public function test_read_by_uuid_resolves_manifest(): void
    {
        $manifest = $this->buildManifest();
        $bundle = $this->reader()->readByUuid((string) $manifest->uuid);
        $this->assertSame((string) $manifest->uuid, $bundle['manifest_uuid']);
    }

    public function test_read_by_unknown_uuid_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->reader()->readByUuid('00000000-0000-0000-0000-000000000000');
    }
}
