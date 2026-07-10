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
