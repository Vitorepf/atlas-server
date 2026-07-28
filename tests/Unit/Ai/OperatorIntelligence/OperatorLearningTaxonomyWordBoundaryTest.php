<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorLearningClassifySupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O mesmo defeito de `inferPrivacy`, no segundo lugar onde ele morava.
 *
 * `normalizeTaxonomy` decidia por `str_contains` sem fronteira de palavra, entao 'tom'
 * casava dentro de "**tom**ar", "bot**tom**", "cus**tom**", "sin**tom**a" e "a**tom**o".
 * Medido antes do conserto: 4 de 7 frases realistas em portugues caiam em COL-156 —
 * "Seu vocabulario proprio" — por acidente de substring.
 *
 * E havia o erro espelhado, invisivel: sem dobrar acento, 'nao mexa' nunca casava
 * "nao mexa" escrito com til, que e como o operador escreve. Um lado inventava rotulo, o
 * outro perdia o certo. Por isso o teste cobre as DUAS direcoes — uma rede que so provasse
 * "nao rotula demais" passaria com todas as listas vazias.
 *
 * O caso de `inferSignalKind` importa mais que os outros: `operator_boundary` e o rotulo
 * de LIMITE, o que o operador proibiu. Perder um limite por causa de um til e o tipo de
 * falha que so aparece quando ja foi tarde.
 */
final class OperatorLearningTaxonomyWordBoundaryTest extends TestCase
{
    /**
     * @return array<string,array{0:string}>
     */
    public static function frasesQueContemTermoDentroDeOutraPalavra(): array
    {
        return [
            'tomar contém tom' => ['vou tomar uma decisao rapida agora'],
            'bottom contém tom' => ['com bottom pair em board monotone eu nao pago'],
            'custom contém tom' => ['o custom do cliente mudou ontem'],
            'sintoma e atomo contêm tom' => ['esse sintoma aparece so no atomo do parser'],
        ];
    }

    #[Test]
    #[DataProvider('frasesQueContemTermoDentroDeOutraPalavra')]
    public function substring_dentro_de_palavra_nao_inventa_rotulo(string $claim): void
    {
        $this->assertSame(
            'OP-071',
            OperatorLearningClassifySupport::normalizeTaxonomy('', $claim),
            'acidente de substring nao pode virar classificacao de perfil do operador'
        );
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function frasesReaisQueDevemClassificar(): array
    {
        return [
            'tom como palavra' => ['prefiro resposta em tom informal', 'COL-156'],
            'resposta longa' => ['nao gosto de resposta longa', 'COL-156'],
            'limite com til' => ['não mexa no diretorio de producao', 'OP-140'],
            'nunca' => ['nunca rode isso em producao', 'OP-140'],
            'radical autonom' => ['me da autonomia total nessa area', 'COL-157'],
            'radical bloque' => ['bloqueie esse caminho', 'OP-140'],
            'radical aprov' => ['quero aprovar antes de rodar', 'COL-157'],
            'gosto' => ['gosto de interface escura', 'OP-124'],
        ];
    }

    #[Test]
    #[DataProvider('frasesReaisQueDevemClassificar')]
    public function termo_real_continua_classificado(string $claim, string $esperado): void
    {
        $this->assertSame(
            $esperado,
            OperatorLearningClassifySupport::normalizeTaxonomy('', $claim),
            'o conserto do falso positivo nao pode apagar a classificacao que funcionava'
        );
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function flexoesQueOPortuguesExige(): array
    {
        return [
            'plural' => ['prefiro respostas curtas quando eu pedir status'],
            'plural e feminino' => ['nao gosto de respostas longas'],
            'feminino singular' => ['prefiro entrega curta'],
            'plural de tom' => ['quero tons mais informais'],
        ];
    }

    #[Test]
    #[DataProvider('flexoesQueOPortuguesExige')]
    public function flexao_de_plural_e_genero_continua_casando(string $claim): void
    {
        // A ARMADILHA que esta rede existe para fechar: trocar `str_contains` por fronteira
        // dura dos DOIS lados conserta o falso positivo e cria um falso negativo, porque
        // `\bresposta\b` nao casa "respostas". O `str_contains` casava — por acidente, mas
        // casava. Medido no momento em que aconteceu: "prefiro respostas curtas" caiu de
        // COL-156 para OP-124, e nenhum teste existente pegou, porque a unica suite que
        // cobria essa frase ja estava vermelha por outro motivo.
        $this->assertSame(
            'COL-156',
            OperatorLearningClassifySupport::normalizeTaxonomy('', $claim),
            'fronteira de palavra nao pode custar a flexao — portugues flexiona'
        );
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function palavrasVizinhasQueNaoPodemCasar(): array
    {
        return [
            'curtir nao e curto' => ['quero curtir a folga amanha'],
            'longe nao e longo' => ['ele mora longe daqui'],
            'gostoso nao e gosto' => ['o cafe estava gostoso hoje'],
        ];
    }

    #[Test]
    #[DataProvider('palavrasVizinhasQueNaoPodemCasar')]
    public function flexao_declarada_nao_abre_a_porta_para_a_palavra_vizinha(string $claim): void
    {
        // O preco de aceitar flexao seria voltar ao substring. `curt[oa]s?` pega
        // curto/curta/curtos/curtas e para em "curtir"; `long[oa]s?` para em "longe".
        $this->assertSame('OP-071', OperatorLearningClassifySupport::normalizeTaxonomy('', $claim));
    }

    #[Test]
    public function radical_truncado_continua_pegando_a_familia_inteira(): void
    {
        // 'autonom' e 'bloque' sao radicais de proposito. Prender a fronteira da DIREITA
        // neles quebraria justamente o que o truncamento existia para pegar.
        foreach (['autonomia', 'autonomo', 'autonomamente'] as $palavra) {
            $this->assertSame('COL-157', OperatorLearningClassifySupport::normalizeTaxonomy('', 'quero '.$palavra));
        }
        foreach (['bloqueie', 'bloqueio', 'bloquear'] as $palavra) {
            $this->assertSame('OP-140', OperatorLearningClassifySupport::normalizeTaxonomy('', 'faca o '.$palavra));
        }
    }

    #[Test]
    public function acento_nao_esconde_o_limite_do_operador(): void
    {
        // `operator_boundary` e o rotulo do que o operador PROIBIU. Perde-lo por um til e
        // a falha que so aparece depois que o limite ja foi cruzado.
        $this->assertSame(
            'operator_boundary',
            OperatorLearningClassifySupport::inferSignalKind('não mexa nesse arquivo', 'OP-140')
        );
        $this->assertSame(
            OperatorLearningClassifySupport::inferSignalKind('nao mexa nesse arquivo', 'OP-140'),
            OperatorLearningClassifySupport::inferSignalKind('não mexa nesse arquivo', 'OP-140'),
            'as duas grafias do mesmo limite tem de decidir igual'
        );
    }

    #[Test]
    public function substring_nao_transforma_preferencia_em_limite(): void
    {
        $this->assertSame(
            'operator_preference',
            OperatorLearningClassifySupport::inferSignalKind('com bottom pair eu pago', 'OP-071')
        );
    }

    #[Test]
    public function id_canonico_recebido_continua_mandando(): void
    {
        // A porta da frente: id valido do registro nunca e reinterpretado pelo palpite.
        $this->assertSame('COL-160', OperatorLearningClassifySupport::normalizeTaxonomy('col-160', 'texto com tom e gosto e nunca'));
    }
}
