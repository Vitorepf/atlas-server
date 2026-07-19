<?php

namespace Tests\Unit\Ai\Arena;

use Tests\TestCase;

class ArenaConfigurationTest extends TestCase
{
    public function test_operator_mission_keeps_all_ten_external_suites_in_the_profile(): void
    {
        $expected = [
            'terminal_bench',
            'inspect_evals',
            'tau2_bench',
            'bfcl',
            'senior_swe_bench',
            'swe_bench_live',
            'live_code_bench',
            'hal_harness',
            'aider_polyglot',
            'swe_marathon',
        ];

        $this->assertSame($expected, config('atlas_arena.suites'));
        $this->assertSame($expected, array_keys(config('atlas_arena.weights')));
        $this->assertEqualsWithDelta(1.0, array_sum(config('atlas_arena.weights')), 0.000001);
        $this->assertSame($expected, array_keys(config('atlas_arena.capability_map')));
    }
}
