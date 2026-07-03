<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\External\AbstractExternalSuiteAdapter;
use App\Services\Ai\Rivals\Adapters\External\AiderBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\HalHarnessAdapter;
use App\Services\Ai\Rivals\Adapters\External\HarborTerminalBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\InspectEvalsAdapter;
use App\Services\Ai\Rivals\Adapters\External\LiveCodeBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\SeniorSweBenchAdapter;
use App\Services\Ai\Rivals\Adapters\External\SweBenchLiveAdapter;
use App\Services\Ai\Rivals\Adapters\External\Tau2BfclAdapter;
use App\Services\Ai\Rivals\Support\RunPaths;
use RuntimeException;
use Tests\TestCase;

/**
 * Slice 7: prova de ingest reproduzível dos 8 adapters de suite externa.
 * Fixture = resultado NATIVO plausível da suite; o adapter mapeia para
 * RunReceipt (schema atlas.rivals2.run_receipt.v1) sem inventar nada.
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

    /** Copia a fixture nativa para um runDir temporário e retorna o runDir. */
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

    /** Importa um case mínimo (fonte do task_type p/ suites multi-tipo). */
    private function importCase(string $suiteId, string $caseId, string $taskType): void
    {
        $dir = $this->storage."/external/{$suiteId}/cases";
        RunPaths::ensureDir($dir);
        file_put_contents($dir."/{$caseId}.json", json_encode([
            'case_id' => $caseId, 'task_type' => $taskType, 'title' => $caseId,
        ]));
    }

    /** @return array<int, \App\Services\Ai\Rivals\Core\RunReceipt> */
    private function ingestAndAssertCommon(AbstractExternalSuiteAdapter $adapter): array
    {
        $receipts = $adapter->ingestResults($this->stageRunDir($adapter->suiteId()));

        $this->assertNotEmpty($receipts, $adapter->suiteId().': ingest vazio');
        foreach ($receipts as $receipt) {
            // RunReceipt::fromArray já validou o schema (senão teria lançado)
            $this->assertContains(
                $receipt->data['task_type'],
                config('atlas_rivals.task_types'),
                $adapter->suiteId().': task_type inválido '.$receipt->data['task_type']
            );
            $this->assertNotEmpty($receipt->data['artifacts'][0]['sha256']);
        }

        return $receipts;
    }

    public function test_all_external_adapters_ingest_native_fixtures_into_valid_receipts(): void
    {
        $this->importCase('inspect_evals', 'gaia_l1_004', 'tool_use_function_calling');
        $this->importCase('inspect_evals', 'gaia_l1_011', 'tool_use_function_calling');
        $this->importCase('hal_harness', 'hal_task_001', 'long_horizon_engineering');
        $this->importCase('hal_harness', 'hal_task_002', 'long_horizon_engineering');

        foreach ([
            new SeniorSweBenchAdapter,
            new HarborTerminalBenchAdapter,
            new AiderBenchAdapter,
            new InspectEvalsAdapter,
            new SweBenchLiveAdapter,
            new HalHarnessAdapter,
            new Tau2BfclAdapter,
            new LiveCodeBenchAdapter,
        ] as $adapter) {
            $this->ingestAndAssertCommon($adapter);
        }
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
        // honestidade: sem import não há cases — nunca inventar
        $this->assertSame([], (new Tau2BfclAdapter)->listCases());
    }
}
