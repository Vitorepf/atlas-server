<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use ReflectionClass;
use Tests\TestCase;

/**
 * Pins the INERT-BY-DEFAULT invariant of the characterization-test lane routing: the new cert branch
 * must never fire for the running soak. It activates only when (a) the dedicated objective_kind is
 * present AND (b) the config flag is ON AND (c) the gap descriptor (target + operator) is complete.
 */
final class AtlasLoopGrinderCharacterizationGateTest extends TestCase
{
    private function isCharTask(array $payload): bool
    {
        $grinder = (new ReflectionClass(AtlasLoopTaskGrinder::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($grinder, 'isCharacterizationTestTask');

        return (bool) $m->invoke($grinder, $payload);
    }

    private function completePayload(): array
    {
        return [
            'objective_kind' => 'characterization_test',
            'characterization_target' => 'app/Services/Ai/X.php',
            'characterization_operator' => 'strict_equals',
            'characterization_sibling_test' => 'tests/Unit/Ai/XTest.php',
        ];
    }

    public function test_flag_off_is_inert_even_with_a_complete_characterization_payload(): void
    {
        config(['atlas.loop.characterization_test_lane_enabled' => false]);
        $this->assertFalse($this->isCharTask($this->completePayload()), 'flag OFF must keep the lane dormant');
    }

    public function test_flag_on_with_complete_payload_activates(): void
    {
        config(['atlas.loop.characterization_test_lane_enabled' => true]);
        $this->assertTrue($this->isCharTask($this->completePayload()));
    }

    public function test_flag_on_but_wrong_kind_or_incomplete_payload_stays_inert(): void
    {
        config(['atlas.loop.characterization_test_lane_enabled' => true]);
        // wrong objective_kind => a normal refactor/vanilla task is never hijacked
        $this->assertFalse($this->isCharTask(['objective_kind' => 'refactor_reduce_complexity', 'characterization_target' => 'a', 'characterization_operator' => 'b']));
        // missing target
        $p = $this->completePayload();
        unset($p['characterization_target']);
        $this->assertFalse($this->isCharTask($p));
        // missing operator
        $p = $this->completePayload();
        unset($p['characterization_operator']);
        $this->assertFalse($this->isCharTask($p));
    }
}
