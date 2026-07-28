<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Learning;

use App\Models\OperatorLearningSignal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\TestCase;

/**
 * A rede do produtor REAL de worked examples.
 *
 * O que ele existe para nao ser: `atlas:worked-example author` ja gravava, mas com
 * conteudo enlatado — os mesmos 5 passos genericos com o topico interpolado. Encher
 * `worked_examples` assim faria a contagem subir sem ninguem aprender nada, que e o
 * defeito de medir o que e facil em vez do que importa.
 *
 * Por isso o teste central aqui NAO e "criou uma linha". E "a linha contem as palavras do
 * operador". Um teste que so contasse linhas passaria verde sobre o gerador enlatado.
 */
final class AtlasStudyExampleCommandTest extends TestCase
{
    use CreatesOperatorIntelligenceTables;

    /**
     * As migrations reais, requeridas a mao. NAO uso `TestsWithLedgerEvents` de proposito:
     * ele instala o schema pelo proprio `setUp`, e esta classe precisa do seu — declarar os
     * dois faz o da classe vencer em silencio e a tabela nunca nascer. Foi exatamente o que
     * aconteceu na primeira versao: os 5 testes reprovaram com exit 1 e o motivo real era
     * `worked_examples` ausente, nao a logica sob teste.
     */
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_05_07_140000_create_worked_examples_table.php'))->up();
        $this->createOperatorIntelligenceTables();
    }

    protected function tearDown(): void
    {
        $this->dropOperatorIntelligenceTables();
        Schema::dropIfExists('worked_examples');
        parent::tearDown();
    }

    /** @param array<string,mixed> $meta */
    private function sinal(string $spot, string $decisao, string $principio, array $meta = []): OperatorLearningSignal
    {
        return OperatorLearningSignal::query()->create([
            'id' => (string) Str::uuid(),
            'operator_id' => 'vitor',
            'taxonomy_item_id' => 'OP-071',
            'signal_kind' => 'operator_decision',
            'source_type' => 'operator_declared',
            'normalized_claim' => $principio,
            'evidence_refs' => ['spot:'.$spot, 'decision:'.$decisao],
            'privacy_class' => 'normal',
            'risk_level' => 'low',
            'confidence' => 0.9,
            'scope_type' => 'domain',
            'scope_id' => 'poker',
            'metadata' => array_merge([
                'operator_text_declared' => true,
                'confidence_kind' => 'principled',
                'vocabulary_term' => 'C-bet',
            ], $meta),
        ]);
    }

    public function test_o_exemplo_carrega_as_palavras_do_operador_nao_texto_generico(): void
    {
        $this->sinal(
            'abri no CO, BB pagou, flop A72 rainbow',
            'aposta 33%',
            'board seco favorece minha range entao aposto pequeno com tudo'
        );

        $this->artisan('atlas:study:example', ['--domain' => 'poker', '--json' => true])->assertExitCode(0);

        $exemplo = DB::table('worked_examples')->firstOrFail();
        $solucao = json_encode(json_decode((string) $exemplo->solution_full, true), JSON_UNESCAPED_UNICODE);

        $this->assertSame('abri no CO, BB pagou, flop A72 rainbow', $exemplo->problem_context);
        $this->assertStringContainsString('board seco favorece minha range', (string) $solucao, 'o principio do operador tem de estar no exemplo');
        $this->assertStringContainsString('aposta 33%', (string) $solucao, 'a decisao do operador tem de estar no exemplo');
        $this->assertStringContainsString('C-bet', (string) $solucao, 'o conceito ancorado tem de estar no exemplo');
    }

    public function test_o_principio_e_o_primeiro_a_sumir_no_fading_e_o_spot_e_o_ultimo(): void
    {
        $this->sinal('spot qualquer', 'call', 'o principio que ele tem de reproduzir');

        $this->artisan('atlas:study:example', ['--json' => true])->assertExitCode(0);

        $fading = json_decode((string) DB::table('worked_examples')->value('fading_levels'), true);

        // Passo 3 e o principio, passo 1 e o spot. A decisao pedagogica inteira esta aqui:
        // esconder o spot seria esconder a pergunta; esconder o principio obriga o operador
        // a produzi-lo de novo, e reproduzi-lo e a unica prova de que ele o tem.
        $this->assertContains(3, $fading['1'], 'no estagio iniciante o principio aparece inteiro');
        $this->assertNotContains(3, $fading['3'], 'no estagio 3 o principio ja saiu');
        $this->assertNotContains(3, $fading['5'], 'no estagio 5 o principio continua fora');
        $this->assertContains(1, $fading['5'], 'o spot sobrevive ate o ultimo estagio — e a pergunta');
    }

    public function test_rodar_duas_vezes_nao_dobra_o_material_de_estudo(): void
    {
        $this->sinal('spot A', 'fold', 'principio A');

        $this->artisan('atlas:study:example', ['--json' => true])->assertExitCode(0);
        $this->artisan('atlas:study:example', ['--json' => true])->assertExitCode(0);

        $this->assertSame(1, DB::table('worked_examples')->count(), 'idempotencia: o sinal ja convertido nao volta');
    }

    public function test_chute_nao_vira_material_de_estudo(): void
    {
        $this->sinal('board 9h7h2c', 'fold', 'senti que ele tinha o flush', ['confidence_kind' => 'guess']);

        $this->artisan('atlas:study:example', ['--json' => true])->assertExitCode(0);

        // Um palpite que acertou nao e principio. Devolve-lo como material de estudo
        // ensinaria o operador a confiar na propria sorte.
        $this->assertSame(0, DB::table('worked_examples')->count());
    }

    public function test_sinal_nao_declarado_pelo_operador_nao_vira_exemplo(): void
    {
        $this->sinal('spot X', 'call', 'inferido pela maquina', ['operator_text_declared' => false]);

        $this->artisan('atlas:study:example', ['--json' => true])->assertExitCode(0);

        // A mesma lei da captura: nao declarado = desconhecido. Gravar a voz da maquina
        // como material de estudo do operador seria ensina-lo com o proprio eco.
        $this->assertSame(0, DB::table('worked_examples')->count());
    }
}
