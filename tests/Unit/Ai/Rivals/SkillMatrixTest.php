<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\SkillMatrix;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SkillMatrixTest extends TestCase
{
    private string $storage;

    private string $runId = '20260715_000000_5c111111';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_skill_matrix_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
        RunPaths::ensureDir(RunPaths::nativeResultsDir($this->runId));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_skill_comes_from_sample_metadata_not_from_a_hand_written_list(): void
    {
        // O relatório publicava "Multilíngue 94%" tendo medido só bengali e
        // "Viés social 100%" tendo medido só idade: o rótulo prometia o domínio,
        // a amostra cobria uma fatia. A habilidade tem de sair do metadata que o
        // próprio benchmark declara sobre a pergunta — se saísse de lista fixa,
        // envelheceria contra o dado, como já aconteceu com a frase de escopo.
        $this->unit('mmlu_0_shot', 'q1', ['subject' => 'college_physics'], 'bare');
        $this->unit('bbq', 'q2', ['category' => 'Race_ethnicity'], 'bare');
        $this->receipts([
            ['case_id' => 'q1', 'arm_id' => 'm@bare', 'status' => 'success'],
            ['case_id' => 'q2', 'arm_id' => 'm@bare', 'status' => 'success'],
        ]);

        $skills = array_column((new SkillMatrix)->forRun($this->runId), 'skill');

        $this->assertContains('mmlu_0_shot:college_physics', $skills);
        $this->assertContains('bbq:Race_ethnicity', $skills);
    }

    public function test_environment_failure_is_not_a_zero_it_is_absent(): void
    {
        // ⚠️ A mesma armadilha do falso-seguro, virada para a capacidade: uma
        // resposta cortada por teto de tokens não é "o modelo errou", é medição
        // que não houve. Contá-la como 0 publica "incapaz" sobre o que ninguém
        // mediu. Sem resposta não há nota — a habilidade some da conta, e a
        // lacuna aparece no n, que é auditável.
        $this->unit('gsm8k', 'ok', [], 'bare');
        $this->unit('gsm8k', 'cortado', [], 'bare');
        $this->receipts([
            ['case_id' => 'ok', 'arm_id' => 'm@bare', 'status' => 'success'],
            // Forma REAL do recibo de ambiente: status=error + failure_class.
            // Verificada num recibo vivo — inventar a forma aqui testaria a minha
            // suposição, não o contrato.
            [
                'case_id' => 'cortado',
                'arm_id' => 'm@bare',
                'status' => 'error',
                'failure_class' => 'environment_failure',
            ],
        ]);

        $row = (new SkillMatrix)->forRun($this->runId)[0];

        $this->assertSame(1, $row['bare_n'], 'a unidade cortada não pode entrar na conta');
        $this->assertSame(1.0, $row['bare'], 'contá-la como 0 daria 50% — nota inventada sobre não-medição');
    }

    public function test_missing_atlas_arm_never_reads_as_green(): void
    {
        // O operador pediu vermelho quando o Atlas piora e verde quando melhora.
        // A tentação é pintar de verde o que não tem braço Atlas — e aí ausência
        // de medição viraria elogio. Sem os dois braços não há comparação: é
        // lacuna declarada, e o relatório mostra a lacuna.
        $this->unit('musr', 'q1', [], 'bare');
        $this->receipts([['case_id' => 'q1', 'arm_id' => 'm@bare', 'status' => 'success']]);

        $row = (new SkillMatrix)->forRun($this->runId)[0];

        $this->assertSame('atlas_nao_medido', $row['verdict']);
        $this->assertNull($row['atlas']);
        $this->assertNull($row['delta'], 'sem braço Atlas não existe delta para pintar');
    }

    public function test_atlas_worse_is_reported_as_worse(): void
    {
        // O ponto do benchmark é achar onde o Atlas FALHA. Se o caminho do
        // "Atlas pior" não funcionasse, o relatório só saberia elogiar.
        $this->unit('gsm8k', 'q1', [], 'bare');
        $this->receipts([
            ['case_id' => 'q1', 'arm_id' => 'm@bare', 'status' => 'success'],
            ['case_id' => 'q1', 'arm_id' => 'm@atlas_dev', 'status' => 'failure'],
        ]);

        $row = (new SkillMatrix)->forRun($this->runId)[0];

        $this->assertSame('atlas_pior', $row['verdict']);
        $this->assertSame(-1.0, $row['delta']);
    }

    public function test_pair_without_proof_of_atlas_is_not_blamed_on_atlas(): void
    {
        // Bug REAL, meu, pego na conferência contra a aba Uplift: esta matriz
        // dizia "terminal_bench: Atlas 50pp PIOR" contando a falha do tb_hello
        // como falha do Atlas — enquanto o uplift tinha excluído tb_hello por
        // FALTA DE PROVA de que o Atlas rodou naquela unidade. Culpar o Atlas
        // por unidade não provada como Atlas é o mesmo defeito de atribuição que
        // o resto do relatório recusa; a aba tem de herdar a recusa, não
        // re-julgar. Excluir dos dois braços mantém o mesmo conjunto.
        $this->unit('tb', 'sem_prova', [], 'bare');
        $this->unit('tb', 'provado', [], 'bare');
        $this->receipts([
            ['case_id' => 'sem_prova', 'arm_id' => 'm@bare', 'status' => 'success'],
            ['case_id' => 'sem_prova', 'arm_id' => 'm@atlas_dev', 'status' => 'failure'],
            ['case_id' => 'provado', 'arm_id' => 'm@bare', 'status' => 'failure'],
            ['case_id' => 'provado', 'arm_id' => 'm@atlas_dev', 'status' => 'failure'],
        ]);
        file_put_contents(
            RunPaths::runDir($this->runId).'/uplift.json',
            (string) json_encode(['excluded_pair_keys' => ['sem_prova|1']]),
        );

        $row = (new SkillMatrix)->forRun($this->runId)[0];

        $this->assertSame(1, $row['bare_n'], 'o par sem prova sai dos DOIS braços');
        $this->assertSame(1, $row['atlas_n']);
        $this->assertSame('empate', $row['verdict'], 'sem o par excluído, Atlas não é pior');
    }

    public function test_arms_are_compared_on_the_same_units_never_on_different_sets(): void
    {
        // O braço Atlas morre em unidades que o bare atravessa — medido no
        // terminal_bench: das 3 tarefas, só a trivial (`tb_hello`) produziu
        // recibo com Atlas; nas outras duas ele recusa. Sem interseção, a
        // habilidade sairia com bare=3 casos contra atlas=1, e o delta compararia
        // o Atlas na tarefa FÁCIL contra o bare nas três, incluindo as difíceis.
        //
        // O viés é grande e é A FAVOR do Atlas: as unidades que sobrevivem no
        // braço Atlas são, por construção, as mais fáceis. Publicar isso seria
        // inventar um multiplicador a partir da própria fragilidade do Atlas.
        $this->unit('tb', 'facil', [], 'bare');
        $this->unit('tb', 'dificil_1', [], 'bare');
        $this->unit('tb', 'dificil_2', [], 'bare');
        $this->receipts([
            // O bare atravessa as três: acerta a fácil, erra as duas difíceis.
            ['case_id' => 'facil', 'arm_id' => 'm@bare', 'status' => 'success'],
            ['case_id' => 'dificil_1', 'arm_id' => 'm@bare', 'status' => 'failure'],
            ['case_id' => 'dificil_2', 'arm_id' => 'm@bare', 'status' => 'failure'],
            // O Atlas só chegou na fácil, e acertou.
            ['case_id' => 'facil', 'arm_id' => 'm@atlas_dev', 'status' => 'success'],
        ]);

        $row = (new SkillMatrix)->forRun($this->runId)[0];

        $this->assertSame(1, $row['bare_n'], 'o bare tem de ser restrito às unidades que o Atlas também correu');
        $this->assertSame(1, $row['atlas_n']);
        $this->assertSame(1.0, $row['bare'], 'na unidade comum o bare acertou');
        $this->assertSame(0.0, $row['delta'], 'empate na mesma amostra — NÃO +67pp inventado pelo conjunto');
        $this->assertFalse($row['conclusive']);
    }

    public function test_delta_without_signal_is_not_conclusive(): void
    {
        // 6/9 contra 9/9 é "+33 pp" — e o intervalo é [-3, +65], que contém o
        // zero. Publicar isso em verde afirma um ganho que a amostra não
        // sustenta. Com 9 tarefas por braço só se afirma acima de 44 pp; é por
        // isto que repetição barata compra conclusão e amostra pequena não.
        $receipts = [];
        for ($i = 1; $i <= 9; $i++) {
            $this->unit('gsm8k', "q{$i}", [], 'bare');
            $receipts[] = ['case_id' => "q{$i}", 'arm_id' => 'm@bare', 'status' => $i <= 6 ? 'success' : 'failure'];
            $receipts[] = ['case_id' => "q{$i}", 'arm_id' => 'm@atlas_dev', 'status' => 'success'];
        }
        $this->receipts($receipts);

        $row = (new SkillMatrix)->forRun($this->runId)[0];

        $this->assertSame(0.3333, $row['delta'], 'o fato bruto continua +33 pp');
        $this->assertSame('atlas_melhor', $row['verdict'], 'verdict é o fato, não a afirmação');
        $this->assertFalse($row['conclusive'], '+33 pp com n=9 não separa do zero');
        $this->assertLessThan(0.0, $row['delta_ci_low'], 'o intervalo cruza o zero');
        $this->assertGreaterThan(0.0, $row['delta_ci_high']);
    }

    public function test_delta_with_signal_is_conclusive(): void
    {
        // O outro lado da moeda: sem este caminho, exigir intervalo viraria uma
        // desculpa para nunca afirmar nada, e o relatório seria inútil de outro
        // jeito. 0/9 contra 9/9 é [+58, +100] — separa do zero e AFIRMA.
        $receipts = [];
        for ($i = 1; $i <= 9; $i++) {
            $this->unit('gsm8k', "q{$i}", [], 'bare');
            $receipts[] = ['case_id' => "q{$i}", 'arm_id' => 'm@bare', 'status' => 'failure'];
            $receipts[] = ['case_id' => "q{$i}", 'arm_id' => 'm@atlas_dev', 'status' => 'success'];
        }
        $this->receipts($receipts);

        $row = (new SkillMatrix)->forRun($this->runId)[0];

        $this->assertTrue($row['conclusive']);
        $this->assertGreaterThan(0.0, $row['delta_ci_low'], 'o intervalo inteiro fica acima do zero');
    }

    public function test_skill_gets_portuguese_name_and_domain_derived_from_the_data(): void
    {
        // A tela imprimia o slug cru (`bbq:Age`) e `domain: null` nas 30 linhas:
        // 28 exigiam legenda e nada agrupava. O mapa não precisa de taxonomia
        // nova — o recibo já traz `task_type`, que é a MESMA chave de
        // CAPABILITIES.task_types, e SUB_CAPABILITIES já tem o rótulo humano.
        // Derivar em vez de listar à mão: lista escrita à mão envelhece contra o
        // dado que ela resume.
        file_put_contents(
            RunPaths::nativeManifestPath($this->runId),
            (string) json_encode(['suite_id' => 'inspect_evals']),
        );
        $this->unit('bbq', 'Age_00000', ['category' => 'Age'], 'bare');
        $this->receipts([
            ['case_id' => 'Age_00000', 'arm_id' => 'm@bare', 'status' => 'success', 'task_type' => 'social_bias'],
        ]);

        $row = (new SkillMatrix)->forRun($this->runId)[0];

        $this->assertSame('Viés social', $row['domain_label']);
        $this->assertSame('social_bias', $row['domain']);
        $this->assertSame('Responder pelo contexto, não pelo estereótipo', $row['label']);
        // O slug continua, para auditar; ele só deixa de ser o que o humano lê.
        $this->assertSame('bbq:Age', $row['skill']);
    }

    /** @param array<string,mixed> $metadata */
    private function unit(string $instrument, string $caseId, array $metadata, string $arm): void
    {
        file_put_contents(
            RunPaths::nativeResultsDir($this->runId)."/{$caseId}__{$arm}__r1__x.json",
            (string) json_encode([
                'eval' => ['task_display_name' => $instrument],
                'samples' => [['id' => $caseId, 'metadata' => $metadata]],
            ]),
        );
    }

    /**
     * O recibo é validado por schema fail-closed (v2), então o fixture tem de
     * ser um recibo de verdade — não um objeto com os três campos que eu leio.
     * Fixture frouxo passaria aqui e mentiria sobre o contrato real.
     *
     * @param  list<array<string,mixed>>  $receipts
     */
    private function receipts(array $receipts): void
    {
        $lines = array_map(fn (array $r): string => (string) json_encode($r + [
            'schema_version' => 'atlas.rivals2.run_receipt.v2',
            'run_id' => $this->runId,
            'task_type' => 'unit',
            'repetition' => 1,
            'wall_ms' => 1,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'cost_usd' => 0,
            'artifacts' => [],
            'started_at' => '2026-07-15T00:00:00+00:00',
            'finished_at' => '2026-07-15T00:00:01+00:00',
            'claim_tier' => 'diagnostic',
            'harness_only' => false,
            'failure_class' => null,
            'field_presence' => [],
        ]), $receipts);
        file_put_contents(RunPaths::receiptsPath($this->runId), implode(PHP_EOL, $lines).PHP_EOL);
    }
}
