<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use ReflectionMethod;
use Tests\TestCase;

/**
 * INDEPENDÊNCIA 24h+: o timeout de UM grind era o BUDGET INTEIRO da campanha (até 7 dias).
 * Uma chamada de provider travada congelaria o supervisor por dias — e o keepalive NÃO pega
 * (o processo está vivo, só preso, sem atualizar heartbeat). O cap por-task (default 1800s)
 * garante que nenhum grind único segure o loop, sem violar o budget total.
 */
final class AtlasLoopTaskTimeoutCapTest extends TestCase
{
    private function timeout(?int $budgetLeft, int $cap): int
    {
        $m = new ReflectionMethod(AtlasLoopCampaignSupervisor::class, 'grindTimeout');
        $m->setAccessible(true);

        return (int) $m->invoke(app(AtlasLoopCampaignSupervisor::class), $budgetLeft, $cap);
    }

    public function test_huge_budget_is_capped_at_the_per_task_limit(): void
    {
        // Budget de 7 dias, cap de 30min → o grind recebe 1800, NÃO 604800.
        $this->assertSame(1800, $this->timeout(604800, 1800), 'um grind nunca segura o loop por dias');
    }

    public function test_small_remaining_budget_wins_over_the_cap(): void
    {
        // Perto do fim do budget, o grind não pode passar do que resta.
        $this->assertSame(120, $this->timeout(120, 1800), 'usa o menor: o budget restante');
    }

    public function test_null_budget_uses_the_cap(): void
    {
        $this->assertSame(1800, $this->timeout(null, 1800), 'sem budget definido, só o cap por-task');
    }

    public function test_cap_has_a_safety_floor(): void
    {
        // Um cap absurdamente baixo é elevado ao piso de 60s (não estrangula o grind).
        $this->assertSame(60, $this->timeout(null, 1), 'cap mínimo de 60s');
    }
}
