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
        self::assertSame(
            'tem algum problema?',
            OperatorLearningRuntimeCaptureService::operatorWords([
                'payload' => ['operator_text' => 'tem algum problema?'],
            ]),
            'o que a superfície anexou nunca é a voz do operador'
        );
    }

    /**
     * Esta é a lei invertida por medição. A versão anterior aceitava o
     * `input_text` quando a superfície calava, "porque é o melhor que existe
     * ali". Duas medições derrubaram a premissa:
     *
     *   • nenhuma superfície jamais mandou `operator_text` — zero produtores no
     *     repo — então o galho do fallback era 100% das capturas, e a guarda
     *     inteira era inerte;
     *   • `input_text` não é o melhor que existe: é o prompt montado. Nos 13
     *     sinais vivos ele trazia ~1,5k de fatos que o próprio Atlas colheu mais
     *     o preâmbulo de sistema, e a fala do operador eram as quatro palavras
     *     no fim — e o preâmbulo virou proposta de skill com o título "O
     *     operador repete: Você NÃO pode editar, commitar ou executar nada
     *     neste repositório".
     *
     * Não aprender é recuperável. Gravar a voz da máquina como regra dele não é.
     */
    public function test_undeclared_text_is_unknown_and_unknown_is_never_learned(): void
    {
        $dossie = "[Fatos lidos do git de atlas-server agora…]\n23 exceções: 18 worktrees fora do lugar.";

        self::assertNull(
            OperatorLearningRuntimeCaptureService::operatorWords([]),
            'sem a superfície declarar, o fio não é atribuível ao operador'
        );
        self::assertNull(
            OperatorLearningRuntimeCaptureService::operatorWords(['payload' => ['input_text' => $dossie]]),
            'o prompt montado nunca vira sinal do operador por falta de alternativa'
        );
    }

    public function test_an_empty_claim_of_operator_text_is_a_non_declaration(): void
    {
        foreach ([['payload' => ['operator_text' => '   ']], ['payload' => ['operator_text' => null]], ['payload' => []]] as $options) {
            self::assertNull(OperatorLearningRuntimeCaptureService::operatorWords($options));
        }
    }
}
