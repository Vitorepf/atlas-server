<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\HermesCliProvider;
use Tests\TestCase;

/**
 * L3-3: regressão do transporte ACP congelada.
 *
 * O sintoma diff-0 (o transporte acp warm reportava `succeeded` mas devolvia output VAZIO,
 * sem editar nada) era pior que uma falha: o sucesso-falso NÃO disparava o fallback, e o
 * Loop via diff 0. O guard trata "succeeded + output vazio" como fallback-required → cai no
 * CLI provado. Este teste congela o predicado para que a regressão não volte silenciosa, e
 * permite reativar o transporte acp warm com segurança.
 */
final class HermesAcpEmptyOutputFallbackTest extends TestCase
{
    private function provider(): HermesCliProvider
    {
        return app(HermesCliProvider::class);
    }

    public function test_succeeded_but_empty_output_is_treated_as_empty_success_fallback(): void
    {
        config(['atlas.ai.hermes.acp_empty_output_fallback' => true]);
        $p = $this->provider();

        $this->assertTrue($p->acpResultIsEmptySuccess(true, ''), 'succeeded + vazio = fallback (o sintoma diff-0)');
        $this->assertTrue($p->acpResultIsEmptySuccess(true, "   \n  "), 'whitespace-only conta como vazio');
    }

    public function test_succeeded_with_real_content_is_not_a_fallback(): void
    {
        config(['atlas.ai.hermes.acp_empty_output_fallback' => true]);
        $p = $this->provider();

        $this->assertFalse($p->acpResultIsEmptySuccess(true, 'diff --git a/x b/x ...'), 'output real não cai no fallback');
    }

    public function test_a_genuine_acp_failure_is_not_an_empty_success(): void
    {
        config(['atlas.ai.hermes.acp_empty_output_fallback' => true]);
        $p = $this->provider();

        // Uma falha de verdade (ok=false) já dispara o fallback pelo caminho normal — este
        // guard é especificamente para o SUCESSO-falso, então não deve reivindicar ok=false.
        $this->assertFalse($p->acpResultIsEmptySuccess(false, ''), 'falha real não é "empty success"');
    }

    public function test_flag_off_disables_the_guard_restoring_old_behavior(): void
    {
        config(['atlas.ai.hermes.acp_empty_output_fallback' => false]);
        $p = $this->provider();

        $this->assertFalse($p->acpResultIsEmptySuccess(true, ''), 'flag OFF restaura o comportamento antigo (sem guard)');
    }
}
