<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Learning;

use App\Models\OperatorLearningSignal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\Concerns\CreatesStudyVocabularyTable;
use Tests\TestCase;

/**
 * O ciclo inteiro, ponta a ponta, com os quatro comandos reais.
 *
 *   vocabulario -> captura -> worked example -> entrega -> julgamento
 *
 * Por que este teste existe alem dos que ja cobrem cada peca: os testes de unidade provam
 * que cada comando faz o que promete, mas nao provam que as COSTURAS batem. Toda a cadeia
 * depende de campos que viajam entre comandos por convencao, nao por tipo — `term:X` e
 * `signal:Y` dentro de `author_evidence_refs`, `vocabulary_term` dentro de `metadata`,
 * `spot:` dentro de `evidence_refs`. Um renomeio silencioso em qualquer um deles deixaria
 * todos os testes de peca verdes e o ciclo quebrado.
 *
 * O desfecho que ele exige e o do proprio criterio de certificacao: o operador so e
 * promovido quando aplica o principio a um spot que nunca viu.
 */
final class CicloDeAprendizadoIntegrationTest extends TestCase
{
    use CreatesOperatorIntelligenceTables;
    use CreatesStudyVocabularyTable;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_05_07_140000_create_worked_examples_table.php'))->up();
        (require database_path('migrations/2026_05_07_120000_create_dreyfus_overlays_table.php'))->up();
        $this->createOperatorIntelligenceTables();
        $this->createStudyVocabularyTable();
    }

    protected function tearDown(): void
    {
        $this->dropStudyVocabularyTable();
        $this->dropOperatorIntelligenceTables();
        Schema::dropIfExists('dreyfus_overlays');
        Schema::dropIfExists('worked_examples');
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function rodar(string $comando, array $opcoes): array
    {
        Artisan::call($comando, array_merge(['--json' => true], $opcoes));

        return (array) json_decode(trim(Artisan::output()), true);
    }

    public function test_do_verbete_ao_veredito_de_transferencia(): void
    {
        // 1 — o vocabulario entra e vira ancora possivel.
        $corpus = tempnam(sys_get_temp_dir(), 'vocab').'.json';
        file_put_contents($corpus, json_encode([
            ['termo' => 'C-bet', 'definicao' => 'aposta de continuidade de quem agrediu na rodada anterior.'],
        ], JSON_UNESCAPED_UNICODE));

        $vocab = $this->rodar('atlas:study:vocab', ['--import' => $corpus, '--domain' => 'poker']);
        $this->assertTrue($vocab['ok']);
        @unlink($corpus);

        // 2 — a primeira decisao, ancorada num termo que EXISTE (escrito em outra caixa).
        $primeira = $this->rodar('atlas:study:log', [
            '--spot' => 'abri no CO, BB pagou, flop A72 rainbow',
            '--decision' => 'aposta 33%',
            '--why' => 'board seco favorece minha range entao aposto pequeno com tudo',
            '--term' => 'c-BET',
            '--confidence' => 'principled',
        ]);
        $this->assertTrue($primeira['ok']);
        $this->assertSame('C-bet', $primeira['vocabulary_term'], 'a ancora atravessa a costura ja resolvida');

        // 3 — a decisao vira material de estudo com as palavras dele.
        $exemplos = $this->rodar('atlas:study:example', ['--domain' => 'poker']);
        $this->assertSame(1, $exemplos['exemplos_criados']);

        // 4 — com um sinal so, a entrega e honestamente recall, e avisa.
        $recall = $this->rodar('atlas:study:review', ['--domain' => 'poker']);
        $this->assertSame('recall', $recall['modo']);

        // 5 — acertar em recall NAO promove. Este e o elo que impede o placar de mentir.
        $julgado = $this->rodar('atlas:study:review', [
            '--grade' => (string) $recall['worked_example_id'],
            '--mode' => 'recall',
            '--correct' => true,
        ]);
        $this->assertFalse($julgado['promoveu'], 'reconhecer a propria anotacao nao pode promover');
        $this->assertSame(1, $julgado['estagio_depois']);

        // 6 — uma SEGUNDA decisao no mesmo conceito cria o material de transferencia.
        $this->rodar('atlas:study:log', [
            '--spot' => 'abri no BTN, BB pagou, flop K83 rainbow',
            '--decision' => 'aposta 33%',
            '--why' => 'mesmo raciocinio de vantagem de range em board seco',
            '--term' => 'C-bet',
            '--confidence' => 'principled',
        ]);

        // 7 — agora a entrega troca o spot: mesmo conceito, situacao que ele nunca viu.
        $transfer = $this->rodar('atlas:study:review', ['--domain' => 'poker', '--force' => true]);
        $this->assertSame('transfer', $transfer['modo']);
        $this->assertStringContainsString('K83', $transfer['spot']);
        $this->assertStringNotContainsString('A72', $transfer['spot'], 'o spot da transferencia nao pode ser o anotado');

        // 8 — e so aqui o operador sobe de estagio.
        $promocao = $this->rodar('atlas:study:review', [
            '--grade' => (string) $transfer['worked_example_id'],
            '--mode' => 'transfer',
            '--correct' => true,
        ]);
        $this->assertTrue($promocao['promoveu']);
        $this->assertSame(2, $promocao['estagio_depois']);
        $this->assertNotNull($promocao['proxima_revisao'], 'a repeticao espacada ganha relogio');
    }

    public function test_ancora_falsa_para_o_ciclo_no_primeiro_elo(): void
    {
        // Sem verbete importado, a decisao ancorada e RECUSADA — e nada a jusante existe.
        $saida = $this->rodar('atlas:study:log', [
            '--spot' => 'spot qualquer',
            '--decision' => 'call',
            '--why' => 'principio qualquer',
            '--term' => 'conceito-que-ninguem-definiu',
        ]);

        $this->assertFalse($saida['ok']);
        $this->assertSame('unknown_vocabulary_term', $saida['reason']);
        $this->assertSame(0, OperatorLearningSignal::query()->count());

        $exemplos = $this->rodar('atlas:study:example', ['--domain' => 'poker']);
        $this->assertSame(0, $exemplos['exemplos_criados']);
        $this->assertSame(0, DB::table('worked_examples')->count());
    }
}
