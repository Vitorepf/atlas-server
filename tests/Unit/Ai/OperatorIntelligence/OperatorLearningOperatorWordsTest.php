<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\OperatorLearningRuntimeCaptureService;
use PHPUnit\Framework\TestCase;

/**
 * O Atlas aprende quem o operador é. Se ele aprender das próprias frases, a
 * memória do operador vira o eco da máquina — e volta como "regra dele" para
 * sempre, num sistema cuja tese inteira é soberania pessoal.
 *
 * Aconteceu de verdade: 8 dos 13 sinais gravados eram prosa do coletor
 * determinístico atribuída ao operador.
 */
final class OperatorLearningOperatorWordsTest extends TestCase
{
    public function test_the_machine_prose_prefixed_to_the_wire_is_not_the_operator_speaking(): void
    {
        $dossie = "[Fatos lidos do git de atlas-server agora…]\n23 exceções: 18 worktrees fora do lugar.";
        $fio = $dossie."\n\ntem algum problema?";

        self::assertSame(
            'tem algum problema?',
            OperatorLearningRuntimeCaptureService::operatorWords($fio, [
                'payload' => ['operator_text' => 'tem algum problema?'],
            ]),
            'o que a superfície anexou nunca é a voz do operador'
        );
    }

    public function test_without_the_surface_saying_the_wire_text_still_counts(): void
    {
        // Ausência não vira silêncio inventado: superfície que não diz o que o
        // operador digitou (CLI, MCP, qualquer porta futura) continua sendo
        // aprendida pelo input_text, que é o melhor que existe ali.
        self::assertSame(
            'sempre rode os testes antes de commitar',
            OperatorLearningRuntimeCaptureService::operatorWords('sempre rode os testes antes de commitar', [])
        );
    }

    public function test_an_empty_claim_of_operator_text_does_not_erase_the_operator(): void
    {
        // Superfície mandando vazio (bug dela) não pode APAGAR o operador: seria
        // trocar uma contaminação por um emudecimento.
        foreach ([['payload' => ['operator_text' => '   ']], ['payload' => ['operator_text' => null]], ['payload' => []]] as $options) {
            self::assertSame(
                'o que eu escrevi',
                OperatorLearningRuntimeCaptureService::operatorWords('o que eu escrevi', $options)
            );
        }
    }
}
