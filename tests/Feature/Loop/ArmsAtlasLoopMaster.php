<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;

/**
 * Test support: arm the §0 master switch ON for tests that exercise the loop's ACTIVE path (keepalive
 * revive/reap, campaign launch, etc.). Since the master switch defaults FAIL-CLOSED (OFF), any test that
 * proves the loop DOES something must explicitly enable it — mirroring the operator running `atlas:loop:on`.
 * Points the switch at a throwaway .env so the real .env is never touched.
 */
trait ArmsAtlasLoopMaster
{
    private ?string $loopMasterTmpEnv = null;

    protected function armLoopMasterOn(): void
    {
        $this->loopMasterTmpEnv = sys_get_temp_dir().'/atlas-master-arm-'.bin2hex(random_bytes(6)).'.env';
        file_put_contents($this->loopMasterTmpEnv, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->loopMasterTmpEnv;
    }

    protected function disarmLoopMaster(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        if ($this->loopMasterTmpEnv !== null) {
            @unlink($this->loopMasterTmpEnv);
            $this->loopMasterTmpEnv = null;
        }
    }
}
