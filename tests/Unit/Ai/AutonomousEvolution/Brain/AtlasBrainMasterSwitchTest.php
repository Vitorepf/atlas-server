<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use Tests\TestCase;

/**
 * §0 · THE BRAIN MASTER ON/OFF SWITCH — proves it is fail-closed (absent/garbage = OFF), that an explicit
 * truthy .env flag flips it ON, that on/off round-trips without clobbering other secrets, and that the brain
 * can never edit its own switch (pétreo). Independent of the loop/serving switches.
 */
final class AtlasBrainMasterSwitchTest extends TestCase
{
    private string $tmpEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpEnv = sys_get_temp_dir().'/atlas-brain-master-'.bin2hex(random_bytes(6)).'.env';
        AtlasBrainMasterSwitch::$envPathOverride = $this->tmpEnv;
    }

    protected function tearDown(): void
    {
        AtlasBrainMasterSwitch::$envPathOverride = null;
        @unlink($this->tmpEnv);
        parent::tearDown();
    }

    private function writeEnv(string $contents): void
    {
        file_put_contents($this->tmpEnv, $contents);
    }

    public function test_default_off_when_flag_absent(): void
    {
        @unlink($this->tmpEnv); // no file at all
        self::assertFalse(AtlasBrainMasterSwitch::enabled(), 'no .env => OFF');

        $this->writeEnv("FOO=bar\n"); // file exists, flag absent
        self::assertFalse(AtlasBrainMasterSwitch::enabled(), 'flag absent => OFF');
        self::assertSame('off', AtlasBrainMasterSwitch::state());
    }

    public function test_explicit_false_and_garbage_are_off(): void
    {
        foreach (['false', 'False', '0', 'no', 'off', 'maybe', ''] as $v) {
            $this->writeEnv("ATLAS_BRAIN_MASTER_ENABLED={$v}\n");
            self::assertFalse(AtlasBrainMasterSwitch::enabled(), "value '{$v}' must be OFF (fail-closed)");
        }
    }

    public function test_explicit_truthy_env_flips_it_on(): void
    {
        foreach (['true', '1', 'on', 'yes', 'enabled', 'TRUE', '"true"'] as $v) {
            $this->writeEnv("ATLAS_BRAIN_MASTER_ENABLED={$v}\n");
            self::assertTrue(AtlasBrainMasterSwitch::enabled(), "value '{$v}' must be ON");
        }
        self::assertSame('on', AtlasBrainMasterSwitch::state());
    }

    public function test_on_off_round_trip_preserves_other_env_lines(): void
    {
        $this->writeEnv("APP_KEY=secret\nATLAS_OTHER=keep\n");
        self::assertTrue(AtlasBrainMasterSwitch::on());
        self::assertTrue(AtlasBrainMasterSwitch::enabled());
        self::assertTrue(AtlasBrainMasterSwitch::off());
        self::assertFalse(AtlasBrainMasterSwitch::enabled());
        $kept = (string) file_get_contents($this->tmpEnv);
        self::assertStringContainsString('APP_KEY=secret', $kept, 'on/off must never clobber other secrets');
        self::assertStringContainsString('ATLAS_OTHER=keep', $kept);
    }

    public function test_brain_switch_is_petreo_in_the_constitution(): void
    {
        self::assertTrue(
            (new AtlasLoopHarnessGuard)->isForbiddenSelfTarget('app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainMasterSwitch.php'),
            'the brain must never be able to edit its own master switch',
        );
    }
}
