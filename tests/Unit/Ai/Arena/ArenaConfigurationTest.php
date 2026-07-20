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

        // O capability_map do perfil de Capacidades cobre as 3 integradas MAIS as
        // nativas de engenharia — binárias (pass@1, Wilson) e a contínua archbench
        // (rougeL, média + IC normal). Todas arm64.
        $capabilityKeys = array_keys(config('atlas_arena.capability_map'));
        foreach ($expected as $suite) {
            $this->assertContains($suite, $capabilityKeys, $suite.' integrada tem que mapear capacidade');
        }
        foreach (['cruxeval', 'evalplus', 'bigcodebench', 'classeval', 'deveval', 'debug_gym', 'archbench'] as $suite) {
            $this->assertContains($suite, $capabilityKeys, $suite.' nativa deveria aparecer no app');
        }

        // Nenhuma suíte de Docker/x86 pode voltar ao perfil sem x86 real.
        $parked = ['terminal_bench', 'swe_bench_live', 'senior_swe_bench', 'swe_marathon', 'hal_harness'];
        foreach ($parked as $suite) {
            $this->assertNotContains($suite, config('atlas_arena.suites'), $suite.' é Docker/x86 — corrompe no arm64, não pode estar no perfil');
            $this->assertNotContains($suite, $capabilityKeys, $suite.' é Docker/x86 — não pode virar capacidade no perfil');
        }
    }
}
