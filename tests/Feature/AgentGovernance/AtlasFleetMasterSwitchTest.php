<?php

declare(strict_types=1);

namespace Tests\Feature\AgentGovernance;

use App\Services\Ai\AgentGovernance\AtlasFleetMasterSwitch;
use Tests\TestCase;

/**
 * The fleet-wide gate is the loop's master switch generalized: fail-closed (absent/garbage = OFF), on/off
 * round-trips and never clobbers other .env lines.
 */
final class AtlasFleetMasterSwitchTest extends TestCase
{
    private string $tmpEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpEnv = sys_get_temp_dir().'/atlas-fleet-'.bin2hex(random_bytes(6)).'.env';
        AtlasFleetMasterSwitch::$envPathOverride = $this->tmpEnv;
    }

    protected function tearDown(): void
    {
        AtlasFleetMasterSwitch::$envPathOverride = null;
        @unlink($this->tmpEnv);
        parent::tearDown();
    }

    public function test_absent_file_and_flag_are_off_fail_closed(): void
    {
        @unlink($this->tmpEnv);
        $this->assertFalse(AtlasFleetMasterSwitch::enabled(), 'no .env => OFF');

        file_put_contents($this->tmpEnv, "FOO=bar\n");
        $this->assertFalse(AtlasFleetMasterSwitch::enabled(), 'flag absent => OFF');
    }

    public function test_garbage_and_explicit_false_are_off(): void
    {
        foreach (['false', '0', 'no', 'off', '', 'maybe'] as $v) {
            file_put_contents($this->tmpEnv, "ATLAS_FLEET_ENABLED={$v}\n");
            $this->assertFalse(AtlasFleetMasterSwitch::enabled(), "value '{$v}' must be OFF (fail-closed)");
        }
    }

    public function test_only_explicit_truthy_is_on(): void
    {
        foreach (['true', '1', 'on', 'yes', 'enabled', 'TRUE'] as $v) {
            file_put_contents($this->tmpEnv, "ATLAS_FLEET_ENABLED={$v}\n");
            $this->assertTrue(AtlasFleetMasterSwitch::enabled(), "value '{$v}' must be ON");
        }
    }

    public function test_on_off_round_trip_preserves_other_lines(): void
    {
        file_put_contents($this->tmpEnv, "APP_KEY=secret\nATLAS_OTHER=keep\n");
        $this->assertTrue(AtlasFleetMasterSwitch::on());
        $this->assertTrue(AtlasFleetMasterSwitch::enabled());
        $this->assertSame('on', AtlasFleetMasterSwitch::state());
        $this->assertTrue(AtlasFleetMasterSwitch::off());
        $this->assertFalse(AtlasFleetMasterSwitch::enabled());
        $kept = (string) file_get_contents($this->tmpEnv);
        $this->assertStringContainsString('APP_KEY=secret', $kept);
        $this->assertStringContainsString('ATLAS_OTHER=keep', $kept);
    }
}
