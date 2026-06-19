<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopRobustnessProbe;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 3 · Slice 2 — the robustness probe catches a lock downgrade BEHAVIORALLY (not by grep):
 * the real LOCK_EX actuator serializes two racing processes; a LOCK_EX→LOCK_SH blinder lets both into the
 * critical section at once, which the probe observes as an interleave. The probe runs the CANDIDATE bytes.
 */
final class AtlasLoopRobustnessProbeTest extends TestCase
{
    private const ACTUATOR = 'app/Services/Ai/AutonomousEvolution/Constitution/AtlasLoopMergeActuator.php';

    public function test_the_real_exclusive_actuator_holds(): void
    {
        $res = (new AtlasLoopRobustnessProbe())->lockIsExclusive(base_path(self::ACTUATOR));
        $this->assertTrue($res['held'], 'LOCK_EX serializes the race: '.json_encode($res['lines']));
        $this->assertSame('serialized_exclusive', $res['reason']);
    }

    public function test_a_lock_sh_downgrade_is_caught_as_an_interleave(): void
    {
        // The candidate-bytes blinder: a copy of the actuator with the exclusive lock downgraded to shared.
        $mutated = sys_get_temp_dir().'/AtlasLoopMergeActuator_blinded_'.bin2hex(random_bytes(4)).'.php';
        $src = str_replace('LOCK_EX', 'LOCK_SH', (string) file_get_contents(base_path(self::ACTUATOR)));
        file_put_contents($mutated, $src);

        try {
            $res = (new AtlasLoopRobustnessProbe())->lockIsExclusive($mutated);
            $this->assertFalse($res['held'], 'a shared lock lets both processes in: '.json_encode($res['lines']));
            $this->assertStringContainsString('interleave', $res['reason']);
        } finally {
            @unlink($mutated);
        }
    }

    public function test_a_missing_actuator_file_is_not_held(): void
    {
        $res = (new AtlasLoopRobustnessProbe())->lockIsExclusive('/no/such/actuator.php');
        $this->assertFalse($res['held']);
        $this->assertSame('actuator_file_missing', $res['reason']);
    }
}
