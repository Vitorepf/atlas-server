<?php

namespace Tests\Feature\Ai\LongHorizon\Replay;

use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

class AtlasLongHorizonReplayManifestCommandTest extends TestCase
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

    private function runCommand(array $params): array
    {
        $exit = Artisan::call('atlas:long-horizon:replay-manifest', $params);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, 'command output must be JSON');

        return ['exit' => $exit, 'payload' => $payload];
    }

    private function freshPack(array $overrides = []): AtlasLongHorizonContinuationPack
    {
        return AtlasLongHorizonContinuationPack::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            'scope_id' => (string) Str::uuid(),
            'objective' => 'Obj',
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
        ], $overrides));
    }

    public function test_build_with_continuation_pack_uuid_produces_manifest(): void
    {
        $pack = $this->freshPack();

        $result = $this->runCommand([
            'action' => 'build',
            '--continuation-pack' => (string) $pack->uuid,
            '--json' => true,
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertTrue($result['payload']['ok']);
        $this->assertSame('build', $result['payload']['action']);
        $manifest = $result['payload']['manifest'];
        $this->assertSame((string) $pack->id, $manifest['continuation_pack_id']);
        $this->assertSame(AtlasLongHorizonCanon::REPLAY_STATUS_READY, $manifest['replay_status']);
    }

    public function test_build_with_scope_selector_selects_latest_pack(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-19 10:00:00'));
        $oldPack = $this->freshPack(['scope_id' => 'mission-1']);
        Carbon::setTestNow(Carbon::parse('2026-05-19 10:00:10'));
        $newPack = $this->freshPack(['scope_id' => 'mission-1']);
        Carbon::setTestNow();

        $result = $this->runCommand([
            'action' => 'build',
            '--scope-type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            '--scope-id' => 'mission-1',
            '--json' => true,
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertSame((string) $newPack->id, $result['payload']['manifest']['continuation_pack_id']);
    }

    public function test_build_with_unknown_uuid_returns_not_found(): void
    {
        $result = $this->runCommand([
            'action' => 'build',
            '--continuation-pack' => '00000000-0000-0000-0000-000000000000',
            '--json' => true,
        ]);

        $this->assertSame(1, $result['exit']);
        $this->assertFalse($result['payload']['ok']);
        $this->assertSame('continuation_pack_not_found', $result['payload']['error']);
    }

    public function test_show_returns_persisted_manifest(): void
    {
        $pack = $this->freshPack();
        $built = $this->runCommand([
            'action' => 'build',
            '--continuation-pack' => (string) $pack->uuid,
            '--json' => true,
        ]);
        $uuid = $built['payload']['manifest']['uuid'];

        $result = $this->runCommand(['action' => 'show', '--manifest' => $uuid, '--json' => true]);
        $this->assertSame(0, $result['exit']);
        $this->assertSame($uuid, $result['payload']['manifest']['uuid']);
    }

    public function test_read_returns_provider_independent_bundle(): void
    {
        $pack = $this->freshPack();
        $built = $this->runCommand([
            'action' => 'build',
            '--continuation-pack' => (string) $pack->uuid,
            '--json' => true,
        ]);
        $uuid = $built['payload']['manifest']['uuid'];

        $result = $this->runCommand(['action' => 'read', '--manifest' => $uuid, '--json' => true]);
        $this->assertSame(0, $result['exit']);
        $bundle = $result['payload']['bundle'];
        $this->assertSame(AtlasLongHorizonCanon::REPLAY_READER_SCHEMA_VERSION, $bundle['schema_version']);
        $this->assertArrayHasKey('claim_policy', $bundle);
        $this->assertFalse($bundle['claim_policy']['invokes_provider']);
        $this->assertFalse($bundle['claim_policy']['reads_raw_chat']);
    }

    public function test_list_filters_by_scope(): void
    {
        $packA = $this->freshPack(['scope_id' => 'mission-A']);
        $packB = $this->freshPack(['scope_id' => 'mission-B']);

        $this->runCommand(['action' => 'build', '--continuation-pack' => (string) $packA->uuid, '--json' => true]);
        $this->runCommand(['action' => 'build', '--continuation-pack' => (string) $packB->uuid, '--json' => true]);

        $result = $this->runCommand([
            'action' => 'list',
            '--scope-type' => AtlasLongHorizonCanon::SCOPE_TYPE_MISSION,
            '--scope-id' => 'mission-A',
            '--json' => true,
        ]);
        $this->assertSame(0, $result['exit']);
        $this->assertSame(1, $result['payload']['count']);
        $this->assertSame('mission-A', $result['payload']['manifests'][0]['scope_id']);
    }

    public function test_usage_error_on_unknown_action(): void
    {
        $result = $this->runCommand(['action' => 'foo', '--json' => true]);
        $this->assertSame(2, $result['exit']);
        $this->assertSame('usage_error', $result['payload']['error']);
    }
}
