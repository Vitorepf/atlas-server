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

    public function test_o_estagio_provado_no_review_e_lido_pelo_planejador_que_ja_existia(): void
    {
        // `atlas:study <topic>` (anterior a esta obra) resolve o estagio Dreyfus por
        // `nodeIdForTopic($topic)` = sha1 do topico. `atlas:study:example` cria a carta com
        // `topic: <termo do vocabulario>`, e `atlas:study:review` escreve o overlay nesse
        // mesmo no. Ou seja: os dois compoem, sem que nenhum saiba do outro.
        //
        // A costura inteira e uma STRING virando hash. Trocar o topico usado na criacao da
        // carta — de "C-bet" para o dominio, por exemplo — desligaria a composicao em
        // silencio: os dois comandos continuariam funcionando, cada um sobre um no
        // diferente, e o operador veria estagio 1 no planejador depois de ter provado
        // transferencia no review.
        $corpus = tempnam(sys_get_temp_dir(), 'vocab').'.json';
        file_put_contents($corpus, json_encode([['termo' => 'C-bet', 'definicao' => 'aposta de continuidade.']], JSON_UNESCAPED_UNICODE));
        $this->rodar('atlas:study:vocab', ['--import' => $corpus, '--domain' => 'poker']);
        @unlink($corpus);

        $this->rodar('atlas:study:log', [
            '--spot' => 'flop A72 rainbow',
            '--decision' => 'aposta 33%',
            '--why' => 'vantagem de range em board seco',
            '--term' => 'C-bet',
        ]);
        $this->rodar('atlas:study:example', ['--domain' => 'poker']);

        $carta = DB::table('worked_examples')->firstOrFail();
        $noDaCarta = (string) $carta->knowledge_node_id;

        $this->rodar('atlas:study:review', [
            '--grade' => (string) $carta->id, '--mode' => 'transfer', '--correct' => true,
        ]);

        $overlay = DB::table('dreyfus_overlays')->where('domain', 'poker')->firstOrFail();

        $this->assertSame($noDaCarta, (string) $overlay->knowledge_node_id, 'o review tem de escrever no no da carta');
        $this->assertSame(2, (int) $overlay->current_level);

        // E o no e derivavel do TERMO — que e por onde o planejador chega nele.
        $noDoTermo = app(\App\Services\Ai\Learning\Dreyfus\DreyfusOverlayRepository::class)->nodeIdForTopic('C-bet');
        $this->assertSame($noDaCarta, $noDoTermo, 'o planejador e o laco de revisao tem de cair no MESMO no');
    }

    public function test_o_mesmo_ciclo_roda_em_engenharia_sem_uma_linha_de_codigo_de_poquer(): void
    {
        // O poquer e o TESTE DE ACEITE da obra, nao o escopo dela. Se alguma peca do ciclo
        // soubesse de poquer, o Atlas teria construido um app de poquer em vez de um orgao
        // de aprendizado — e cada dominio novo (venture builder, cyber, trading) exigiria
        // reescrever tudo. `domain` e o unico eixo, e ele ja e o `scope_id` do sinal.
        $corpus = tempnam(sys_get_temp_dir(), 'vocab').'.json';
        file_put_contents($corpus, json_encode([
            ['termo' => 'Mutação', 'definicao' => 'reintroduzir o defeito para provar que o teste o pega.'],
        ], JSON_UNESCAPED_UNICODE));

        $this->rodar('atlas:study:vocab', ['--import' => $corpus, '--domain' => 'engenharia']);
        @unlink($corpus);

        $primeira = $this->rodar('atlas:study:log', [
            '--spot' => 'escrevi um teste que passou de primeira',
            '--decision' => 'reintroduzi o bug antes de commitar',
            '--why' => 'teste que passa com o bug de volta nao e rede',
            '--term' => 'mutacao',
            '--domain' => 'engenharia',
        ]);
        $this->assertTrue($primeira['ok']);
        $this->assertSame('Mutação', $primeira['vocabulary_term'], 'a ancora resolve sem acento em qualquer dominio');

        $this->assertSame(1, $this->rodar('atlas:study:example', ['--domain' => 'engenharia'])['exemplos_criados']);

        // E a separacao por dominio continua valendo: o corpus de poquer nao ve isto.
        $this->assertSame(0, $this->rodar('atlas:study:example', ['--domain' => 'poker'])['exemplos_criados']);

        $entrega = $this->rodar('atlas:study:review', ['--domain' => 'engenharia']);
        $this->assertSame('recall', $entrega['modo']);

        $this->rodar('atlas:study:log', [
            '--spot' => 'outro teste, outro modulo, tambem passou de primeira',
            '--decision' => 'mutei antes de confiar',
            '--why' => 'mesmo principio aplicado a um caso que nunca vi',
            '--term' => 'Mutação',
            '--domain' => 'engenharia',
        ]);

        $transfer = $this->rodar('atlas:study:review', ['--domain' => 'engenharia', '--force' => true]);
        $this->assertSame('transfer', $transfer['modo']);

        $promocao = $this->rodar('atlas:study:review', [
            '--grade' => (string) $transfer['worked_example_id'], '--mode' => 'transfer', '--correct' => true,
        ]);
        $this->assertTrue($promocao['promoveu']);

        $painel = $this->rodar('atlas:study:status', ['--domain' => 'engenharia']);
        $this->assertSame(1, $painel['placar_de_aprendizado']['transferencias']);
        $this->assertEquals(1.0, $painel["placar_de_aprendizado"]["taxa_de_dominio"]);
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
