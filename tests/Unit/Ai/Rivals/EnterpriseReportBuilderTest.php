<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\EnterpriseModelDissectionBuilder;
use App\Services\Ai\Rivals\Core\EnterpriseReportBuilder;
use App\Services\Ai\Rivals\Core\EnterpriseSuiteDeliveryCatalog;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunReceipt;
use App\Services\Ai\Rivals\Core\SuiteRegistry;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EnterpriseReportBuilderTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_enterprise_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_schema_requires_ten_suite_rows_and_rejects_claim_allowed_true(): void
    {
        $payload = $this->minimalEnterprisePayload(suiteCount: 9);
        $violations = SchemaContract::validate($payload, SchemaContract::ENTERPRISE_REPORT);
        $this->assertContains('enterprise_suite_rows_count:9', $violations);

        $payload = $this->minimalEnterprisePayload(suiteCount: 10);
        $payload['claim_allowed'] = true;
        $violations = SchemaContract::validate($payload, SchemaContract::ENTERPRISE_REPORT);
        $this->assertContains('enterprise_claim_allowed_must_be_false', $violations);

        $payload = $this->minimalEnterprisePayload(suiteCount: 10);
        $this->assertSame([], SchemaContract::validate($payload, SchemaContract::ENTERPRISE_REPORT));
    }

    public function test_always_emits_ten_suite_rows_when_storage_empty(): void
    {
        $report = (new EnterpriseReportBuilder)->build();
        $this->assertSame(SchemaContract::ENTERPRISE_REPORT, $report['schema_version']);
        $this->assertCount(10, $report['suite_rows']);
        $this->assertFalse($report['claim_allowed']);
        $this->assertContains('aggregate_view_claims_live_per_run', $report['claim_blockers']);
        foreach ($report['suite_rows'] as $row) {
            $this->assertSame('not_run', $row['status']);
        }
        $this->assertSame(
            (new SuiteRegistry)->externalSuiteIds(),
            array_column($report['suite_rows'], 'suite_id'),
        );
    }

    public function test_marks_pipeline_valid_run_as_ok_and_others_not_run(): void
    {
        $runId = $this->seedSuiteRun('bfcl', pipelineValid: true, tokensPresent: true);

        $report = (new EnterpriseReportBuilder)->build();
        $bySuite = [];
        foreach ($report['suite_rows'] as $row) {
            $bySuite[$row['suite_id']] = $row;
        }
        $this->assertSame('ok', $bySuite['bfcl']['status']);
        $this->assertSame($runId, $bySuite['bfcl']['run_id']);
        $this->assertTrue($bySuite['bfcl']['pipeline_valid']);
        $this->assertSame('not_run', $bySuite['tau2_bench']['status']);
        $this->assertContains($runId, $report['included_run_ids']);
    }

    public function test_claim_allowed_is_always_false(): void
    {
        $this->seedSuiteRun('bfcl', pipelineValid: true, tokensPresent: true, internalClaim: true);
        $report = (new EnterpriseReportBuilder)->build();
        $this->assertFalse($report['claim_allowed']);
    }

    public function test_writes_json_markdown_csv(): void
    {
        $report = (new EnterpriseReportBuilder)->build();
        $this->assertFileExists(RunPaths::enterpriseReportPath());
        $this->assertFileExists(RunPaths::enterpriseMarkdownPath());
        $this->assertFileExists(RunPaths::enterpriseCsvPath());
        $this->assertFileExists(RunPaths::enterpriseHtmlPath());
        $md = file_get_contents(RunPaths::enterpriseMarkdownPath());
        $this->assertStringContainsString('Relatório de Capacidades', $md);
        $this->assertStringContainsString('bfcl', $md);
        $this->assertStringContainsString('claim_allowed', $md);
        $this->assertStringContainsString('Eixos de capacidade', $md);
        $this->assertStringContainsString('Como ler este relatório', $md);
        $this->assertStringContainsString('Leitura suite a suite', $md);
        $csv = file_get_contents(RunPaths::enterpriseCsvPath());
        $this->assertStringContainsString('suite_id', $csv);
        $html = file_get_contents(RunPaths::enterpriseHtmlPath());
        $this->assertStringContainsString('claim_allowed = false', $html);
        $this->assertStringContainsString('chart.js', $html);
        $this->assertStringContainsString('<canvas id="scatter"', $html);
        $this->assertStringContainsString('Relatório de Capacidades', $html);
        $this->assertStringContainsString('lang="pt-BR"', $html);
        $this->assertStringContainsString('Ranking do modelo', $html);
        $this->assertStringContainsString('Ranking · com e sem Atlas', $html);
        $this->assertStringContainsString('Uplift Atlas', $html);
        $this->assertStringContainsString('Inteligência', $html);
        $this->assertStringContainsString('Custo / tarefa', $html);
        $this->assertStringContainsString('Tokens / tarefa', $html);
        $this->assertStringContainsString('Tokens / s', $html);
        $this->assertStringContainsString('Tok/tarefa', $html);
        $this->assertStringContainsString('Sem Atlas', $html);
        $this->assertStringContainsString('Com Atlas', $html);
        $this->assertSame($report['report_hash'], json_decode(
            (string) file_get_contents(RunPaths::enterpriseReportPath()),
            true,
        )['report_hash']);
    }

    public function test_missing_tokens_surface_as_missing_data_status(): void
    {
        $this->seedSuiteRun('live_code_bench', pipelineValid: true, tokensPresent: false);
        $report = (new EnterpriseReportBuilder)->build();
        $row = collect($report['suite_rows'])->firstWhere('suite_id', 'live_code_bench');
        $this->assertSame('missing_data', $row['status']);
        $this->assertContains('tokens_in', $row['missing_fields']);
        $this->assertNotEmpty(array_filter(
            $report['gaps'],
            fn (string $gap): bool => str_contains($gap, 'missing_data:live_code_bench'),
        ));
    }

    public function test_delivery_inventory_and_suite_dossiers_are_complete(): void
    {
        $runId = $this->seedSuiteRun('bfcl', pipelineValid: true, tokensPresent: true);
        $unitsDir = RunPaths::runDir($runId).'/external_results/units';
        mkdir($unitsDir, 0777, true);
        file_put_contents($unitsDir.'/unit.json', json_encode([
            'model' => 'kimi',
            'results' => [[
                'case_id' => 'simple',
                'test_category' => 'simple',
                'native_category' => 'simple',
                'accuracy' => 1.0,
                'status' => 'success',
                'duration_sec' => 1.2,
                'tokens_in' => 10,
                'tokens_out' => 5,
                'cost_usd' => 0.0,
            ]],
        ], JSON_UNESCAPED_SLASHES));

        $report = (new EnterpriseReportBuilder)->build();
        $this->assertCount(10, $report['delivery_inventory']);
        $this->assertSame(10, count($report['suite_rows']));
        foreach ($report['suite_rows'] as $row) {
            $this->assertArrayHasKey('delivery', $row);
            $this->assertArrayHasKey('native_metrics', $row['delivery']);
            $this->assertArrayHasKey('delivery_coverage', $row);
            $this->assertNotEmpty($row['delivery']['atlas_report_metrics']);
        }
        $bfcl = collect($report['suite_rows'])->firstWhere('suite_id', 'bfcl');
        $this->assertNotEmpty($bfcl['native_signals']);
        $this->assertEqualsWithDelta(1.0, (float) $bfcl['native_signals'][0]['accuracy'], 0.0001);
        $this->assertNotNull($bfcl['full_metrics']);
        $this->assertSame(240.0, $bfcl['tokens_per_task']);
        $this->assertSame(120.0, $bfcl['tokens_per_second']);
        $this->assertSame(720.0, $bfcl['full_metrics']['total_tokens']);
        $this->assertContains('accuracy', $bfcl['observed_native_metric_keys']);
        $this->assertArrayHasKey('report_json', $bfcl['artifacts']);
        $this->assertTrue($bfcl['artifacts']['report_json']['present']);

        $html = file_get_contents(RunPaths::enterpriseHtmlPath());
        $this->assertStringContainsString('Dossiês das suites', $html);
        $this->assertStringContainsString('delivery_inventory', $html);
        $this->assertStringContainsString('native_signals', $html);
        $this->assertStringContainsString('tokens_per_task', $html);

        $md = file_get_contents(RunPaths::enterpriseMarkdownPath());
        $this->assertStringContainsString('Inventário de entrega', $md);
        $this->assertStringContainsString('Sinais nativos observados', $md);
        $this->assertStringContainsString('accuracy', $md);
        $this->assertStringContainsString('tokens/task', $md);
        $this->assertStringContainsString('tokens/s', $md);

        $csv = file_get_contents(RunPaths::enterpriseCsvPath());
        $this->assertStringContainsString('tokens_per_task', $csv);
        $this->assertStringContainsString('tokens_per_second', $csv);
    }

    public function test_model_dissections_always_pair_bare_and_atlas_with_epistemic_contract(): void
    {
        $this->seedSuiteRun('bfcl', pipelineValid: true, tokensPresent: true);
        $report = (new EnterpriseReportBuilder)->build();
        $this->assertArrayHasKey('model_dissections', $report);
        $this->assertFalse($report['model_dissections']['completeness']['absolute_knowledge_claim']);
        $this->assertSame(
            EnterpriseModelDissectionBuilder::requiredFacets(),
            $report['model_dissections']['epistemic_contract']['required_facets'],
        );
        $models = $report['model_dissections']['models'];
        $this->assertNotEmpty($models);
        $runtimes = array_column($models, 'runtime');
        $this->assertContains('bare', $runtimes);
        $this->assertContains('atlas_dev', $runtimes);
        $bare = collect($models)->first(fn (array $m): bool => ($m['runtime'] ?? '') === 'bare' && ($m['present'] ?? false));
        $this->assertNotNull($bare);
        $this->assertArrayHasKey('per_suite', $bare);
        $this->assertCount(10, $bare['per_suite']);
        $this->assertSame(240.0, $bare['summary']['tokens_per_task_mean']);
        $this->assertSame(120.0, $bare['summary']['tokens_per_second_mean']);
        $this->assertContains('tokens_per_task', $bare['measured_facets']);
        $this->assertContains('tokens_per_second', $bare['measured_facets']);
        $this->assertNotEmpty($bare['unknowns']);
        $this->assertStringContainsString('absolute honesty', strtolower($bare['reality_statement']));

        $atlas = collect($models)->first(fn (array $m): bool => ($m['runtime'] ?? '') === 'atlas_dev');
        $this->assertNotNull($atlas);
        $this->assertFalse($atlas['present']);

        $html = file_get_contents(RunPaths::enterpriseHtmlPath());
        $this->assertStringContainsString('Dissecção — tudo que foi medido', $html);
        $this->assertStringContainsString('epistemic_contract', $html);
        $this->assertStringContainsString('Tokens/tarefa', $html);
        $md = file_get_contents(RunPaths::enterpriseMarkdownPath());
        $this->assertStringContainsString('Dissecção por modelo', $md);
        $this->assertStringContainsString('absolute_knowledge_claim = false', $md);
        $this->assertStringContainsString('tok/task=', $md);
        $this->assertStringContainsString('tok/s=', $md);
    }

    public function test_uplift_section_lists_five_families(): void
    {
        $report = (new EnterpriseReportBuilder)->build();
        $families = (array) config('atlas_rivals.uplift_families', []);
        $this->assertCount(count($families), $report['atlas_uplift']['families']);
        $this->assertSame(5, count($report['atlas_uplift']['families']));
        foreach ($report['atlas_uplift']['families'] as $family) {
            $this->assertSame('not_run', $family['status']);
        }
    }

    public function test_trust_contract_exposes_four_axes_and_events_complete(): void
    {
        $runId = $this->seedSuiteRun('bfcl', pipelineValid: true, tokensPresent: true, internalClaim: true, withEvents: true);
        $report = (new EnterpriseReportBuilder)->build();
        $row = collect($report['suite_rows'])->firstWhere('suite_id', 'bfcl');
        $this->assertTrue($row['events_complete']);
        $this->assertTrue($row['is_atlas_fact']);
        $this->assertArrayHasKey('pipeline', $row['axes']);
        $this->assertArrayHasKey('measurement', $row['axes']);
        $this->assertArrayHasKey('intelligence', $row['axes']);
        $this->assertArrayHasKey('claim', $row['axes']);
        $this->assertTrue($row['axes']['pipeline']['ok']);
        $this->assertSame('complete', $row['axes']['measurement']['status']);
        $this->assertSame('allowed', $row['axes']['claim']['status']);
        $this->assertSame(1.0, $row['intelligence_rate']);
        $this->assertSame($runId, $row['run_id']);

        $md = file_get_contents(RunPaths::enterpriseMarkdownPath());
        $this->assertStringContainsString('Eixos de confiança', $md);
        $this->assertStringContainsString('is_atlas_fact', $md);
        $html = file_get_contents(RunPaths::enterpriseHtmlPath());
        // Rótulos humanos (sem jargão) — a tela não pode mostrar termos ambíguos.
        $this->assertStringContainsString('confirmado', $html);
        $this->assertStringContainsString('Sucesso (tarefas válidas)', $html);
        $this->assertStringContainsString('Como ler este relatório', $html);
    }

    public function test_events_incomplete_blocks_atlas_fact(): void
    {
        $this->seedSuiteRun('bfcl', pipelineValid: true, tokensPresent: true, internalClaim: true, withEvents: false);
        $report = (new EnterpriseReportBuilder)->build();
        $row = collect($report['suite_rows'])->firstWhere('suite_id', 'bfcl');
        $this->assertFalse($row['events_complete']);
        $this->assertFalse($row['is_atlas_fact']);
        $this->assertSame('ok', $row['status']); // pipeline chip still ok
    }

    public function test_intelligence_rate_distinct_from_itt_when_seeded(): void
    {
        $this->seedSuiteRun(
            'bfcl',
            pipelineValid: true,
            tokensPresent: true,
            internalClaim: false,
            withEvents: true,
            successRateItt: 0.5,
            intelligenceRate: 1.0,
        );
        $report = (new EnterpriseReportBuilder)->build();
        $row = collect($report['suite_rows'])->firstWhere('suite_id', 'bfcl');
        $this->assertSame(0.5, $row['success_rate_itt']);
        $this->assertSame(1.0, $row['intelligence_rate']);
        $this->assertSame(0.5, $row['axes']['intelligence']['itt']);
        $this->assertSame(1.0, $row['axes']['intelligence']['rate']);

        // Capacidade "Uso de ferramentas" (bfcl) deve carregar faixa de confiança
        // 95% que envolve o ponto e é larga em amostra pequena — nunca deixar o
        // número cru fingir precisão que a amostra não tem.
        $cap = collect($report['model_capabilities']['capabilities'])
            ->firstWhere('id', 'tool_use');
        $this->assertNotNull($cap['bare_ci_low'], 'capacidade sem faixa de confiança');
        $this->assertLessThanOrEqual($cap['bare_intelligence'], $cap['bare_ci_low']);
        $this->assertGreaterThanOrEqual($cap['bare_intelligence'], $cap['bare_ci_high']);
        $this->assertGreaterThan($cap['bare_ci_low'], $cap['bare_ci_high']);
    }

    public function test_inspect_evals_missing_tokens_is_harness_omit_not_fact(): void
    {
        $this->seedSuiteRun('inspect_evals', pipelineValid: true, tokensPresent: false, internalClaim: true, withEvents: true);
        $report = (new EnterpriseReportBuilder)->build();
        $row = collect($report['suite_rows'])->firstWhere('suite_id', 'inspect_evals');
        $this->assertSame('harness_omit', $row['axes']['measurement']['status']);
        $this->assertFalse($row['axes']['measurement']['ok']);
        $this->assertFalse($row['is_atlas_fact']);
        $this->assertSame('missing_data', $row['status']);
    }

    public function test_capability_exposes_named_sub_capabilities_per_instrument(): void
    {
        // Uma capacidade não é uma caixa: é um domínio com habilidades distintas.
        // "Programação" precisa mostrar corrigir-bug, algoritmo, terminal etc. —
        // achatar tudo num % único esconde que o modelo vai bem num e zera noutro.
        $this->seedSuiteRun('bfcl', pipelineValid: true, tokensPresent: true, internalClaim: false, withEvents: true, successRateItt: 0.5, intelligenceRate: 0.5);
        $report = (new EnterpriseReportBuilder)->build();

        $coding = collect($report['model_capabilities']['capabilities'])->firstWhere('id', 'coding');
        $this->assertCount(5, $coding['sub_capabilities'], 'Programação deve expor 5 habilidades');
        $this->assertSame(
            ['senior_swe_bench', 'swe_bench_live', 'live_code_bench', 'aider_polyglot', 'terminal_bench'],
            array_column($coding['sub_capabilities'], 'suite_id')
        );
        foreach ($coding['sub_capabilities'] as $sub) {
            $this->assertNotSame($sub['suite_id'], $sub['label'], 'sub-capacidade precisa de nome humano');
            $this->assertNotEmpty($sub['measures']);
        }

        // Suíte medida vira sub-capacidade com score + faixa Wilson própria.
        $tool = collect($report['model_capabilities']['capabilities'])->firstWhere('id', 'tool_use');
        $bfcl = collect($tool['sub_capabilities'])->firstWhere('suite_id', 'bfcl');
        $this->assertTrue($bfcl['reliable']);
        $this->assertSame(0.5, $bfcl['bare_intelligence']);
        $this->assertNotNull($bfcl['bare_ci_low']);
        $this->assertLessThanOrEqual($bfcl['bare_intelligence'], $bfcl['bare_ci_low']);
        $this->assertGreaterThanOrEqual($bfcl['bare_intelligence'], $bfcl['bare_ci_high']);

        // Suíte não medida diz o porquê, nunca 0%.
        $notRun = collect($tool['sub_capabilities'])->firstWhere('suite_id', 'tau2_bench');
        $this->assertFalse($notRun['reliable']);
        $this->assertNull($notRun['bare_intelligence']);
        $this->assertNotEmpty($notRun['unreliable_reason']);
    }

    public function test_capability_slices_multi_domain_suite_by_task_type(): void
    {
        // inspect_evals abrange domínios distintos (gsm8k matemática, mmlu
        // conhecimento, gpqa ciência). Sem fatiar por task_type, conhecimento
        // seria contado como Raciocínio. A capacidade Raciocínio só pode somar
        // unidades math_reasoning — e uma habilidade sem unidade daquele domínio
        // aparece como NÃO MEDIDA, nunca some do card.
        $builder = new EnterpriseReportBuilder;
        $method = new \ReflectionMethod($builder, 'sliceByTaskTypes');
        $method->setAccessible(true);

        $ev = ['blame_by_task_type' => [
            'math_reasoning' => ['successes' => 8, 'model_failures' => 2, 'environment_or_flow_failures' => 0],
            'knowledge_qa' => ['successes' => 0, 'model_failures' => 0, 'environment_or_flow_failures' => 9],
        ]];

        $math = $method->invoke($builder, $ev, ['math_reasoning']);
        $this->assertSame(0.8, $math['intelligence_rate'], 'não pode misturar outro domínio');
        $this->assertSame(10, $math['tasks_decidable']);
        $this->assertTrue($math['reliable']);

        // Domínio irmão quebrado por ambiente não contamina nem é julgado.
        $knowledge = $method->invoke($builder, $ev, ['knowledge_qa']);
        $this->assertFalse($knowledge['reliable']);
        $this->assertNull($knowledge['intelligence_rate']);
        $this->assertStringContainsString('ambiente', $knowledge['unreliable_reason_human']);

        // Domínio sem nenhuma unidade registrada = null (vira "não medido").
        $this->assertNull($method->invoke($builder, $ev, ['multimodal_vision']));

        // A capacidade Raciocínio declara a fatia.
        $this->assertSame(['math_reasoning'], EnterpriseReportBuilder::CAPABILITIES['reasoning']['task_types']);
    }

    public function test_coverage_declares_scope_ceiling_and_uncovered_domains(): void
    {
        // O relatório precisa declarar o próprio teto. Chamar 4 domínios de
        // código/agente de "capacidades" sugere retrato da IA inteira — os
        // domínios sem instrumento (conhecimento, contexto longo, multimodal,
        // segurança…) têm de aparecer como NÃO cobertos, não sumir.
        $report = (new EnterpriseReportBuilder)->build();
        $cov = $report['model_capabilities']['coverage'];

        $this->assertLessThan($cov['domains_total'], $cov['domains_covered'], 'cobertura não pode se declarar total');
        $this->assertNotEmpty($cov['scope_note']);

        $uncovered = array_column(array_filter($cov['map'], fn (array $d): bool => ! $d['covered']), 'domain');
        $this->assertNotEmpty($uncovered, 'domínios fora do alcance precisam ser declarados');
        // O denominador não pode encolher: declarar cobertura contra uma lista curta
        // dá nota melhor que a real. Estes domínios existem no inventário (138
        // instrumentos) e têm de aparecer, mesmo que com zero medição.
        foreach (['Cibersegurança', 'Dissimulação', 'Moral', 'Multimodal'] as $needle) {
            $this->assertNotEmpty(
                array_filter($uncovered, fn (string $d): bool => str_contains($d, $needle)),
                "domínio real ausente do mapa de cobertura: {$needle}"
            );
        }
        // Lacuna dimensionada: "não coberto" sem contar os instrumentos parados
        // faz parecer falta de ferramenta, quando eles já estão instalados.
        $this->assertGreaterThan(100, $cov['instruments_dormant']);
        foreach (['Multimodal', 'Factualidade', 'Segurança'] as $needle) {
            $this->assertNotEmpty(
                array_filter($uncovered, fn (string $d): bool => str_contains($d, $needle)),
                "domínio não coberto ausente do mapa: {$needle}"
            );
        }
        // Habilidades medidas nunca podem exceder as que têm instrumento.
        $this->assertLessThanOrEqual($cov['skills_wired'], $cov['skills_measured']);
    }

    public function test_headline_is_split_when_count_favors_atlas_but_magnitude_does_not(): void
    {
        // A armadilha: 2↑/1↓ parece vitória do Atlas, mas a única regressão
        // (-66.7pp) supera as duas melhoras. Liderar com "melhorou mais vezes"
        // mentiria por spin — contagem e magnitude precisam concordar.
        $builder = new EnterpriseReportBuilder;
        $method = new \ReflectionMethod($builder, 'buildMeasuredFacts');
        $method->setAccessible(true);
        $fam = fn (string $id, float $bare, float $atlas): array => [
            'family' => $id, 'suite_id' => $id, 'status' => 'real_uplift',
            'bare_intelligence' => $bare, 'atlas_intelligence' => $atlas,
            'delta_intelligence' => round($atlas - $bare, 4),
        ];
        $diag = fn (string $id, float $bare, float $atlas): array
            => $fam($id, $bare, $atlas) + ['diagnostic_only' => true];
        $facts = $method->invoke($builder, 'kimi', [], ['families' => [
            $fam('long_horizon', 0.444, 0.556),  // +11.1pp confirmado
            $fam('polyglot', 0.857, 1.0),         // +14.3pp confirmado
            $fam('tool_function', 1.0, 0.333),    // -66.7pp confirmado — domina
            $diag('patch_swe', 0.0, 0.0),         // 0→0 diagnóstico: NÃO conta
        ]], []);

        $this->assertStringContainsString('dividido', $facts['headline']);
        $this->assertStringContainsString('saldo médio', $facts['headline']);
        $this->assertStringNotContainsString('melhorou mais vezes', $facts['headline']);
        // Saldo médio negativo visível no headline (sinal é o árbitro).
        $this->assertStringContainsString('-13.7 pp', $facts['headline']);
        // Par diagnóstico fica fora do veredito: 3 confirmados, 1 diagnóstico.
        $this->assertSame(3, $facts['pairs_valid']);
        $this->assertSame(1, $facts['pairs_diagnostic']);
        $this->assertCount(3, $facts['measured']);
        $this->assertCount(1, $facts['diagnostic']);
    }

    public function test_uplift_excluded_pairs_mark_diagnostic_only(): void
    {
        $arm = (new ArmRegistry)->parse('verboo_kimi_k2_7@bare', 'bfcl');
        $plan = RunPlan::make(
            'bfcl',
            ['case_a', 'case_b', 'case_c'],
            [$arm],
            3,
            ['max_usd' => 10.0, 'max_minutes' => 30],
            1,
        );
        $runId = $plan->persist();
        $this->seedEvents($runId);
        file_put_contents(RunPaths::reportPath($runId), json_encode([
            'schema_version' => SchemaContract::REPORT,
            'run_id' => $runId,
            'rows' => [[
                'task_type' => 'tool_use_function_calling',
                'arm_id' => $arm['arm_id'],
                'success_rate_itt' => 1.0,
                'intelligence_rate' => 1.0,
                'median_wall_ms' => 1000.0,
                'avg_tokens_in' => 100.0,
                'avg_tokens_out' => 20.0,
                'cost_per_task' => 0.0,
                'environment_failure_rate' => 0.0,
                'tokens_coverage' => ['in' => 3, 'out' => 3, 'n' => 3, 'in_rate' => 1.0, 'out_rate' => 1.0],
                'stability' => 1.0,
            ]],
            'pipeline_valid' => true,
            'claim_tier' => 'production',
            'internal_claim_allowed' => false,
            'public_claim_allowed' => false,
            'not_ready_reasons' => [],
            'claim_allowed' => false,
            'claim_blockers' => [],
            'claim_scope' => ['suite' => 'bfcl', 'models' => ['verboo_kimi_k2_7'], 'runtimes' => ['bare']],
            'statistical_analysis' => ['adequate' => true, 'blockers' => [], 'segments' => []],
            'missing_data_policy' => [],
            'report_hash' => 'fixture',
            'built_at' => '2026-07-10T00:00:00Z',
        ], JSON_UNESCAPED_SLASHES));
        file_put_contents(RunPaths::adjudicationPath($runId), json_encode([
            'pipeline_valid' => true,
            'claim_tier' => 'production',
            'internal_claim_allowed' => false,
            'public_claim_allowed' => false,
            'internal_claim_blockers' => ['diagnostic_only'],
            'not_ready_reasons' => ['diagnostic_only'],
            'claim_scope' => ['suite' => 'bfcl', 'models' => ['verboo_kimi_k2_7'], 'runtimes' => ['bare']],
            'statistical_analysis' => ['adequate' => true, 'blockers' => [], 'segments' => []],
            'adjudicated_at' => '2026-07-10T00:00:00Z',
        ], JSON_UNESCAPED_SLASHES));
        file_put_contents(RunPaths::runDir($runId).'/uplift.json', json_encode([
            'schema_version' => 'atlas.rivals2.uplift.v1',
            'uplift_kind' => 'real_uplift',
            'internal_claim_allowed' => false,
            'proven_pair_count' => 2,
            'excluded_pair_keys' => ['case_c@1'],
            'deltas' => [[
                'base' => ['success_rate' => 0.5],
                'atlas' => ['success_rate' => 0.8],
                'delta_success_rate' => 0.3,
            ]],
        ], JSON_UNESCAPED_SLASHES));

        $report = (new EnterpriseReportBuilder)->build();
        $family = collect($report['atlas_uplift']['families'])->firstWhere('suite_id', 'bfcl');
        $this->assertNotNull($family);
        $this->assertSame('real_uplift', $family['status']);
        $this->assertTrue($family['diagnostic_only']);
        $this->assertSame(2, $family['proven_pair_count']);
        $this->assertSame(['case_c@1'], $family['excluded_pair_keys']);
    }

    public function test_atlas_only_run_is_not_the_bare_source(): void
    {
        // Contrato de confiança: um run só de atlas_dev (bateria Atlas viva)
        // não pode ser fonte do score "sem Atlas" — senão bare sairia de dados
        // com Atlas.
        $builder = new EnterpriseReportBuilder;
        $m = new \ReflectionMethod($builder, 'runHasBareArm');
        $m->setAccessible(true);
        $atlasOnly = ['report' => ['rows' => [['arm_id' => 'verboo_kimi_k2_7@atlas_dev']]]];
        $withBare = ['report' => ['rows' => [['arm_id' => 'verboo_kimi_k2_7@bare']]]];
        $this->assertFalse($m->invoke($builder, $atlasOnly));
        $this->assertTrue($m->invoke($builder, $withBare));
    }

    public function test_single_model_battery_when_only_one_bare_model(): void
    {
        $this->seedSuiteRun('bfcl', pipelineValid: true, tokensPresent: true);
        $report = (new EnterpriseReportBuilder)->build();
        $this->assertSame('single_model_battery', $report['model_matrix']['mode']);
        $this->assertNotEmpty($report['model_matrix']['model_id']);
        $this->assertSame('model_with_without_atlas', $report['model_matrix']['face'] ?? null);
        $this->assertNotEmpty($report['model_matrix']['rows']);
        $this->assertArrayHasKey('bare_intelligence', $report['model_matrix']['rows'][0]);
        $this->assertArrayHasKey('per_suite', $report['model_matrix']['rows'][0]);
        $this->assertArrayHasKey('facts', $report);
        $this->assertArrayHasKey('measured', $report['facts']);
        $this->assertArrayHasKey('incomplete', $report['facts']);
        $html = file_get_contents(RunPaths::enterpriseHtmlPath());
        $this->assertStringContainsString('Fatos medidos', $html);
        $this->assertStringContainsString('Suite a suite', $html);
    }

    public function test_model_vs_model_when_two_bare_models_present(): void
    {
        $armA = (new ArmRegistry)->parse('verboo_kimi_k2_7@bare', 'bfcl');
        $armB = (new ArmRegistry)->parse('verboo_qwen_3_6_27b@bare', 'bfcl');
        $plan = RunPlan::make(
            'bfcl',
            ['case_a', 'case_b', 'case_c'],
            [$armA, $armB],
            1,
            ['max_usd' => 10.0, 'max_minutes' => 30],
            1,
        );
        $runId = $plan->persist();
        foreach ([$armA, $armB] as $index => $arm) {
            RunReceipt::fromArray([
                'schema_version' => SchemaContract::RUN_RECEIPT,
                'run_id' => $runId,
                'case_id' => 'case_a',
                'task_type' => 'tool_use_function_calling',
                'arm_id' => $arm['arm_id'],
                'repetition' => 1,
                'status' => 'success',
                'failure_class' => null,
                'wall_ms' => 1000,
                'tokens_in' => 100,
                'tokens_out' => 20,
                'cost_usd' => 0.0,
                'field_presence' => [
                    'wall_ms' => ['present' => true, 'reason' => null],
                    'tokens_in' => ['present' => true, 'reason' => null],
                    'tokens_out' => ['present' => true, 'reason' => null],
                    'cost_usd' => ['present' => true, 'reason' => 'verboo_subscription_marginal'],
                ],
                'claim_tier' => 'production',
                'harness_only' => false,
                'artifacts' => [],
                'started_at' => null,
                'finished_at' => null,
            ])->append();
        }
        file_put_contents(RunPaths::reportPath($runId), json_encode([
            'schema_version' => SchemaContract::REPORT,
            'run_id' => $runId,
            'rows' => [
                [
                    'task_type' => 'tool_use_function_calling',
                    'arm_id' => $armA['arm_id'],
                    'success_rate_itt' => 1.0,
                    'median_wall_ms' => 1000.0,
                    'avg_tokens_in' => 100.0,
                    'avg_tokens_out' => 20.0,
                    'cost_per_task' => 0.0,
                    'environment_failure_rate' => 0.0,
                    'tokens_coverage' => ['in' => 1, 'out' => 1, 'n' => 1, 'in_rate' => 1.0, 'out_rate' => 1.0],
                    'stability' => 1.0,
                ],
                [
                    'task_type' => 'tool_use_function_calling',
                    'arm_id' => $armB['arm_id'],
                    'success_rate_itt' => 0.5,
                    'median_wall_ms' => 2000.0,
                    'avg_tokens_in' => 80.0,
                    'avg_tokens_out' => 10.0,
                    'cost_per_task' => 0.0,
                    'environment_failure_rate' => 0.0,
                    'tokens_coverage' => ['in' => 1, 'out' => 1, 'n' => 1, 'in_rate' => 1.0, 'out_rate' => 1.0],
                    'stability' => 1.0,
                ],
            ],
            'pipeline_valid' => true,
            'claim_tier' => 'production',
            'internal_claim_allowed' => false,
            'public_claim_allowed' => false,
            'not_ready_reasons' => [],
            'claim_allowed' => false,
            'claim_blockers' => [],
            'claim_scope' => [
                'suite' => 'bfcl',
                'models' => ['verboo_kimi_k2_7', 'verboo_qwen_3_6_27b'],
                'runtimes' => ['bare'],
            ],
            'statistical_analysis' => ['adequate' => false, 'blockers' => [], 'segments' => []],
            'missing_data_policy' => [],
            'report_hash' => 'fixture',
            'built_at' => '2026-07-10T00:00:00Z',
        ], JSON_UNESCAPED_SLASHES));
        file_put_contents(RunPaths::adjudicationPath($runId), json_encode([
            'pipeline_valid' => true,
            'claim_tier' => 'production',
            'internal_claim_allowed' => false,
            'public_claim_allowed' => false,
            'internal_claim_blockers' => ['fixture'],
            'not_ready_reasons' => ['fixture'],
            'claim_scope' => [
                'suite' => 'bfcl',
                'models' => ['verboo_kimi_k2_7', 'verboo_qwen_3_6_27b'],
                'runtimes' => ['bare'],
            ],
            'statistical_analysis' => ['adequate' => false, 'blockers' => [], 'segments' => []],
            'adjudicated_at' => '2026-07-10T00:00:00Z',
        ], JSON_UNESCAPED_SLASHES));

        $report = (new EnterpriseReportBuilder)->build();
        $this->assertSame('model_vs_model', $report['model_matrix']['mode']);
        $this->assertNotEmpty($report['model_matrix']['rows']);
        $this->assertArrayHasKey('per_model', $report['model_matrix']['rows'][0]);
        $this->assertArrayHasKey('verboo_kimi_k2_7', $report['model_matrix']['rows'][0]['per_model']);
        $this->assertArrayHasKey('verboo_qwen_3_6_27b', $report['model_matrix']['rows'][0]['per_model']);
    }

    /** @return array<string, mixed> */
    private function minimalEnterprisePayload(int $suiteCount): array
    {
        $ids = array_slice((new SuiteRegistry)->externalSuiteIds(), 0, $suiteCount);
        while (count($ids) < $suiteCount) {
            $ids[] = 'suite_'.count($ids);
        }
        $rows = array_map(fn (string $id): array => [
            'suite_id' => $id,
            'status' => 'not_run',
            'run_id' => null,
            'success_rate_itt' => null,
            'median_wall_ms' => null,
            'tokens_in_avg' => null,
            'tokens_out_avg' => null,
            'tokens_per_task' => null,
            'tokens_per_second' => null,
            'total_tokens' => null,
            'cost_per_1k_tokens' => null,
            'cost_per_task' => null,
            'cost_basis' => null,
            'env_failure_rate' => null,
            'missing_fields' => [],
            'pipeline_valid' => false,
            'internal_claim_allowed' => false,
            'delivery' => EnterpriseSuiteDeliveryCatalog::forSuite($id),
            'full_metrics' => null,
            'report_rows' => [],
            'native_signals' => [],
            'case_ids' => [],
            'artifacts' => [],
            'adjudication' => null,
            'observed_native_metric_keys' => [],
            'observed_report_metric_keys' => [],
            'delivery_coverage' => [
                'native_expected' => 0,
                'native_observed' => 0,
                'native_missing' => [],
                'report_expected' => 0,
                'report_observed' => 0,
                'report_missing' => [],
                'dimensions_expected' => [],
                'uplift_eligible' => false,
                'uplift_family' => null,
            ],
        ], $ids);

        return [
            'schema_version' => SchemaContract::ENTERPRISE_REPORT,
            'built_at' => '2026-07-10T00:00:00Z',
            'report_hash' => 'pending',
            'claim_allowed' => false,
            'claim_blockers' => ['aggregate_view_claims_live_per_run'],
            'executive_summary' => [
                'primary_model' => 'verboo_kimi_k2_7',
                'suites_ok' => 0,
                'suites_not_run' => $suiteCount,
                'suites_failed' => 0,
                'suites_missing_data' => 0,
                'suites_blocked' => 0,
                'provider_binding' => 'hermes+verboo',
            ],
            'delivery_inventory' => array_values(array_map(
                fn (string $id): array => EnterpriseSuiteDeliveryCatalog::forSuite($id),
                $ids,
            )),
            'suite_rows' => $rows,
            'model_dissections' => [
                'epistemic_contract' => [
                    'goal' => 'test',
                    'forbidden' => [],
                    'required_facets' => EnterpriseModelDissectionBuilder::requiredFacets(),
                    'completeness_definition' => 'test',
                ],
                'models' => [],
                'global_unknowns' => [],
                'completeness' => [
                    'dissections_total' => 0,
                    'dissections_present' => 0,
                    'dissections_complete' => 0,
                    'mean_completeness_ratio' => 0.0,
                    'absolute_knowledge_claim' => false,
                    'absolute_measured_reality_claim' => false,
                ],
            ],
            'model_matrix' => ['mode' => 'single_model_battery', 'model_id' => 'verboo_kimi_k2_7', 'rows' => []],
            'atlas_uplift' => ['families' => []],
            'gaps' => [],
            'included_run_ids' => [],
            'excluded_run_ids' => [],
        ];
    }

    private function seedSuiteRun(
        string $suiteId,
        bool $pipelineValid,
        bool $tokensPresent,
        bool $internalClaim = false,
        bool $withEvents = true,
        ?float $successRateItt = null,
        ?float $intelligenceRate = null,
    ): string {
        $arm = (new ArmRegistry)->parse('verboo_kimi_k2_7@bare', $suiteId);
        $plan = RunPlan::make(
            $suiteId,
            ['case_a', 'case_b', 'case_c'],
            [$arm],
            3,
            ['max_usd' => 10.0, 'max_minutes' => 30],
            1,
        );
        $runId = $plan->persist();
        for ($i = 0; $i < 3; $i++) {
            RunReceipt::fromArray([
                'schema_version' => SchemaContract::RUN_RECEIPT,
                'run_id' => $runId,
                'case_id' => ['case_a', 'case_b', 'case_c'][$i],
                'task_type' => 'tool_use_function_calling',
                'arm_id' => $arm['arm_id'],
                'repetition' => 1,
                'status' => 'success',
                'failure_class' => null,
                'wall_ms' => 1000 * ($i + 1),
                'tokens_in' => $tokensPresent ? 100 * ($i + 1) : 0,
                'tokens_out' => $tokensPresent ? 20 * ($i + 1) : 0,
                'cost_usd' => 0.0,
                'field_presence' => [
                    'wall_ms' => ['present' => true, 'reason' => null],
                    'tokens_in' => [
                        'present' => $tokensPresent,
                        'reason' => $tokensPresent ? null : 'lcb_omits_usage',
                    ],
                    'tokens_out' => [
                        'present' => $tokensPresent,
                        'reason' => $tokensPresent ? null : 'lcb_omits_usage',
                    ],
                    'cost_usd' => [
                        'present' => $tokensPresent,
                        'reason' => $tokensPresent ? 'verboo_subscription_marginal' : 'usage_missing',
                    ],
                ],
                'claim_tier' => 'production',
                'harness_only' => false,
                'artifacts' => [],
                'started_at' => null,
                'finished_at' => null,
            ])->append();
        }

        $itt = $successRateItt ?? 1.0;
        $intel = $intelligenceRate ?? $itt;
        $reportRows = [[
            'task_type' => 'tool_use_function_calling',
            'arm_id' => $arm['arm_id'],
            'n' => 3,
            'planned_attempts' => 3,
            'observed_attempts' => 3,
            'valid_results' => 3,
            'successes' => 3,
            'success_rate' => $itt,
            'success_rate_itt' => $itt,
            'intelligence_rate' => $intel,
            'success_rate_valid_results' => $itt,
            'success_rate_wilson_95' => ['low' => 0.29, 'high' => 1.0, 'width' => 0.71],
            'median_wall_ms' => 2000.0,
            'avg_wall_ms' => 2000.0,
            'p95_wall_ms' => 3000.0,
            'avg_tokens_in' => $tokensPresent ? 200.0 : null,
            'avg_tokens_out' => $tokensPresent ? 40.0 : null,
            'total_tokens_in' => $tokensPresent ? 600.0 : null,
            'total_tokens_out' => $tokensPresent ? 120.0 : null,
            'total_tokens' => $tokensPresent ? 720.0 : null,
            'tokens_per_task' => $tokensPresent ? 240.0 : null,
            'avg_tokens_per_task' => $tokensPresent ? 240.0 : null,
            'tokens_in_per_task' => $tokensPresent ? 200.0 : null,
            'tokens_out_per_task' => $tokensPresent ? 40.0 : null,
            'tokens_per_second' => $tokensPresent ? 120.0 : null,
            'tokens_per_second_aggregate' => $tokensPresent ? 120.0 : null,
            'tokens_in_per_second' => $tokensPresent ? 100.0 : null,
            'tokens_out_per_second' => $tokensPresent ? 20.0 : null,
            'median_wall_sec' => 2.0,
            'cost_per_1k_tokens' => null,
            'cost_per_task' => 0.0,
            'total_cost_usd' => 0.0,
            'avg_cost_usd' => 0.0,
            'median_cost_usd' => 0.0,
            'p95_cost_usd' => 0.0,
            'environment_failure_rate' => 0.0,
            'failure_classes' => [],
            'dimensions' => null,
            'avg_patch_bloat' => null,
            'reality' => null,
            'tokens_coverage' => [
                'in' => $tokensPresent ? 3 : 0,
                'out' => $tokensPresent ? 3 : 0,
                'n' => 3,
                'in_rate' => $tokensPresent ? 1.0 : 0.0,
                'out_rate' => $tokensPresent ? 1.0 : 0.0,
            ],
            'stability' => 1.0,
        ]];
        file_put_contents(RunPaths::reportPath($runId), json_encode([
            'schema_version' => SchemaContract::REPORT,
            'run_id' => $runId,
            'rows' => $reportRows,
            'pipeline_valid' => $pipelineValid,
            'claim_tier' => 'production',
            'internal_claim_allowed' => $internalClaim,
            'public_claim_allowed' => false,
            'not_ready_reasons' => [],
            'claim_allowed' => $internalClaim,
            'claim_blockers' => [],
            'claim_scope' => ['suite' => $suiteId, 'models' => ['verboo_kimi_k2_7'], 'runtimes' => ['bare']],
            'statistical_analysis' => ['adequate' => true, 'blockers' => [], 'segments' => []],
            'missing_data_policy' => [],
            'report_hash' => 'fixture',
            'built_at' => '2026-07-10T00:00:00Z',
        ], JSON_UNESCAPED_SLASHES));

        file_put_contents(RunPaths::adjudicationPath($runId), json_encode([
            'pipeline_valid' => $pipelineValid,
            'claim_tier' => 'production',
            'internal_claim_allowed' => $internalClaim,
            'public_claim_allowed' => false,
            'internal_claim_blockers' => $internalClaim ? [] : ['fixture'],
            'not_ready_reasons' => $internalClaim ? [] : ['fixture'],
            'claim_scope' => [
                'suite' => $suiteId,
                'models' => ['verboo_kimi_k2_7'],
                'runtimes' => ['bare'],
                'task_types' => ['tool_use_function_calling'],
            ],
            'statistical_analysis' => ['adequate' => true, 'blockers' => [], 'segments' => []],
            'adjudicated_at' => '2026-07-10T00:00:00Z',
        ], JSON_UNESCAPED_SLASHES));

        if ($withEvents) {
            $this->seedEvents($runId);
        }

        return $runId;
    }

    private function seedEvents(string $runId): void
    {
        $path = RunPaths::eventsPath($runId);
        RunPaths::ensureDir(dirname($path));
        $lines = [
            ['event_type' => 'battery_started', 'data' => ['suite' => 'fixture']],
            ['event_type' => 'unit_started', 'data' => ['execution_id' => 'u1']],
            ['event_type' => 'unit_heartbeat', 'data' => ['execution_id' => 'u1']],
            ['event_type' => 'unit_finished', 'data' => ['execution_id' => 'u1', 'status' => 'ok']],
            ['event_type' => 'pipeline_finished', 'data' => []],
            ['event_type' => 'battery_finished', 'data' => []],
        ];
        $buf = '';
        foreach ($lines as $event) {
            $buf .= json_encode([
                'timestamp' => '2026-07-12T00:00:00Z',
                'event_type' => $event['event_type'],
                'data' => $event['data'],
            ], JSON_UNESCAPED_SLASHES).PHP_EOL;
        }
        file_put_contents($path, $buf);
    }
}
