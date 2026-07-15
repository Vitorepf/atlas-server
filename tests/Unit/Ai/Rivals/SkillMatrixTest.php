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
