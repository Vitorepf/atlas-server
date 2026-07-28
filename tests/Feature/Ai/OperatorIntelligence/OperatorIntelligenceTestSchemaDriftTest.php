<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\OperatorLearningRuntimeCaptureService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\TestCase;

/**
 * A rede contra a causa-raiz, nao contra o sintoma.
 *
 * `CreatesOperatorIntelligenceTables` criava SEIS tabelas; o servico exige OITO. O efeito
 * nao era um erro visivel: `runtimeCaptureAvailability` respondia `missing_operator_tables`
 * e a captura devolvia sem gravar. O teste que existia para provar que a captura funciona
 * ficou vermelho por um motivo que nao era o assunto dele.
 *
 * E o custo real nao foi o teste vermelho — foi o que ele passou a esconder. Uma suite com
 * falhas conhecidas para de ser sinal: uma regressao NOVA entra no meio das antigas e
 * ninguem repara. Aconteceu nesta sessao, com uma regressao de flexao do portugues que so
 * apareceu porque a frase afetada era justamente a deste teste vermelho.
 *
 * Por isso a rede e sobre a DERIVA, nao sobre as duas tabelas que faltavam: quando alguem
 * acrescentar a nona tabela a `REQUIRED_TABLES`, este teste reprova no mesmo commit, com o
 * nome da tabela, em vez de virar mais uma falha de fundo meses depois.
 */
final class OperatorIntelligenceTestSchemaDriftTest extends TestCase
{
    use CreatesOperatorIntelligenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOperatorIntelligenceTables();
    }

    protected function tearDown(): void
    {
        $this->dropOperatorIntelligenceTables();
        parent::tearDown();
    }

    public function test_o_trait_de_teste_cria_todas_as_tabelas_que_o_servico_exige(): void
    {
        $faltando = DatabaseTableAvailability::missing(OperatorLearningRuntimeCaptureService::REQUIRED_TABLES);

        $this->assertSame(
            [],
            $faltando,
            'CreatesOperatorIntelligenceTables derivou de REQUIRED_TABLES — a captura vai '
            .'responder missing_operator_tables e todo teste que dependa dela fica vermelho '
            .'por um motivo que nao e o assunto dele. Faltando: '.implode(', ', $faltando)
        );
    }

    public function test_o_drop_devolve_o_banco_ao_estado_anterior(): void
    {
        $this->dropOperatorIntelligenceTables();

        $sobraram = array_values(array_filter(
            OperatorLearningRuntimeCaptureService::REQUIRED_TABLES,
            static fn (string $t): bool => DatabaseTableAvailability::has($t)
        ));

        // Criar sem dropar o par vaza estado entre testes, e vazamento de schema produz
        // falha que depende da ORDEM de execucao — a mais cara de diagnosticar.
        $this->assertSame([], $sobraram, 'dropOperatorIntelligenceTables tem de cobrir tudo que create cria');

        $this->createOperatorIntelligenceTables();
    }
}
