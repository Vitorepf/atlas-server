<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Frozen proof of the read-only `atlas:brain:state` snapshot. Proves the JSON shape, that done-set tail
 * counts reflect the ledger, that no origination happens (the command is non-mutating), and that the
 * state command itself is pétreo (a brain entry-point that ledgers + reads — réu never edits its own
 * observability seam).
 */
final class AtlasBrainStateCommandTest extends TestCase
{
    private string $doneSetRoot;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/atlas-brain-state-'.bin2hex(random_bytes(6));
        $this->doneSetRoot = $base.'/done-set';
        @mkdir($this->doneSetRoot, 0o775, true);
        config()->set('atlas.brain.done_set_root', $this->doneSetRoot);
        config()->set('atlas.brain.default_scope', 'loop');
        config()->set('atlas.brain.scopes.loop', [
            'label' => 'test',
            'roots' => ['app/Services/Ai/AutonomousEvolution'],
            'docs_roots' => [],
            'meta_harness' => true,
        ]);

        $this->envPath = $base.'/.env';
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");
        AtlasBrainMasterSwitch::$envPathOverride = $this->envPath;
    }

    protected function tearDown(): void
    {
        AtlasBrainMasterSwitch::$envPathOverride = null;
        parent::tearDown();
    }

    public function test_state_shape_includes_master_switch_scope_and_tails(): void
    {
        // Seed the done-set with a few rows so tail counts are non-zero.
        $ledger = new AtlasBrainDoneSetLedger('loop', $this->doneSetRoot);
        $ledger->record(['snapshot_id' => 's', 'status' => 'served', 'produced' => true, 'action' => 'origin', 'target_path' => 'A.php', 'task_packet_id' => 'p1', 'refusal' => false]);
        $ledger->record(['snapshot_id' => 's', 'status' => 'refused', 'produced' => false, 'action' => 'origin', 'target_path' => 'B.php', 'task_packet_id' => 'p2', 'refusal' => true]);

        Artisan::call('atlas:brain:state', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertTrue($payload['brain_enabled']);
        self::assertSame('loop', $payload['default_scope']);
        self::assertSame('loop', $payload['scope']['slug']);
        self::assertTrue($payload['scope']['meta_harness']);
        self::assertSame(2, $payload['done_set']['recent_count']);
        self::assertSame(1, $payload['done_set']['recent_served']);
        self::assertSame(1, $payload['done_set']['recent_refused']);
        self::assertArrayHasKey('reflection', $payload);
    }

    public function test_state_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Console/Commands/AtlasBrainStateCommand.php',
            true
        );

        self::assertSame('forbidden', $verdict);
    }
}
