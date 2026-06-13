<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasLoopJudgeSelfCalibrationTest extends TestCase
{
    private string $campaignId;

    private string $redCanaryPath = 'tests/Feature/Loop/__AtlasLoopJudgeCalibrationRedFixtureTest.php';

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }

        DB::table('atlas_loop_tasks')->delete();
        DB::table('atlas_loop_campaigns')->delete();

        $this->campaignId = (string) Str::uuid();
        DB::table('atlas_loop_campaigns')->insert([
            'id' => $this->campaignId,
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'l6-2-judge-self-calibration-test',
            'base_workspace' => base_path(),
            'config' => json_encode([]),
            'max_seconds' => 3600,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        File::put(base_path($this->redCanaryPath), <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Tests\TestCase;

final class __AtlasLoopJudgeCalibrationRedFixtureTest extends TestCase
{
    public function test_historical_canary_is_red(): void
    {
        $this->fail('historical RED canary fixture');
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        File::delete(base_path($this->redCanaryPath));
        parent::tearDown();
    }

    public function test_historical_fix_forward_case_compiles_frozen_red_verifier(): void
    {
        $manifest = storage_path('framework/testing/judge-calibration/manifest.json');
        $packetDir = storage_path('framework/testing/judge-calibration/packets');
        File::deleteDirectory(dirname($manifest));
        $this->fixForwardTask(
            'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php',
            $this->redCanaryPath,
        );

        $exit = Artisan::call('atlas:loop:judge-calibration', [
            '--window-hours' => '168',
            '--limit' => '3',
            '--write' => true,
            '--manifest' => $manifest,
            '--packet-dir' => $packetDir,
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('verifier_candidates_ready', $payload['status']);
        $this->assertTrue((bool) $payload['completion_claim_allowed']);
        $this->assertSame(1, data_get($payload, 'counts.ready_verifier_candidates'));
        $this->assertTrue((bool) data_get($payload, 'candidates.0.would_have_caught_case'));
        $this->assertSame('frozen_verifier_ready', data_get($payload, 'candidates.0.status'));
        $this->assertSame('red', data_get($payload, 'candidates.0.verifier.red_preflight_status'));
        $this->assertTrue((bool) data_get($payload, 'candidates.0.verifier.acceptance_revert_recheck'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_calls_made'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.never_merge_changed'));
        $this->assertFileExists($manifest);
        $packetPath = (string) data_get($payload, 'candidates.0.verifier.packet_path');
        $this->assertFileExists($packetPath);
        $packet = json_decode((string) file_get_contents($packetPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('command_output', data_get($packet, 'verification_atoms.0.type'));
        $this->assertSame('red', data_get($packet, 'red_preflight.status'));
        $this->assertTrue((bool) data_get($packet, 'acceptance.revert_recheck'));
    }

    public function test_forbidden_self_target_never_generates_a_calibration_packet(): void
    {
        $this->fixForwardTask(
            'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
            $this->redCanaryPath,
        );

        $exit = Artisan::call('atlas:loop:judge-calibration', [
            '--window-hours' => '168',
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('calibration_unproven', $payload['status']);
        $this->assertContains('forbidden_self_target', $payload['blockers']);
        $this->assertFalse((bool) data_get($payload, 'candidates.0.would_have_caught_case'));
        $this->assertArrayNotHasKey('verifier', $payload['candidates'][0]);
    }

    public function test_schedule_lists_judge_calibration(): void
    {
        $exit = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('atlas:loop:judge-calibration --write --json', $output);
    }

    private function fixForwardTask(string $targetPath, string $canaryTarget): void
    {
        $id = (string) Str::uuid();
        DB::table('atlas_loop_tasks')->insert([
            'id' => $id,
            'campaign_id' => $this->campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => 'pending',
            'source' => 'fix_forward',
            'self_contained' => false,
            'target_path' => $targetPath,
            'objective' => 'fix-forward: canario RED apos auto-merge de '.$targetPath,
            'payload' => json_encode([
                'origin' => 'fix_forward_canary_red',
                'snapshot_tag' => 'atlas-snap-l6-2-fixture',
                'canary_target' => $canaryTarget,
                'merged_proposal_hash' => hash('sha256', 'l6-2-proposal'),
            ]),
            'priority' => 10,
            'attempts' => 0,
            'max_attempts' => 1,
            'dedupe_key' => hash('sha256', $targetPath.'|'.$canaryTarget),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
