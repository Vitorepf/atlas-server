<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\SuiteRegistry;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExternalCommandContractTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_command_contract_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
        foreach (File::directories(base_path('tests/Fixtures/Rivals/cases')) as $suiteDir) {
            File::copyDirectory(
                $suiteDir,
                RunPaths::root().'/external/'.basename($suiteDir).'/cases',
            );
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_all_ten_adapters_emit_unique_argv_for_independent_repetitions(): void
    {
        $registry = new SuiteRegistry;
        $expectedFirstBinary = [
            'tau2_bench' => 'tau2',
            'bfcl' => 'python',
            'terminal_bench' => 'tb',
            'senior_swe_bench' => 'harbor',
            'swe_bench_live' => 'php',
            'live_code_bench' => 'python',
            'inspect_evals' => 'inspect',
            'hal_harness' => 'python',
            'aider_polyglot' => 'benchmark/benchmark.py',
            'swe_marathon' => 'harbor',
        ];

        foreach ($registry->externalSuiteIds() as $suiteId) {
            $adapter = $registry->adapterFor($suiteId, allowLegacyAlias: false);
            $cases = $adapter->listCases();
            $this->assertNotEmpty($cases, "{$suiteId}: case pack missing");
            $arm = (new ArmRegistry)->parse('claude_sonnet_5@bare', $suiteId);
            $plan = RunPlan::make(
                $suiteId,
                [(string) $cases[0]['case_id']],
                [$arm],
                3,
                ['max_usd' => 3.0, 'max_minutes' => 30],
                42,
            );
            $commands = $adapter->planCommands($plan);
            $this->assertCount(3, $commands, "{$suiteId}: repetitions are not independent");
            $this->assertCount(3, array_unique(array_column($commands, 'output_path')));
            foreach ($commands as $command) {
                $this->assertSame($expectedFirstBinary[$suiteId], $command['argv'][0]);
                $this->assertNotEmpty($command['normalization']['scratch_dir']);
                $this->assertStringNotContainsString('{', implode(' ', $command['argv']));
            }
        }
    }

    public function test_native_cli_regressions_are_absent_from_templates(): void
    {
        $commands = [];
        $registry = new SuiteRegistry;
        foreach ($registry->externalSuiteIds() as $suiteId) {
            $adapter = $registry->adapterFor($suiteId, allowLegacyAlias: false);
            $case = $adapter->listCases()[0];
            $plan = RunPlan::make(
                $suiteId,
                [$case['case_id']],
                [(new ArmRegistry)->parse('claude_sonnet_5@bare', $suiteId)],
                1,
                ['max_usd' => 1.0, 'max_minutes' => 30],
                1,
            );
            $commands[$suiteId] = $adapter->planCommands($plan)[0]['argv'];
        }

        $this->assertContains('--save-to', $commands['tau2_bench']);
        $this->assertSame('1', $commands['tau2_bench'][array_search('--num-trials', $commands['tau2_bench'], true) + 1]);
        $this->assertNotContains('--output', $commands['tau2_bench']);
        $this->assertNotContains('--num-runs', $commands['bfcl']);
        $this->assertContains('--result-dir', $commands['bfcl']);
        $this->assertContains('--output-path', $commands['terminal_bench']);
        $this->assertContains('--include-task-name', $commands['senior_swe_bench']);
        $this->assertNotContains('--output', $commands['senior_swe_bench']);
        $this->assertStringContainsString('rivals-swe-live-unit.php', $commands['swe_bench_live'][1]);
        $this->assertNotFalse(array_search(
            true,
            array_map(fn (string $arg): bool => str_starts_with($arg, '--case-file='), $commands['swe_bench_live']),
            true,
        ));
        $this->assertNotContains('--question_ids', $commands['live_code_bench']);
        $this->assertContains('--evaluate', $commands['live_code_bench']);
        $this->assertContains('--log-dir', $commands['inspect_evals']);
        $this->assertSame('1', $commands['inspect_evals'][array_search('--epochs', $commands['inspect_evals'], true) + 1]);
        $this->assertContains('--agent_function', $commands['hal_harness']);
        $this->assertContains('--results_dir', $commands['hal_harness']);
        $this->assertNotContains('--output', $commands['aider_polyglot']);
        $this->assertContains('--keywords', $commands['aider_polyglot']);
        $this->assertContains('docker', $commands['swe_marathon']);
        $this->assertContains('--jobs-dir', $commands['swe_marathon']);
    }

    public function test_five_uplift_families_use_distinct_bare_and_atlas_solver_paths(): void
    {
        $registry = new SuiteRegistry;
        foreach ((array) config('atlas_rivals.uplift_families') as $family => $suiteId) {
            $adapter = $registry->adapterFor($suiteId, allowLegacyAlias: false);
            $case = $adapter->listCases()[0];
            $plan = RunPlan::make(
                $suiteId,
                [$case['case_id']],
                [
                    (new ArmRegistry)->parse('verboo_kimi_k2_7@bare', $suiteId),
                    (new ArmRegistry)->parse('verboo_kimi_k2_7@atlas_dev', $suiteId),
                ],
                1,
                ['max_usd' => 0.0, 'max_minutes' => 120],
                42,
            );
            $commands = $adapter->planCommands($plan);

            $this->assertCount(2, $commands, $family);
            $byArm = collect($commands)->keyBy('arm_id');
            $bare = $byArm['verboo_kimi_k2_7@bare']['argv'];
            $atlas = $byArm['verboo_kimi_k2_7@atlas_dev']['argv'];
            $this->assertNotSame($bare, $atlas, "{$family}: atlas_dev relabels bare argv");
            $this->assertStringContainsString(
                'atlas',
                strtolower(implode(' ', $atlas)),
                "{$family}: Atlas solver absent",
            );
        }
    }
}
