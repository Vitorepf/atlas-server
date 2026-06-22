<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * §0 · THE MASTER ON/OFF SWITCH — the definitive fix for the auto-respawn token-burn. Proves: the switch is
 * fail-closed (absent/garbage = OFF); on/off round-trips and preserves the rest of the .env; the loop can
 * never edit the switch (pétreo); and — the keystone — with master OFF the keepalive and campaign commands are
 * no-ops (keepalive respawns nothing, campaign refuses to launch, the supervisor is never even constructed-into).
 */
final class AtlasLoopMasterSwitchTest extends TestCase
{
    private string $tmpEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpEnv = sys_get_temp_dir().'/atlas-master-'.bin2hex(random_bytes(6)).'.env';
        AtlasLoopMasterSwitch::$envPathOverride = $this->tmpEnv;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->tmpEnv);
        parent::tearDown();
    }

    private function writeEnv(string $contents): void
    {
        file_put_contents($this->tmpEnv, $contents);
    }

    // ── the switch itself: FAIL-CLOSED ──────────────────────────────────────────────────────────────────

    public function test_absent_env_file_is_off_fail_closed(): void
    {
        @unlink($this->tmpEnv); // no file at all
        $this->assertFalse(AtlasLoopMasterSwitch::enabled(), 'no .env => OFF');
    }

    public function test_absent_flag_is_off_fail_closed(): void
    {
        $this->writeEnv("FOO=bar\nBAZ=qux\n"); // file exists, flag absent
        $this->assertFalse(AtlasLoopMasterSwitch::enabled(), 'flag absent => OFF');
    }

    public function test_explicit_false_and_garbage_are_off(): void
    {
        foreach (['false', 'False', '0', 'no', 'off', 'maybe', '', 'truthy-ish'] as $v) {
            $this->writeEnv("ATLAS_LOOP_MASTER_ENABLED={$v}\n");
            $this->assertFalse(AtlasLoopMasterSwitch::enabled(), "value '{$v}' must be OFF (fail-closed)");
        }
    }

    public function test_only_explicit_truthy_values_are_on(): void
    {
        foreach (['true', '1', 'on', 'yes', 'enabled', 'TRUE', 'On', '"true"'] as $v) {
            $this->writeEnv("ATLAS_LOOP_MASTER_ENABLED={$v}\n");
            $this->assertTrue(AtlasLoopMasterSwitch::enabled(), "value '{$v}' must be ON");
        }
    }

    public function test_on_off_round_trip_preserves_other_env_lines(): void
    {
        $this->writeEnv("APP_KEY=secret\nATLAS_OTHER=keep\n");
        $this->assertTrue(AtlasLoopMasterSwitch::on());
        $this->assertTrue(AtlasLoopMasterSwitch::enabled());
        $this->assertTrue(AtlasLoopMasterSwitch::off());
        $this->assertFalse(AtlasLoopMasterSwitch::enabled());
        $kept = (string) file_get_contents($this->tmpEnv);
        $this->assertStringContainsString('APP_KEY=secret', $kept, 'on/off must never clobber other secrets');
        $this->assertStringContainsString('ATLAS_OTHER=keep', $kept);
    }

    // ── PÉTREO: the loop can never edit the switch (never re-enable itself) ──────────────────────────────

    public function test_master_switch_is_petreo_in_the_constitution(): void
    {
        $this->assertTrue(
            (new AtlasLoopHarnessGuard)->isForbiddenSelfTarget('app/Services/Ai/AutonomousEvolution/AtlasLoopMasterSwitch.php'),
            'the loop must never be able to edit its own master switch',
        );
    }

    // ── KEYSTONE: master OFF => every auto-start command is a no-op ──────────────────────────────────────

    public function test_keepalive_respawns_nothing_when_master_off(): void
    {
        $this->writeEnv("ATLAS_LOOP_MASTER_ENABLED=false\n");

        $code = Artisan::call('atlas:loop:keepalive', ['--json' => true]);
        $this->assertSame(0, $code, 'clean no-op exit (never an error that triggers retry-storms)');

        $out = json_decode(trim(Artisan::output()), true);
        $this->assertSame('off', $out['master'] ?? null, 'keepalive short-circuits on master OFF');
        $this->assertSame(0, $out['checked'] ?? -1, 'it never even queries for stuck campaigns');
        $this->assertSame([], $out['respawned'] ?? null, 'it respawns NOTHING');
    }

    public function test_campaign_refuses_to_launch_when_master_off(): void
    {
        $this->writeEnv("ATLAS_LOOP_MASTER_ENABLED=false\n");

        // The supervisor is final (cannot be mocked). The guard returns at the FIRST line of handle() — before
        // any supervisor method runs — so `launched:false` is the proof no campaign/grind was started.
        $code = Artisan::call('atlas:loop:campaign', []);
        $this->assertSame(0, $code, 'clean no-op exit (never an error that triggers a retry-storm)');

        $out = json_decode(trim(Artisan::output()), true);
        $this->assertSame('off', $out['master'] ?? null, 'campaign short-circuits on master OFF');
        $this->assertFalse($out['launched'] ?? true, 'no supervisor, no grind workers — nothing launched');
    }
}
