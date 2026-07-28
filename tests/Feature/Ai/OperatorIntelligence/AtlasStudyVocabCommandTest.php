<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\OperatorIntelligence;

use App\Console\Commands\AtlasStudyVocabCommand;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesStudyVocabularyTable;
use Tests\TestCase;

/**
 * A rede do vocabulario controlado.
 *
 * Cada caso aqui saiu de uma medicao sobre os 305 verbetes reais do
 * `Dicionario_do_Poker.pdf`, nao de imaginacao sobre o que poderia dar errado.
 */
final class AtlasStudyVocabCommandTest extends TestCase
{
    use CreatesStudyVocabularyTable;

    private string $arquivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStudyVocabularyTable();
        $this->arquivo = tempnam(sys_get_temp_dir(), 'vocab').'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->arquivo);
        $this->dropStudyVocabularyTable();
        parent::tearDown();
    }

    /** @param list<array<string,string>> $entradas */
    private function corpus(array $entradas): void
    {
        file_put_contents($this->arquivo, json_encode($entradas, JSON_UNESCAPED_UNICODE));
    }

    public function test_importa_e_conta_o_que_entrou(): void
    {
        $this->corpus([
            ['termo' => 'ABI', 'definicao' => 'Average Buy In. O valor medio dos torneios jogados.'],
            ['termo' => 'Bottom', 'definicao' => 'O par formado com a carta mais baixa do board.'],
        ]);

        $this->artisan('atlas:study:vocab', ['--import' => $this->arquivo, '--domain' => 'poker', '--json' => true])
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('atlas_study_vocabulary')->where('domain', 'poker')->count());
    }

    public function test_reimportar_atualiza_em_vez_de_duplicar(): void
    {
        $this->corpus([['termo' => 'ABI', 'definicao' => 'primeira versao da definicao.']]);
        $this->artisan('atlas:study:vocab', ['--import' => $this->arquivo, '--json' => true])->assertExitCode(0);

        $this->corpus([['termo' => 'ABI', 'definicao' => 'segunda versao, corrigida.']]);
        $this->artisan('atlas:study:vocab', ['--import' => $this->arquivo, '--json' => true])->assertExitCode(0);

        // Idempotencia real: reimportar um corpus corrigido nao pode dobrar o vocabulario.
        $this->assertSame(1, DB::table('atlas_study_vocabulary')->count());
        $this->assertSame(
            'segunda versao, corrigida.',
            DB::table('atlas_study_vocabulary')->value('definition')
        );
    }

    public function test_caixa_e_acento_nao_criam_verbete_duplicado(): void
    {
        $this->corpus([
            ['termo' => 'Mão', 'definicao' => 'as cartas que o jogador segura.'],
            ['termo' => 'MAO', 'definicao' => 'a mesma coisa, digitada sem acento.'],
        ]);

        $this->artisan('atlas:study:vocab', ['--import' => $this->arquivo, '--json' => true])->assertExitCode(0);

        // Sem dobrar caixa e acento a ancora do principio passaria a depender de como o
        // operador digitou naquele dia — dois verbetes para um conceito so.
        $this->assertSame(1, DB::table('atlas_study_vocabulary')->count());
    }

    public function test_repara_o_espaco_que_o_pdf_comeu(): void
    {
        // Strings VERBATIM do corpus, colhidas com regex sobre os 305 verbetes. A primeira
        // versao deste teste usou formas reconstruidas de cabeca e reprovou — reconstruir
        // e adivinhar, e o corpus e a unica autoridade sobre o que ele contem.
        $this->assertSame('continuation Bet', AtlasStudyVocabCommand::repararEspacos('continuationBet'));
        $this->assertSame('modalidade Omahaonde', AtlasStudyVocabCommand::repararEspacos('modalidadeOmahaonde'));
        $this->assertSame('últiplas Mesas', AtlasStudyVocabCommand::repararEspacos('últiplasMesas'));
    }

    public function test_juncao_de_mesma_caixa_fica_visivel_em_vez_de_ser_adivinhada(): void
    {
        // "Minimade" e "Minima de" colado, mas separar exigiria lexico. Chutar a separacao
        // dentro de palavra real e pior que deixar o defeito visivel: um erro que se ve
        // se conserta depois; um erro que parece certo entra no estudo como verdade.
        $this->assertSame('Frequência Mínimade Defesa', AtlasStudyVocabCommand::repararEspacos('FrequênciaMínimade Defesa'));
    }

    public function test_pagina_seguinte_nao_contamina_a_definicao_deste_verbete(): void
    {
        // Verbatim do verbete "Average": depois do rodape vinha a definicao de OUTRO termo.
        $sujo = 'palavra em inglês, tradução de “média”. Exemplos: Average buy in, Average stack, etc.'
            .'Comunidade Reg Life - Todos os direitos reservadosPOKER É... falar outro IDIOMA . '
            .'Quandovocêprecisa de mais 2 cartas para formar um jogo completo';

        [$propria, , $orfaos] = AtlasStudyVocabCommand::fatiar($sujo);

        $this->assertStringNotContainsString('2 cartas', $propria, 'texto da pagina seguinte nao pode virar definicao deste verbete');
        $this->assertStringNotContainsString('Reg Life', $propria);
        $this->assertStringEndsWith('etc.', $propria, 'a definicao verdadeira fecha em ponto final antes do rodape');
        // O fragmento sem dono e descartado COM RECIBO — colar no verbete errado seria
        // pior que perder, porque ausencia se ve e atribuicao errada nao.
        $this->assertSame(1, $orfaos);
    }

    public function test_verbete_engolido_na_pagina_seguinte_e_recuperado(): void
    {
        // Verbatim: "Steal" estava dentro do corpo de "Stats", depois do rodape. Truncar
        // limpava a contaminacao e perdia o termo — sao 17 termos centrais assim.
        $sujo = 'sigla para estatísticas do oponente.'
            .'Comunidade Reg Life - Todos os direitos reservadosPOKER É... falar outro IDIOMA '
            .'fragmento sem dono. Steal:palavra em inglês para “roubo”. Nome do open raise nas posições finais.';

        [$propria, $extras] = AtlasStudyVocabCommand::fatiar($sujo);

        $this->assertSame('sigla para estatísticas do oponente.', $propria);
        $this->assertCount(1, $extras);
        $this->assertSame('Steal', $extras[0]['termo']);
        $this->assertStringContainsString('open raise', $extras[0]['definicao']);
    }

    public function test_dois_pontos_no_meio_de_frase_nao_vira_verbete(): void
    {
        // A guarda que separa cabecalho de verbete de dois-pontos comum. Sem ela, cada
        // "Exemplo:" e cada explicacao com lista viraria um termo inventado.
        $texto = 'aposta feita no turn. Exemplo: o jogador aposta metade do pote. '
            .'Existem tres modalidades: hold’em, omaha e stud.';

        [$propria, $extras] = AtlasStudyVocabCommand::fatiar($texto);

        $this->assertSame([], $extras, 'dois-pontos no meio de explicacao nao e cabecalho de verbete');
        $this->assertSame($texto, $propria);
    }

    public function test_rotulo_de_secao_e_anexado_ao_verbete_anterior_nao_vira_verbete(): void
    {
        $this->corpus([
            ['termo' => 'Bounty', 'definicao' => 'premio pago por eliminar um jogador.'],
            ['termo' => 'Variação', 'definicao' => 'Knockout ou “K.O.”'],
            ['termo' => 'Check', 'definicao' => 'passar a vez sem apostar.'],
            ['termo' => 'Variação', 'definicao' => 'pedir “mesa”, checar.'],
        ]);

        $this->artisan('atlas:study:vocab', ['--import' => $this->arquivo, '--json' => true])->assertExitCode(0);

        // Sem o tratamento, as duas "Variação" colidiriam na chave unica: a segunda
        // sobrescreveria a primeira e o vocabulario perderia conteudo relatando sucesso.
        $this->assertSame(2, DB::table('atlas_study_vocabulary')->count());
        $this->assertStringContainsString(
            'K.O.',
            (string) DB::table('atlas_study_vocabulary')->where('term', 'Bounty')->value('definition'),
            'a variacao pertence ao verbete anterior'
        );
        $this->assertStringContainsString(
            'checar',
            (string) DB::table('atlas_study_vocabulary')->where('term', 'Check')->value('definition')
        );
    }

    public function test_sigla_sobrevive_ao_reparo(): void
    {
        // O reparo exige minusculas ANTES e DEPOIS da maiuscula. Sigla nunca casa — se
        // casasse, "GTO" viraria "G TO" e o vocabulario destruiria justo os termos que
        // mais aparecem em principio de poquer.
        foreach (['GTO', 'MDF', 'EV', '3bet', 'cbet', 'ICM'] as $sigla) {
            $this->assertSame($sigla, AtlasStudyVocabCommand::repararEspacos($sigla), "sigla {$sigla} nao pode ser quebrada");
        }
    }

    public function test_busca_acha_por_termo_sem_acento(): void
    {
        $this->corpus([['termo' => 'Mão', 'definicao' => 'as cartas que o jogador segura.']]);
        $this->artisan('atlas:study:vocab', ['--import' => $this->arquivo, '--json' => true])->assertExitCode(0);

        $this->artisan('atlas:study:vocab', ['--term' => 'mao', '--json' => true])->assertExitCode(0);
    }

    public function test_termo_inexistente_recusa_com_recibo(): void
    {
        $this->artisan('atlas:study:vocab', ['--term' => 'termo-que-nao-existe', '--json' => true])
            ->assertExitCode(1);
    }

    public function test_verbete_sem_definicao_e_recusado_com_recibo_nao_em_silencio(): void
    {
        $this->corpus([
            ['termo' => 'Valido', 'definicao' => 'tem definicao de verdade.'],
            ['termo' => 'Vazio', 'definicao' => '   '],
            ['termo' => '', 'definicao' => 'sem termo nenhum.'],
        ]);

        $this->artisan('atlas:study:vocab', ['--import' => $this->arquivo, '--json' => true])->assertExitCode(0);

        // Entrar em silencio seria pior que recusar: a contagem final tem de bater com o
        // corpus, senao o vocabulario mente sobre a propria cobertura.
        $this->assertSame(1, DB::table('atlas_study_vocabulary')->count());
    }
}
