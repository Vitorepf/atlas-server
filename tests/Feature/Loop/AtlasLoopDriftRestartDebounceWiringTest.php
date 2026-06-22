<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopKeepaliveCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LOOP-OS Slice 13 WIRING proof: the EXTERNAL watchdog (keepalive command, never the supervisor
 * itself) consults AtlasLoopDriftRestartDebounce before a code-drift recycle. The class guarantees
 * (1) at most one restart per window and (2) only when real drift landed (≥N self-merges to main
 * since this supervisor booted). This test forces the alive+drift recycle path (supervisor alive,
 * engine commit newer than boot, zero in-flight grinds) and proves the debounce gate actually
 * suppresses the kill+respawn, instead of the previously-unconditional recycle.
 */
final class AtlasLoopDriftRestartDebounceWiringTest extends TestCase
{
    use ArmsAtlasLoopMaster;

    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        // §0 master switch defaults OFF (fail-closed) — arm ON to exercise the keepalive's active drift path.
        $this->armLoopMasterOn();
        $this->beforeApplicationDestroyed(fn () => $this->disarmLoopMaster());
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        // Keep the unrelated passes inert; force the debounce ON with a 600s window / 1 merge.
        config([
            'atlas.loop.keepalive_revive_starved' => false,
            'atlas.loop.keepalive_reap_after_minutes' => 1440,
            'atlas.loop.keepalive_frozen_kill_minutes' => 100000, // never trip the frozen-alive killer
            'atlas.loop.campaign.restart_on_code_drift' => true,
            'atlas.loop.keepalive_code_drift_grace_seconds' => 5, // floored to 60 by the command
            'atlas.loop.drift_restart_debounce_enabled' => true,
            'atlas.loop.drift_restart_debounce_window_seconds' => 600,
            'atlas.loop.drift_restart_debounce_min_self_merges' => 1,
        ]);

        // A real temp git repo whose newest ENGINE commit drives latestPipelineCommitEpoch().
        // Commit time is FIXED far in the past relative to "now" but we pick bootEpoch < commit
        // so shouldRecycle() fires; per-case bootEpoch is injected via the harness override.
        $this->workspace = sys_get_temp_dir().'/atlas-drift-debounce-'.Str::random(8);
        @mkdir($this->workspace.'/app/Services/Ai/AutonomousEvolution', 0777, true);
        file_put_contents($this->workspace.'/app/Services/Ai/AutonomousEvolution/DriftMarker.php', '<?php // engine');
        $this->git('init -q');
        $this->git('config user.email t@t.com');
        $this->git('config user.name t');
        $this->git('add -A');
        // Engine commit anchored at NOW (commit epoch ≈ time()); any bootEpoch in the past is older.
        $this->git('commit -qm "engine change"');
    }

    protected function tearDown(): void
    {
        if ($this->workspace !== '' && is_dir($this->workspace)) {
            (new \Symfony\Component\Process\Process(['rm', '-rf', $this->workspace]))->run();
        }
        parent::tearDown();
    }

    private function git(string $args): void
    {
        $p = \Symfony\Component\Process\Process::fromShellCommandline('git -C '.escapeshellarg($this->workspace).' '.$args);
        $p->run();
    }

    private function seedRunning(int $bootEpoch): string
    {
        $id = (string) Str::uuid();
        DB::table('atlas_loop_campaigns')->insert([
            'id' => $id,
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'drift-debounce-test',
            'status' => 'running',
            'stop_reason' => null,
            'max_seconds' => 0,
            'elapsed_seconds' => 100,
            'kill_switch' => false,
            'config' => '{}',
            'base_workspace' => $this->workspace,
            // FRESH heartbeat so the frozen-alive killer and the reaper/respawn paths below never fire;
            // ONLY the drift-recycle branch (which keys off process start vs commit) is reachable.
            'heartbeat_at' => now(),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedMergedProposal(string $campaignId, \DateTimeInterface $updatedAt): void
    {
        DB::table('atlas_loop_proposals')->insert([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaignId,
            'task_id' => null,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => 'certified_for_review',
            'objective' => 'a self-merge landed',
            'provider' => 'minimax',
            'target_path' => 'app/Services/Ai/AutonomousEvolution/X.php',
            'diff_text' => 'diff',
            'proposal_hash' => substr(hash('sha256', Str::random()), 0, 64),
            'merged_to_main' => true, // sqlite test DB: the Postgres CHECK/trigger does not apply
            'scenarios_explored' => 1,
            'scenarios_accepted' => 1,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    /** Drift-recycle path forced: alive supervisor, controllable bootEpoch, zero in-flight grinds. */
    private function runKeepalive(int $bootEpoch): array
    {
        $cmd = new class($bootEpoch) extends AtlasLoopKeepaliveCommand
        {
            public array $respawned = [];

            public array $killed = [];

            public function __construct(private int $bootEpochStub)
            {
                parent::__construct();
            }

            protected function respawn(string $campaignId): void
            {
                $this->respawned[] = $campaignId;
            }

            protected function killSupervisor(string $campaignId): void
            {
                $this->killed[] = $campaignId;
            }

            protected function supervisorAlive(string $campaignId): bool
            {
                return true; // so the drift block runs
            }

            protected function supervisorStartedAt(string $campaignId): ?int
            {
                return $this->bootEpochStub;
            }
        };
        $cmd->setLaravel(app());
        $captured = [];
        $cmd->run(new \Symfony\Component\Console\Input\ArrayInput(['--json' => true]), new class($captured) extends \Symfony\Component\Console\Output\Output
        {
            public function __construct(private array &$cap)
            {
                parent::__construct();
            }

            protected function doWrite(string $message, bool $newline): void
            {
                $this->cap[] = $message;
            }
        });

        return [$cmd, json_decode(implode("\n", $captured), true) ?: []];
    }

    public function test_A_within_window_with_self_merge_is_debounced_not_respawned(): void
    {
        // bootEpoch 90s ago => aliveSeconds≈90 (≥ grace 60, < window 600) and the engine commit
        // (≈now) is newer than boot => shouldRecycle() fires. One self-merge landed AFTER boot.
        $bootEpoch = time() - 90;
        $id = $this->seedRunning($bootEpoch);
        $this->seedMergedProposal($id, now()->subSeconds(30)); // after boot

        [$cmd, $out] = $this->runKeepalive($bootEpoch);

        $this->assertNotContains($id, $cmd->respawned, 'within the debounce window the drift recycle is suppressed');
        $this->assertNotContains($id, $cmd->killed, 'the supervisor is NOT killed inside the window');
        $reasons = array_column($out['drift_debounced'] ?? [], 'reason', 'campaign_id');
        $this->assertArrayHasKey($id, $reasons, 'debounce decision recorded for this campaign');
        $this->assertStringStartsWith('debounced', $reasons[$id], 'reason is the within-window debounce');
    }

    public function test_B_window_elapsed_but_no_self_merge_is_no_drift_not_respawned(): void
    {
        // bootEpoch 800s ago => aliveSeconds≈800 (> window 600), commit newer than boot =>
        // shouldRecycle() fires. ZERO merged proposals after boot => warranted-gate blocks it.
        $bootEpoch = time() - 800;
        $id = $this->seedRunning($bootEpoch);
        // No merged proposal at all (and an OLD one before boot must NOT count):
        $this->seedMergedProposal($id, now()->subSeconds(1000)); // before boot — excluded by updated_at filter

        [$cmd, $out] = $this->runKeepalive($bootEpoch);

        $this->assertNotContains($id, $cmd->respawned, 'no drift landed since boot => no recycle');
        $this->assertNotContains($id, $cmd->killed, 'no kill without a real self-merge');
        $reasons = array_column($out['drift_debounced'] ?? [], 'reason', 'campaign_id');
        $this->assertArrayHasKey($id, $reasons, 'debounce decision recorded');
        $this->assertStringStartsWith('no_drift', $reasons[$id], 'reason is no_drift (window elapsed, zero merges)');
    }

    public function test_C_window_elapsed_with_self_merge_IS_respawned(): void
    {
        // bootEpoch 800s ago (> window 600) AND ≥1 merge after boot => allowed => real recycle.
        $bootEpoch = time() - 800;
        $id = $this->seedRunning($bootEpoch);
        $this->seedMergedProposal($id, now()->subSeconds(120)); // after boot

        [$cmd, $out] = $this->runKeepalive($bootEpoch);

        $this->assertContains($id, $cmd->respawned, 'window elapsed + real drift => the supervisor IS recycled');
        $this->assertContains($id, $cmd->killed, 'and the stale supervisor is killed first');
        $reasons = array_column($out['respawned'] ?? [], 'reason', 'campaign_id');
        $this->assertSame('code_drift_recycled', $reasons[$id] ?? null, 'recycle reason is code_drift_recycled');
        $this->assertEmpty($out['drift_debounced'] ?? [], 'no debounce record when the recycle is allowed');
    }
}
