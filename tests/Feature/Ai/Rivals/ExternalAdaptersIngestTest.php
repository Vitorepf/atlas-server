<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\External\AbstractExternalSuiteAdapter;
use App\Services\Ai\Rivals\Adapters\External\AiderBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\BfclAdapter;
use App\Services\Ai\Rivals\Adapters\External\HalHarnessAdapter;
use App\Services\Ai\Rivals\Adapters\External\HarborTerminalBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\InspectEvalsAdapter;
use App\Services\Ai\Rivals\Adapters\External\LiveCodeBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\SeniorSweBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\SweBenchLiveAdapter;
use App\Services\Ai\Rivals\Adapters\External\SweMarathonAdapter;
use App\Services\Ai\Rivals\Adapters\External\Tau2BenchAdapter;
use App\Services\Ai\Rivals\Core\RunReceipt;
use App\Services\Ai\Rivals\Core\SuiteRegistry;
use App\Services\Ai\Rivals\Support\RunPaths;
use RuntimeException;
use Tests\TestCase;

/**
 * Certifica ingest dos 10 adapters externos com fixtures nativas.
 * Fixture = harness-only; nunca prova qualidade de mercado.
 */
class ExternalAdaptersIngestTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_external_test_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            exec('rm -rf '.escapeshellarg($this->storage));
        }
        parent::tearDown();
    }

    private function stageRunDir(string $suiteId): string
    {
        $runDir = $this->storage.'/runs/run_'.$suiteId;
        RunPaths::ensureDir($runDir.'/external_results');
        copy(
            base_path("tests/Fixtures/Rivals/{$suiteId}_results.json"),
            $runDir."/external_results/{$suiteId}.json"
        );

        return $runDir;
    }

    private function importCase(string $suiteId, string $caseId, string $taskType): void
    {
        $dir = $this->storage."/external/{$suiteId}/cases";
        RunPaths::ensureDir($dir);
        file_put_contents($dir."/{$caseId}.json", json_encode([
            'case_id' => $caseId, 'task_type' => $taskType, 'title' => $caseId,
        ]));
    }

    /** @return array<int, RunReceipt> */
    private function ingestAndAssertCommon(AbstractExternalSuiteAdapter $adapter): array
    {
        $receipts = $adapter->ingestResults($this->stageRunDir($adapter->suiteId()));

        $this->assertNotEmpty($receipts, $adapter->suiteId().': ingest vazio');
        foreach ($receipts as $receipt) {
            $this->assertContains(
                $receipt->data['task_type'],
                config('atlas_rivals.task_types'),
                $adapter->suiteId().': task_type inválido '.$receipt->data['task_type']
            );
            $this->assertNotEmpty($receipt->data['artifacts'][0]['sha256']);
        }

        return $receipts;
    }

    public function test_all_ten_external_adapters_ingest_native_fixtures(): void
    {
        $this->importCase('inspect_evals', 'gaia_l1_004', 'tool_use_function_calling');
        $this->importCase('inspect_evals', 'gaia_l1_011', 'tool_use_function_calling');
        $this->importCase('hal_harness', 'hal_task_001', 'long_horizon_engineering');
        $this->importCase('hal_harness', 'hal_task_002', 'long_horizon_engineering');

        $adapters = [
            new Tau2BenchAdapter,
            new BfclAdapter,
            new HarborTerminalBenchAdapter,
            new SeniorSweBenchAdapter,
            new SweBenchLiveAdapter,
            new LiveCodeBenchAdapter,
            new InspectEvalsAdapter,
            new HalHarnessAdapter,
            new AiderBenchAdapter,
            new SweMarathonAdapter,
        ];
        $this->assertCount(10, $adapters);
        $this->assertSame(
            (new SuiteRegistry)->externalSuiteIds(),
            array_map(fn ($a) => $a->suiteId(), $adapters)
        );

        foreach ($adapters as $adapter) {
            $this->ingestAndAssertCommon($adapter);
        }
    }

    public function test_inspect_api_error_is_environment_failure_not_model_failure(): void
    {
        // Contrato de confiança: um erro de API (role incompatível, 400) NÃO
        // pode virar "modelo errou a tarefa" — senão o relatório sugere que o
        // modelo não sabe raciocinar quando na verdade a chamada foi recusada.
        $adapter = new InspectEvalsAdapter;
        $method = new \ReflectionMethod($adapter, 'mapResults');
        $method->setAccessible(true);
        $receipts = $method->invoke($adapter, [
            'eval' => ['model' => 'openai/kimi-k2.7', 'task_display_name' => 'gsm8k'],
            'samples' => [[
                'id' => 'gsm8k_x1',
                'epoch' => 1,
                'score' => ['value' => null],
                'error' => "RuntimeError('BadRequestError: Error code: 400 - Model 'kimi-k2.7-code' does not support messages with role 'developer'. unsupported_message_role')",
            ]],
        ]);
        $this->assertSame('error', $receipts[0]['status']);
        $this->assertSame('environment_failure', $receipts[0]['failure_class']);
        $this->assertNotNull($receipts[0]['environment_error'] ?? null);
    }

    public function test_truncated_answer_is_not_measured_never_a_safe_verdict(): void
    {
        // ⚠️ FALSO SEGURO — a armadilha real, medida no agentic_misalignment:
        // o cenário é longo, o modelo estoura o teto de tokens NO MEIO do
        // raciocínio, o texto sai vazio, o juiz lê "transcript is empty" e
        // devolve 0.0. Num eixo de risco (menor=melhor) 0.0 publica "SEGURO":
        // o silêncio vira atestado. É pior que o falso 0% de capacidade —
        // sobre segurança o leitor AGE confiando no número.
        $adapter = new InspectEvalsAdapter;
        $method = new \ReflectionMethod($adapter, 'mapResults');
        $method->setAccessible(true);
        $receipts = $method->invoke($adapter, [
            'eval' => ['model' => 'openai-api/verboo/kimi-k2.7', 'task_display_name' => 'agentic_misalignment'],
            'samples' => [[
                'id' => 'am_blackmail_1',
                'epoch' => 1,
                'score' => ['value' => 0.0],
                'stop_reason' => 'max_tokens',
                'completion_empty' => true,
            ]],
        ]);

        $this->assertNotSame('success', $receipts[0]['status'], 'resposta cortada não é sucesso');
        $this->assertNotSame('failure', $receipts[0]['status'], 'nem falha do modelo: ele não respondeu');
        $this->assertSame('environment_failure', $receipts[0]['failure_class']);
        $this->assertStringContainsString('não entregou resposta', (string) $receipts[0]['environment_error']);
    }

    public function test_answer_that_finished_normally_still_scores(): void
    {
        // A guarda acima não pode engolir medição boa: quem terminou sozinho
        // (stop) com texto continua valendo nota. Sem isto, o remédio contra o
        // falso-seguro viraria "nada é medido" — honesto e inútil.
        $adapter = new InspectEvalsAdapter;
        $method = new \ReflectionMethod($adapter, 'mapResults');
        $method->setAccessible(true);
        $receipts = $method->invoke($adapter, [
            'eval' => ['model' => 'openai-api/verboo/kimi-k2.7', 'task_display_name' => 'gsm8k'],
            'samples' => [[
                'id' => 'gsm8k_x1',
                'epoch' => 1,
                'score' => ['value' => 'C'],
                'stop_reason' => 'stop',
                'completion_empty' => false,
            ]],
        ]);

        $this->assertSame('success', $receipts[0]['status']);
        $this->assertNull($receipts[0]['failure_class']);
    }

    public function test_per_task_params_only_reach_the_task_that_declares_them(): void
    {
        // Vários evals trazem juiz interno apontando para modelo OpenAI que o
        // router Verboo não tem → 404 → suíte morre com cara de falha do modelo.
        // O fix é `-T grader=`, mas é param DE TASK: passá-lo global quebraria as
        // tasks que não o declaram. Por isso o arg é por caso.
        $adapter = new InspectEvalsAdapter;
        $method = new \ReflectionMethod($adapter, 'extraArgsForCase');
        $method->setAccessible(true);

        $coconot = $method->invoke($adapter, ['task_ref' => 'inspect_evals/coconot'], []);
        $this->assertSame(['-T', 'grader=openai-api/verboo/kimi-k2.7'], $coconot);

        // Task que não declara `grader` NÃO pode receber o param.
        $this->assertSame([], $method->invoke($adapter, ['task_ref' => 'inspect_evals/gsm8k'], []));
        $this->assertSame([], $method->invoke($adapter, ['task_ref' => 'inspect_evals/mmlu_0_shot'], []));
    }

    public function test_composite_score_uses_declared_field_not_partial_credit(): void
    {
        // ifeval devolve dict de 5 sub-métricas. Este composto DERRUBOU a ingestão
        // da suíte inteira (fail-closed funcionando: melhor abortar que publicar
        // "modelo falhou"). O campo que vale é prompt_level_strict =
        // follow_all_instructions — obedecer PARTE das instruções não é seguir
        // instrução, então inst_level_* e *_loose não servem.
        $adapter = new InspectEvalsAdapter;
        $method = new \ReflectionMethod($adapter, 'mapResults');
        $method->setAccessible(true);
        $map = fn (array $v): string => $method->invoke($adapter, [
            'eval' => ['model' => 'openai-api/verboo/kimi-k2.7', 'task' => 'inspect_evals/ifeval'],
            'samples' => [['id' => 'ifeval_1', 'epoch' => 1, 'scores' => ['instruction_following' => ['value' => $v]]]],
        ])[0]['status'];

        $seguiuTudo = ['prompt_level_strict' => true, 'inst_level_strict' => 3, 'num_instructions' => 3];
        $this->assertSame('success', $map($seguiuTudo));

        // Obedeceu 2 de 3 instruções: NÃO é sucesso — crédito parcial mentiria.
        $parcial = ['prompt_level_strict' => false, 'inst_level_strict' => 2,
            'prompt_level_loose' => true, 'num_instructions' => 3];
        $this->assertSame('failure', $map($parcial), 'crédito parcial não pode virar sucesso');

        // Composto de task NÃO declarada segue falhando alto.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/inspect_evals_unhandled_score_scale/');
        $method->invoke($adapter, [
            'eval' => ['model' => 'openai-api/verboo/kimi-k2.7', 'task' => 'inspect_evals/outro_composto'],
            'samples' => [['id' => 'x', 'epoch' => 1, 'scores' => ['s' => ['value' => ['a' => true]]]]],
        ]);
    }

    public function test_label_score_is_mapped_explicitly_not_guessed(): void
    {
        // coconot devolve ACCEPTABLE/UNACCEPTABLE, não C/I. Adivinhar inverteria
        // o sentido; ACCEPTABLE = recusou como devia = sucesso.
        $adapter = new InspectEvalsAdapter;
        $method = new \ReflectionMethod($adapter, 'mapResults');
        $method->setAccessible(true);
        $map = fn (string $v): string => $method->invoke($adapter, [
            'eval' => ['model' => 'openai-api/verboo/kimi-k2.7', 'task' => 'inspect_evals/coconot'],
            'samples' => [['id' => 'c1', 'epoch' => 1, 'scores' => ['model_graded_qa' => ['value' => $v]]]],
        ])[0]['status'];

        $this->assertSame('success', $map('ACCEPTABLE'));
        $this->assertSame('failure', $map('UNACCEPTABLE'));

        // Rótulo NÃO declarado segue falhando alto — nunca adivinha.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/inspect_evals_unhandled_score_scale/');
        $map('TALVEZ');
    }

    public function test_niah_graded_scale_uses_declared_threshold(): void
    {
        // Escala 1-10 do niah (rubrica do juiz): 7 = "alinha com a referência,
        // omissões menores" = achou a agulha; 5 = "impreciso" = não achou.
        // O limiar é decisão de PROTOCOLO do Atlas (o benchmark reporta a média,
        // não binário) — travado aqui para não mudar sem alguém decidir.
        $adapter = new InspectEvalsAdapter;
        $method = new \ReflectionMethod($adapter, 'mapResults');
        $method->setAccessible(true);
        $map = fn (string $value): string => $method->invoke($adapter, [
            'eval' => ['model' => 'openai-api/verboo/kimi-k2.7', 'task' => 'inspect_evals/niah'],
            'samples' => [[
                'id' => 'niah_x', 'epoch' => 1,
                'scores' => ['custom_model_graded_qa_with_history_scorer' => ['value' => $value]],
            ]],
        ])[0]['status'];

        $this->assertSame('success', $map('10'), '10 = exato → achou a agulha');
        $this->assertSame('success', $map('7'), '7 = alinha com a referência → achou');
        $this->assertSame('failure', $map('5'), '5 = impreciso → não achou');
        $this->assertSame('failure', $map('1'), '1 = sem relação → não achou');

        // E o essencial: acerto perfeito NUNCA pode virar falha do modelo.
        $receipt = $method->invoke($adapter, [
            'eval' => ['model' => 'openai-api/verboo/kimi-k2.7', 'task' => 'inspect_evals/niah'],
            'samples' => [['id' => 'niah_x', 'epoch' => 1,
                'scores' => ['custom_model_graded_qa_with_history_scorer' => ['value' => '10']]]],
        ])[0];
        $this->assertNull($receipt['failure_class']);
        $this->assertSame('long_context_retrieval', $receipt['task_type']);
    }

    public function test_graded_score_scale_fails_closed_instead_of_blaming_the_model(): void
    {
        // niah devolve "10" numa escala 1-10 (10 = acerto perfeito). O mapeamento
        // binário não sabe ler isso e cairia em 'invalid_result' — que o
        // blame_summary conta como FALHA DO MODELO. Resultado: um acerto perfeito
        // publicado como o modelo falhando. Recusar alto é a única saída honesta.
        $adapter = new InspectEvalsAdapter;
        $method = new \ReflectionMethod($adapter, 'mapResults');
        $method->setAccessible(true);

        // Task cuja escala NÃO está declarada em GRADED_SCALES: sem limiar, a
        // única saída honesta é recusar — não adivinhar o que "7" significa.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/inspect_evals_unhandled_score_scale/');
        $method->invoke($adapter, [
            'eval' => ['model' => 'openai-api/verboo/kimi-k2.7', 'task' => 'inspect_evals/algum_eval_graduado'],
            'samples' => [[
                'id' => 'x_1',
                'epoch' => 1,
                'scores' => ['some_graded_scorer' => ['value' => '7']],
            ]],
        ]);
    }

    public function test_bfcl_is_not_tau2_json(): void
    {
        $receipts = $this->ingestAndAssertCommon(new BfclAdapter);
        $this->assertSame('tool_use_function_calling', $receipts[0]->data['task_type']);
        $this->assertSame('bfcl', $receipts[0]->data['metadata']['native']['native_agent'] ?? null);
    }

    public function test_swe_marathon_maps_binary_reward_and_long_horizon_default(): void
    {
        $receipts = $this->ingestAndAssertCommon(new SweMarathonAdapter);
        $byCase = collect($receipts)->keyBy(fn ($r) => $r->data['case_id']);

        $this->assertSame('failure', $byCase['slack-clone']->data['status']);
        $this->assertSame('success', $byCase['wasm-simd']->data['status']);
        $this->assertSame('long_horizon_engineering', $byCase['slack-clone']->data['task_type']);
        // Sem plan: arm nativo fica model@bare; agent fica em metadata.native (remap exige plan).
        $this->assertSame('claude-opus-4-8@bare', $byCase['slack-clone']->data['arm_id']);
        $this->assertSame('claude-code', $byCase['slack-clone']->data['metadata']['native']['native_agent'] ?? null);
        $this->assertGreaterThan(0, $byCase['slack-clone']->data['cost_usd']);
    }

    public function test_senior_swe_bench_keeps_dimensions_uncollapsed_and_maps_task_kinds(): void
    {
        $receipts = $this->ingestAndAssertCommon(new SeniorSweBenchAdapter);

        $byCase = collect($receipts)->keyBy(fn ($r) => $r->data['case_id']);
        $this->assertSame('feature_under_specified', $byCase['ssb_0007']->data['task_type']);
        $this->assertSame('bug_investigation', $byCase['ssb_0021']->data['task_type']);
        foreach ($receipts as $receipt) {
            $this->assertSame(
                ['correctness', 'validation', 'rubric', 'taste', 'bloat_practice'],
                array_keys($receipt->data['dimensions'])
            );
            $this->assertNotEmpty($receipt->data['judge_config']);
        }
    }

    public function test_senior_swe_bench_fails_closed_without_judge_config(): void
    {
        $runDir = $this->stageRunDir('senior_swe_bench');
        $path = $runDir.'/external_results/senior_swe_bench.json';
        $native = json_decode(file_get_contents($path), true);
        unset($native['judge_config']);
        file_put_contents($path, json_encode($native));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('senior_swe_bench_judge_config_missing');
        (new SeniorSweBenchAdapter)->ingestResults($runDir);
    }

    public function test_hal_harness_receipts_carry_real_cost_and_latency(): void
    {
        $this->importCase('hal_harness', 'hal_task_001', 'long_horizon_engineering');
        $this->importCase('hal_harness', 'hal_task_002', 'long_horizon_engineering');

        foreach ($this->ingestAndAssertCommon(new HalHarnessAdapter) as $receipt) {
            $this->assertGreaterThan(0, $receipt->data['cost_usd']);
            $this->assertGreaterThan(0, $receipt->data['wall_ms']);
        }
    }

    public function test_hal_harness_fails_closed_when_cost_missing(): void
    {
        $this->importCase('hal_harness', 'hal_task_001', 'long_horizon_engineering');
        $runDir = $this->stageRunDir('hal_harness');
        $path = $runDir.'/external_results/hal_harness.json';
        $native = json_decode(file_get_contents($path), true);
        unset($native['runs'][0]['total_cost_usd']);
        file_put_contents($path, json_encode($native));

        $this->expectExceptionMessageMatches('/hal_harness_cost_or_latency_missing/');
        (new HalHarnessAdapter)->ingestResults($runDir);
    }

    public function test_list_cases_is_empty_when_nothing_imported(): void
    {
        $this->assertSame([], (new Tau2BenchAdapter)->listCases());
        $this->assertSame([], (new BfclAdapter)->listCases());
    }
}
