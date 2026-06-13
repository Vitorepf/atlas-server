<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\LongHorizon;

use App\Models\AtlasLongHorizonContinuationPack;
use App\Models\AtlasLongHorizonReplayManifest;
use App\Services\Ai\LongHorizon\LongHorizonContinuityCertificationService;
use App\Services\Ai\LongHorizon\LongHorizonContinuityPackEmitterService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

final class LongHorizonContinuityPackEmitterServiceTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLongHorizonPersistenceTables();
        $this->workspace = storage_path('framework/testing/continuity-pack-emitter-'.Str::random(8));
        File::ensureDirectoryExists($this->workspace);
    }

    protected function tearDown(): void
    {
        $this->dropLongHorizonPersistenceTables();
        File::deleteDirectory($this->workspace);
        parent::tearDown();
    }

    public function test_emitter_builds_pack_replay_manifest_and_ready_certification_from_real_refs(): void
    {
        [$docPath, $evidenceRoot] = $this->writeProviderSafeEvidence();

        $payload = app(LongHorizonContinuityPackEmitterService::class)->emit([
            'scope_type' => 'long_horizon',
            'scope_id' => 'fable-l6-12-test',
            'doc_path' => $docPath,
            'evidence_root' => $evidenceRoot,
            'max_evidence_refs' => 6,
            'required_evidence_kinds' => ['doc', 'artifact'],
            'strict_replay' => true,
        ]);

        $this->assertSame('continuity_pack_ready', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertSame(LongHorizonContinuityCertificationService::STATUS_READY, data_get($payload, 'certification.status'));
        $this->assertSame('blocked', data_get($payload, 'before_certification.status'));
        $this->assertContains('continuation_pack_exists', data_get($payload, 'before_certification.blocker_ids'));
        $this->assertGreaterThan(0, data_get($payload, 'config.ab_summary.p0_blocker_delta'));
        $this->assertTrue(data_get($payload, 'config.ab_summary.measured_improvement'));
        $this->assertSame(0, data_get($payload, 'replay_manifest.missing_ref_count'));
        $this->assertFalse(data_get($payload, 'claim_policy.raw_chat_copied'));
        $this->assertFalse(data_get($payload, 'claim_policy.provider_calls_made'));

        $this->assertSame(1, AtlasLongHorizonContinuationPack::query()->count());
        $this->assertSame(1, AtlasLongHorizonReplayManifest::query()->count());
    }

    public function test_emitter_blocks_when_no_doc_or_evidence_refs_exist(): void
    {
        $payload = app(LongHorizonContinuityPackEmitterService::class)->emit([
            'scope_type' => 'long_horizon',
            'scope_id' => 'fable-l6-12-no-evidence',
            'doc_path' => $this->workspace.'/missing.md',
            'evidence_root' => $this->workspace.'/missing-evidence',
            'required_evidence_kinds' => ['doc', 'artifact'],
            'strict_replay' => true,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertContains('continuity_evidence_refs_missing', $payload['blockers']);
        $this->assertSame(0, AtlasLongHorizonContinuationPack::query()->count());
        $this->assertSame(0, AtlasLongHorizonReplayManifest::query()->count());
    }

    public function test_command_writes_receipt_and_strict_success_when_certified(): void
    {
        [$docPath, $evidenceRoot] = $this->writeProviderSafeEvidence();
        $receipt = $this->workspace.'/receipt.json';

        $exit = $this->artisan('atlas:long-horizon:continuity-pack', [
            '--scope-type' => 'long_horizon',
            '--scope-id' => 'fable-l6-12-command',
            '--doc' => $docPath,
            '--evidence-root' => $evidenceRoot,
            '--max-evidence' => 6,
            '--required-evidence' => 'doc,artifact',
            '--strict-replay' => true,
            '--receipt' => $receipt,
            '--strict' => true,
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
        $this->assertFileExists($receipt);

        $payload = json_decode((string) File::get($receipt), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('continuity_pack_ready', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertSame('ready', data_get($payload, 'replay_manifest.replay_status'));
    }

    public function test_schedule_and_flags_are_enabled_for_l6_12(): void
    {
        $this->assertTrue((bool) config('atlas.long_horizon.continuity_pack_emitter.enabled'));
        $this->assertTrue((bool) config('atlas.long_horizon.continuity_pack_emitter.schedule_enabled'));
        $this->assertSame('07:30', (string) config('atlas.long_horizon.continuity_pack_emitter.schedule_time'));

        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('atlas:long-horizon:continuity-pack --strict-replay --write-receipt --json', $output);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function writeProviderSafeEvidence(): array
    {
        $docPath = $this->workspace.'/fable-lista-6.md';
        $evidenceRoot = $this->workspace.'/evidence';
        File::ensureDirectoryExists($evidenceRoot);

        File::put($docPath, "# Lista 6\n\nL6-12 provider-safe doc evidence.\n");
        File::put($evidenceRoot.'/continuity-certify-before.json', '{"status":"blocked"}'."\n");
        File::put($evidenceRoot.'/continuity-pack-test.txt', "continuity pack emitter test evidence\n");

        return [$docPath, $evidenceRoot];
    }
}
