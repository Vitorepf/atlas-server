<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\OperatorIntelligence;

use App\Models\OperatorLearningSignal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\Concerns\CreatesStudyVocabularyTable;
use Tests\TestCase;

/**
 * A rede do primeiro produtor declarado de `operator_text`.
 *
 * Antes deste comando o orgao de aprendizado era mudo por construcao: o unico caller de
 * `captureFromTrace` e o gateway, entao registrar uma decisao custava uma chamada de
 * provider. Pior, `OperatorLearningRuntimeCaptureService` recusa tudo que nao declare
 * `operator_text` — guarda deliberada, posta depois de o detector ter aprendido o
 * PREAMBULO de um prompt de subagente como se fosse padrao do operador.
 *
 * O que este teste protege, e cada caso ja falhou de verdade neste corpus:
 *  - as tres guardas de entrada, porque um registro sem PRINCIPIO e diario, nao estudo;
 *  - `confidence` como eixo, porque chutar e acertar nao e aprender — sem separar os dois
 *    "acertou", o segundo vira ruido que sobe a metrica;
 *  - o caso "range larga", que e a regressao que apagou o primeiro registro real do
 *    operador: `rg` casava dentro de "la_rg_a", a privacidade subia para `sensitive` e
 *    `redactIfSensitive` trocava o texto duravel por um hash. O principio sumia do banco.
 */
final class AtlasStudyLogCommandTest extends TestCase
{
    use CreatesOperatorIntelligenceTables;
    use CreatesStudyVocabularyTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOperatorIntelligenceTables();
        $this->createStudyVocabularyTable();
        config([
            'atlas_operator_intelligence.default_operator_id' => 'vitor',
            'atlas_operator_intelligence.shadow_mode' => true,
            'atlas_operator_intelligence.auto_apply_enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropStudyVocabularyTable();
        $this->dropOperatorIntelligenceTables();
        parent::tearDown();
    }

    private function verbete(string $termo, string $definicao, string $dominio = 'poker'): void
    {
        DB::table('atlas_study_vocabulary')->insert([
            'id' => (string) Str::uuid(),
            'domain' => $dominio,
            'term' => $termo,
            'term_normalized' => Str::lower(Str::ascii($termo)),
            'definition' => $definicao,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<array<string,string>> $linhas */
    private function arquivoDeLote(array $linhas): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'lote').'.json';
        file_put_contents($caminho, json_encode($linhas, JSON_UNESCAPED_UNICODE));

        return $caminho;
    }

    public function test_lote_grava_todas_as_decisoes_validas(): void
    {
        $this->verbete('C-bet', 'aposta de continuidade.');
        $arquivo = $this->arquivoDeLote([
            ['spot' => 'CO abre, flop A72', 'decision' => 'aposta 33%', 'why' => 'board seco favorece minha range', 'term' => 'C-bet'],
            ['spot' => 'BTN abre, flop K83', 'decision' => 'aposta 33%', 'why' => 'mesmo raciocinio de vantagem', 'term' => 'c-BET'],
            ['spot' => 'SB completa, flop 962', 'decision' => 'check', 'why' => 'range dele conecta melhor', 'confidence' => 'guess'],
        ]);

        $this->artisan('atlas:study:log', ['--import' => $arquivo, '--json' => true])->assertExitCode(0);
        @unlink($arquivo);

        $this->assertSame(3, OperatorLearningSignal::query()->count());
        // O lote nao afrouxa nada: a ancora continua resolvida, inclusive na outra caixa.
        $this->assertSame(2, OperatorLearningSignal::query()->get()
            ->filter(fn ($s): bool => data_get($s->metadata, 'vocabulary_term') === 'C-bet')->count());
    }

    public function test_lote_usa_as_MESMAS_guardas_e_nao_uma_porta_mais_frouxa(): void
    {
        $arquivo = $this->arquivoDeLote([
            ['spot' => 'spot bom', 'decision' => 'call', 'why' => 'principio valido'],
            ['spot' => 'sem principio', 'decision' => 'fold'],
            ['spot' => 'x', 'decision' => 'y', 'why' => 'z', 'confidence' => 'talvez'],
            ['spot' => 'a', 'decision' => 'b', 'why' => 'c', 'term' => 'termo-inexistente'],
        ]);

        $this->artisan('atlas:study:log', ['--import' => $arquivo, '--json' => true])->assertExitCode(1);
        @unlink($arquivo);

        // Toda vez que a validacao e duplicada para um atalho, e o atalho que vira a porta
        // de entrada do dado ruim. Aqui o lote passa pelo mesmo `registrar()`.
        $this->assertSame(1, OperatorLearningSignal::query()->count(), 'so a linha valida entra');
    }

    public function test_lote_com_recusa_devolve_falha_em_vez_de_engolir(): void
    {
        $arquivo = $this->arquivoDeLote([
            ['spot' => 'bom', 'decision' => 'call', 'why' => 'principio'],
            ['spot' => 'ruim', 'decision' => 'fold'],
        ]);

        // Exit 0 aqui seria a mentira mais cara do lote: o operador acha que registrou 20
        // maos e registrou 17, e so descobre semanas depois, procurando o que nao existe.
        $this->artisan('atlas:study:log', ['--import' => $arquivo, '--json' => true])->assertExitCode(1);
        @unlink($arquivo);

        $this->assertSame(1, OperatorLearningSignal::query()->count());
    }

    public function test_arquivo_inexistente_recusa_sem_gravar_nada(): void
    {
        $this->artisan('atlas:study:log', ['--import' => '/tmp/nao-existe-'.uniqid().'.json', '--json' => true])
            ->assertExitCode(1);

        $this->assertSame(0, OperatorLearningSignal::query()->count());
    }

    public function test_ancora_em_termo_inexistente_e_recusada(): void
    {
        $this->artisan('atlas:study:log', [
            '--spot' => 'BTN vs BB',
            '--decision' => 'call',
            '--why' => 'o preco fecha contra a range dele',
            '--term' => 'termo-que-ninguem-definiu',
            '--json' => true,
        ])->assertExitCode(1);

        // Fail-closed de proposito. Uma ancora que aponta para nada e DECORACAO: o registro
        // parece ligado ao vocabulario e nao esta, e a mentira so aparece meses depois,
        // quando o operador procura "todo principio sobre X" e volta vazio.
        $this->assertSame(0, OperatorLearningSignal::query()->count());
    }

    public function test_ancora_valida_viaja_no_sinal_ja_resolvida(): void
    {
        $this->verbete('C-bet', 'aposta de continuidade feita por quem agrediu na rodada anterior.');

        $this->artisan('atlas:study:log', [
            '--spot' => 'abri no CO, BB pagou, flop A72 rainbow',
            '--decision' => 'aposta 33%',
            '--why' => 'board seco favorece minha range, entao aposto pequeno com tudo',
            '--term' => 'c-bet',
            '--json' => true,
        ])->assertExitCode(0);

        $signal = OperatorLearningSignal::query()->firstOrFail();

        $this->assertSame('C-bet', data_get($signal->metadata, 'vocabulary_term'));
        $this->assertSame('c-bet', data_get($signal->metadata, 'vocabulary_term_normalized'));
        $this->assertContains('term:C-bet', (array) $signal->evidence_refs);
    }

    public function test_ancora_resolve_por_caixa_e_acento(): void
    {
        $this->verbete('Mão', 'as cartas que o jogador segura.');

        // O operador digita como lembra. As tres formas tem de achar o mesmo verbete,
        // senao a busca "todo principio sobre X" depende de ortografia.
        foreach (['mao', 'MÃO', 'Mao'] as $forma) {
            OperatorLearningSignal::query()->delete();
            $this->artisan('atlas:study:log', [
                '--spot' => 'spot qualquer',
                '--decision' => 'call',
                '--why' => 'principio ancorado na forma '.$forma,
                '--term' => $forma,
                '--json' => true,
            ])->assertExitCode(0);

            $this->assertSame('mao', data_get(OperatorLearningSignal::query()->firstOrFail()->metadata, 'vocabulary_term_normalized'));
        }
    }

    public function test_termo_de_outro_dominio_nao_ancora(): void
    {
        $this->verbete('Refactor', 'mudar a forma sem mudar o comportamento.', 'engenharia');

        // O vocabulario e por dominio. Um termo de engenharia nao pode ancorar um
        // principio de poquer so porque a palavra existe em algum lugar do banco.
        $this->artisan('atlas:study:log', [
            '--spot' => 'spot de poquer',
            '--decision' => 'fold',
            '--why' => 'principio qualquer',
            '--term' => 'Refactor',
            '--domain' => 'poker',
            '--json' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, OperatorLearningSignal::query()->count());
    }

    public function test_recusa_quando_falta_o_principio(): void
    {
        $this->artisan('atlas:study:log', [
            '--spot' => 'BTN abre, eu no BB',
            '--decision' => 'call',
            '--json' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, OperatorLearningSignal::query()->count(), 'registro sem principio nao pode entrar no corpus');
    }

    public function test_recusa_confianca_fora_do_eixo(): void
    {
        $this->artisan('atlas:study:log', [
            '--spot' => 'CO abre 2.5bb',
            '--decision' => 'fold',
            '--why' => 'a range dele nessa posicao domina meu holding',
            '--confidence' => 'talvez',
            '--json' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, OperatorLearningSignal::query()->count());
    }

    public function test_decisao_declarada_vira_sinal_com_operator_text_declared(): void
    {
        $this->artisan('atlas:study:log', [
            '--spot' => 'CO abre 2.5bb, eu no BB com KJo, 40bb efetivo',
            '--decision' => 'call',
            '--why' => 'contra o open dele nessa posicao o KJo tem equity suficiente fechando a acao',
            '--confidence' => 'principled',
            '--domain' => 'poker',
            '--json' => true,
        ])->assertExitCode(0);

        $signal = OperatorLearningSignal::query()->firstOrFail();

        // O campo exato que `OperatorPatternDetector:77` exige para minerar padrao.
        // Sem ele o sinal entra e nunca e lido — producao sem consumidor.
        $this->assertTrue((bool) data_get($signal->metadata, 'operator_text_declared'));
        $this->assertTrue((bool) data_get($signal->metadata, 'commitment_before_outcome'));
        $this->assertSame('domain', $signal->scope_type);
        $this->assertSame('poker', $signal->scope_id);
        $this->assertSame(0.9, round((float) $signal->confidence, 2));
    }

    public function test_chute_entra_com_peso_baixo(): void
    {
        $this->artisan('atlas:study:log', [
            '--spot' => 'board 9h7h2c, ele check-raise',
            '--decision' => 'fold',
            '--why' => 'senti que ele tinha o flush draw completado',
            '--confidence' => 'guess',
            '--json' => true,
        ])->assertExitCode(0);

        $signal = OperatorLearningSignal::query()->firstOrFail();

        // Um palpite que acertou nao pode pesar como regra do operador.
        $this->assertSame(0.25, round((float) $signal->confidence, 2));
        $this->assertSame('guess', data_get($signal->metadata, 'confidence_kind'));
    }

    public function test_principio_com_range_larga_sobrevive_ao_banco(): void
    {
        $principio = 'contra open range larga de CO eu defendo mais amplo no BB';

        $this->artisan('atlas:study:log', [
            '--spot' => 'CO abre 2.5bb',
            '--decision' => 'defend',
            '--why' => $principio,
            '--confidence' => 'principled',
            '--json' => true,
        ])->assertExitCode(0);

        $signal = OperatorLearningSignal::query()->firstOrFail();

        // A prova e o TEXTO, nao o rotulo: `sensitive` dispara redacao, e o que se perde
        // e justamente o principio que o corpus existe para guardar.
        $this->assertSame($principio, $signal->normalized_claim, 'o principio nao pode virar hash a caminho do banco');
        $this->assertSame('normal', $signal->privacy_class);
        $this->assertFalse((bool) data_get($signal->metadata, 'redacted_at_rest'));
    }
}
