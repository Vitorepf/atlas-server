<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * L2-14 (capstone Forge): a cadeia REAL de execução do Forge é fail-closed por
 * construção — sem uma Obra vinculada, NENHUM provider externo é contatado e o status é
 * `blocked`. Isto congela a propriedade de segurança que torna o Forge seguro para a
 * obra pesada real (a prova-live completa é o run operator-gated): a porta nunca abre
 * sozinha. O dispatch governado idem (prepare_dispatch_plan nunca executa provider).
 */
final class AtlasForgeLiveChainFailClosedTest extends TestCase
{
    public function test_live_execute_is_fail_closed_without_an_obra(): void
    {
        $out = new BufferedOutput;
        Artisan::call('atlas:forge:live-execute', ['--json' => true], $out);
        $report = json_decode($out->fetch(), true);

        $this->assertIsArray($report);
        $this->assertSame('blocked', $report['forge_live_execution_status'], 'sem obra, a execução live é bloqueada');
        $this->assertFalse((bool) $report['external_provider_call'], 'nenhum provider externo é contatado');
        $this->assertContains('obra_required', (array) ($report['remaining_blockers'] ?? []), 'o blocker honesto é a obra ausente');
    }

    public function test_runtime_dispatch_prepare_plan_never_calls_a_provider(): void
    {
        $out = new BufferedOutput;
        Artisan::call('atlas:forge:runtime-dispatch', [
            '--role' => 'primary_builder',
            '--execution-mode' => 'prepare_dispatch_plan',
            '--json' => true,
        ], $out);
        $report = json_decode($out->fetch(), true);

        $this->assertIsArray($report);
        // O modo prepare_dispatch_plan é, por contrato, incapaz de chamar provider externo.
        $this->assertFalse((bool) data_get($report, 'external_provider_call', false));
    }
}
