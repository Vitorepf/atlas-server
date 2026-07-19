<?php

namespace Tests\Unit\Ai\Arena;

use Tests\TestCase;

class ArenaConfigurationTest extends TestCase
{
    public function test_profile_is_native_arm64_only_no_docker_x86_suites(): void
    {
        // Decisão do operador (2026-07-19): o perfil só mede o que roda NATIVO e
        // confiável no Mac arm64 com braço Atlas real. As suítes de Docker/x86
        // (terminal_bench, swe_bench_live, senior_swe_bench, swe_marathon,
        // hal_harness) corrompem o resultado por emulação e ficam estacionadas.
        // inspect/tau2 (não-código, sem braço Atlas) também saem. Ver
        // benchmarks/ e memória swe-bench-arm64-emulacao.
        $expected = [
            'bfcl',
            'live_code_bench',
            'aider_polyglot',
        ];

        $this->assertSame($expected, config('atlas_arena.suites'));
        $this->assertEqualsWithDelta(1.0, array_sum(config('atlas_arena.weights')), 0.000001);
        $this->assertSame($expected, array_keys(config('atlas_arena.capability_map')));

        // Nenhuma suíte de Docker/x86 pode voltar ao perfil sem x86 real.
        $parked = ['terminal_bench', 'swe_bench_live', 'senior_swe_bench', 'swe_marathon', 'hal_harness'];
        foreach ($parked as $suite) {
            $this->assertNotContains($suite, config('atlas_arena.suites'), $suite.' é Docker/x86 — corrompe no arm64, não pode estar no perfil');
        }
    }
}
