<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Gateway;

use App\Services\Ai\Gateway\ChatWeakResponseProbe;
use PHPUnit\Framework\TestCase;

final class ChatWeakResponseProbeTest extends TestCase
{
    private function probe(): ChatWeakResponseProbe
    {
        return new ChatWeakResponseProbe;
    }

    /** @return array<string,mixed> */
    private function debugExecution(): array
    {
        return [
            'flow_id' => 'atlas_debug',
            'handler_id' => 'atlas_debug_triage_handler',
            'response_shape' => ['symptoms', 'likely_causes', 'missing_evidence', 'next_debug_steps'],
        ];
    }

    public function test_empty_response_is_weak(): void
    {
        $r = $this->probe()->inspect("   \n", $this->debugExecution());

        $this->assertTrue($r['weak']);
        $this->assertSame(['empty_response'], $r['reasons']);
    }

    public function test_healthy_prose_response_is_not_weak(): void
    {
        $text = 'Os sintomas apontam para uma corrida de inicialização: o serviço lê a configuração '
            .'antes do bootstrap terminar. Próximo passo de diagnóstico: rodar o teste de integração '
            .'com o log de boot habilitado e comparar a ordem dos eventos.';

        $r = $this->probe()->inspect($text, $this->debugExecution());

        $this->assertFalse($r['weak'], json_encode($r));
    }

    public function test_literal_multiword_contract_identifier_in_body_is_flagged(): void
    {
        $text = 'likely_causes: configuração ausente. missing_evidence: nenhum log anexado ainda, '
            .'preciso do stacktrace completo para confirmar a causa raiz do problema relatado.';

        $r = $this->probe()->inspect($text, $this->debugExecution());

        $this->assertTrue($r['weak']);
        $this->assertContains('literal_contract_identifier:likely_causes', $r['reasons']);
        $this->assertContains('literal_contract_identifier:missing_evidence', $r['reasons']);
    }

    public function test_single_word_shape_id_never_collides_with_prose(): void
    {
        // 'symptoms' is a single-word id — English prose containing it is legit.
        $text = 'The symptoms indicate a race condition during boot; collect the ordered event log '
            .'and rerun the failing integration test to confirm before changing any code.';

        $r = $this->probe()->inspect($text, $this->debugExecution());

        $this->assertFalse($r['weak'], json_encode($r));
    }

    public function test_placeholder_marker_is_flagged_but_ptbr_todo_word_is_not(): void
    {
        $weak = $this->probe()->inspect(
            'TODO: investigar depois. A causa provável ainda não foi confirmada pelos logs disponíveis.',
            $this->debugExecution(),
        );
        $this->assertTrue($weak['weak']);
        $this->assertContains('placeholder_marker_in_response', $weak['reasons']);

        $clean = $this->probe()->inspect(
            'Analisei todo o fluxo de boot e o método de inicialização: a ordem dos eventos está '
            .'correta e o problema está na leitura antecipada da configuração pelo worker.',
            $this->debugExecution(),
        );
        $this->assertFalse($clean['weak'], json_encode($clean));
    }

    public function test_short_response_is_weak_only_for_specialist_flows(): void
    {
        $short = 'ok, feito.';

        $withContract = $this->probe()->inspect($short, $this->debugExecution());
        $this->assertTrue($withContract['weak']);
        $this->assertContains('suspiciously_short_for_specialist_flow', $withContract['reasons']);

        // Plain conversation (or no contract at all): short answers are fine.
        $conversation = $this->probe()->inspect($short, ['flow_id' => 'atlas_conversation']);
        $this->assertFalse($conversation['weak']);
        $noContract = $this->probe()->inspect($short, []);
        $this->assertFalse($noContract['weak']);
    }

    public function test_leaked_model_markup_flags_weak_on_raw_output(): void
    {
        // Incidente 02/07: rota sem harness de tools vaza <antThinking> e
        // pseudo-tool-calls como texto. O flag roda no output CRU e derruba o
        // quality_score da rota no ledger ADML.
        $raw = 'Vou verificar. <antThinking>internal</antThinking> '
            .'<toolcodeinterpreter(code="import subprocess")>x</toolcodeinterpreter> fim.';

        $result = $this->probe()->inspect($raw, []);

        $this->assertTrue($result['weak']);
        $this->assertContains('leaked_model_markup', $result['reasons']);

        // Prosa legítima mencionando tools/thinking NÃO dispara.
        $clean = $this->probe()->inspect('O harness de tools do Atlas usa thinking estruturado.', []);
        $this->assertFalse($clean['weak']);
    }
}
