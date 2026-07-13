<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\NativeResultNormalizer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class NativeResultNormalizerTest extends TestCase
{
    private string $root;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/rivals_normalizer_root_'.uniqid();
        $this->scratch = sys_get_temp_dir().'/rivals_normalizer_scratch_'.uniqid();
        File::ensureDirectoryExists($this->root);
        File::ensureDirectoryExists($this->scratch);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory($this->scratch);
        parent::tearDown();
    }

    public function test_tau2_and_bfcl_native_outputs_normalize_to_one_unit(): void
    {
        $tau = $this->entry('airline_task_012', [
            'native_task_id' => '12',
            'domain' => 'airline',
        ]);
        $tauDir = $this->root.'/data/simulations/'.$tau['normalization']['run_name'];
        File::ensureDirectoryExists($tauDir);
        $this->writeJson($tauDir.'/results.json', [
            'info' => ['agent_info' => ['llm' => 'claude-sonnet-5']],
            'simulations' => [[
                'id' => 'sim-1',
                'task_id' => '12',
                'reward_info' => ['reward' => 1.0],
                'duration' => 1.25,
                'agent_cost' => 0.03,
                'start_time' => '2026-07-09T00:00:00Z',
                'end_time' => '2026-07-09T00:00:01Z',
                'messages' => [[
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
                ]],
            ]],
        ]);
        $tauUnit = (new NativeResultNormalizer)->normalize('tau2_bench', $tau, $this->root);
        $this->assertSame('airline_task_012', $tauUnit['simulations'][0]['task_id']);
        $this->assertSame(2, $tauUnit['simulations'][0]['trial']);
        $this->assertSame(10, $tauUnit['simulations'][0]['usage']['input_tokens']);

        $bfcl = $this->entry('simple', ['native_category' => 'simple_python']);
        File::ensureDirectoryExists($this->scratch.'/result/model/non_live');
        File::ensureDirectoryExists($this->scratch.'/score/model/non_live');
        file_put_contents(
            $this->scratch.'/result/model/non_live/BFCL_v4_simple_python_result.json',
            json_encode([
                'id' => 'simple_python_1',
                'input_token_count' => [[10]],
                'output_token_count' => [[3]],
                'latency' => [[0.5]],
            ])."\n",
        );
        file_put_contents(
            $this->scratch.'/score/model/non_live/BFCL_v4_simple_python_score.json',
            json_encode(['accuracy' => 1.0, 'correct_count' => 1, 'total_count' => 1])."\n",
        );
        $bfclUnit = (new NativeResultNormalizer)->normalize('bfcl', $bfcl, $this->root);
        $this->assertSame('simple', $bfclUnit['results'][0]['case_id']);
        $this->assertSame(13, $bfclUnit['results'][0]['tokens_in'] + $bfclUnit['results'][0]['tokens_out']);
        $this->assertTrue($bfclUnit['results'][0]['field_presence']['cost_usd']['present'] === false);
    }

    public function test_terminal_and_harbor_suites_normalize_native_trial_results(): void
    {
        $terminal = $this->entry('tb_case', ['native_task_id' => 'sanitize-git-repo']);
        $runDir = $this->scratch.'/'.$terminal['normalization']['run_name'];
        File::ensureDirectoryExists($runDir);
        $this->writeJson($runDir.'/results.json', [
            'results' => [[
                'task_id' => 'sanitize-git-repo',
                'is_resolved' => true,
                'failure_mode' => 'none',
                'total_input_tokens' => 100,
                'total_output_tokens' => 20,
                'trial_started_at' => '2026-07-09T00:00:00Z',
                'trial_ended_at' => '2026-07-09T00:00:10Z',
            ]],
        ]);
        $terminalUnit = (new NativeResultNormalizer)->normalize(
            'terminal_bench',
            $terminal,
            $this->root,
        );
        $this->assertSame('completed', $terminalUnit['episodes'][0]['exit_status']);
        $this->assertSame(10.0, $terminalUnit['episodes'][0]['duration_sec']);

        $trial = [
            'task_name' => 'ssb_case',
            'started_at' => '2026-07-09T00:00:00Z',
            'finished_at' => '2026-07-09T00:01:00Z',
            'verifier_result' => ['rewards' => [
                'reward' => 1.0,
                'correctness' => 1.0,
                'rubric_score' => 0.8,
            ]],
            'agent_result' => [
                'n_input_tokens' => 200,
                'n_output_tokens' => 50,
                'cost_usd' => 0.25,
            ],
        ];
        $senior = $this->entry('ssb_case', ['segment' => 'design']);
        $this->writeHarborJob($senior, [$trial]);
        $seniorUnit = (new NativeResultNormalizer)->normalize(
            'senior_swe_bench',
            $senior,
            $this->root,
        );
        $this->assertTrue($seniorUnit['tasks'][0]['resolved']);
        $this->assertSame('feature', $seniorUnit['tasks'][0]['task']);
        $this->assertSame(0.8, $seniorUnit['tasks'][0]['verdicts']['rubric_score']);

        $marathon = $this->entry('slack-clone', ['native_task_id' => 'slack-clone']);
        $trial['task_name'] = 'slack-clone';
        $this->writeHarborJob($marathon, [$trial]);
        $marathonUnit = (new NativeResultNormalizer)->normalize(
            'swe_marathon',
            $marathon,
            $this->root,
        );
        $this->assertTrue($marathonUnit['tasks'][0]['resolved']);
        $this->assertSame(0.25, $marathonUnit['tasks'][0]['usage']['cost_usd']);
    }

    public function test_swe_live_lcb_and_inspect_normalize_native_outputs(): void
    {
        $swe = $this->entry('swe_case', ['native_task_id' => 'astropy__astropy-1']);
        File::ensureDirectoryExists($this->scratch.'/astropy__astropy-1');
        $this->writeJson(
            $this->scratch.'/astropy__astropy-1/report.json',
            ['instance_id' => 'astropy__astropy-1', 'resolved' => true],
        );
        $this->writeJson($this->scratch.'/predictions.usage.json', [
            'duration_sec' => 10,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'cost_usd' => 0.2],
        ]);
        $sweUnit = (new NativeResultNormalizer)->normalize('swe_bench_live', $swe, $this->root);
        $this->assertTrue($sweUnit['instances'][0]['resolved']);
        $this->assertSame('swe_case', $sweUnit['instances'][0]['instance_id']);

        $lcb = $this->entry('lcb_case', ['native_task_id' => 'lcb_native_1']);
        File::ensureDirectoryExists($this->root.'/output/model');
        $this->writeJson(
            $this->root.'/output/model/codegeneration_1_0.2_eval_all.json',
            [['question_id' => 'lcb_native_1', 'graded_list' => [true], 'pass@1' => 1.0]],
        );
        $lcbUnit = (new NativeResultNormalizer)->normalize('live_code_bench', $lcb, $this->root);
        $this->assertSame('lcb_case', $lcbUnit['results'][0]['question_id']);
        $this->assertSame('lcb_native_1', $lcbUnit['results'][0]['native_question_id']);

        $inspect = $this->entry('inspect_case', [
            'task_ref' => 'inspect_evals/gsm8k',
            'sample_id' => 'native-sample',
        ]);
        $zipPath = $this->scratch.'/native.eval';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('header.json', json_encode([
            'eval' => ['task' => 'inspect_evals/gsm8k', 'model' => 'claude-sonnet-5'],
        ]));
        $zip->addFromString('summaries.json', json_encode([[
            'id' => 'native-sample',
            'epoch' => 1,
            'scores' => ['match' => ['value' => 'C']],
            'model_usage' => ['claude-sonnet-5' => [
                'input_tokens' => 10,
                'output_tokens' => 2,
                'total_cost' => 0.01,
            ]],
        ]]));
        $zip->close();
        $inspectUnit = (new NativeResultNormalizer)->normalize('inspect_evals', $inspect, $this->root);
        $this->assertSame('inspect_case', $inspectUnit['samples'][0]['id']);
        $this->assertSame(2, $inspectUnit['samples'][0]['epoch']);
    }

    public function test_hal_and_aider_normalize_native_outputs(): void
    {
        $hal = $this->entry('hal_case', [
            'benchmark' => 'gaia',
            'task_id' => 'native-hal-id',
        ]);
        $halDir = $this->scratch.'/gaia/'.$hal['normalization']['run_name'];
        File::ensureDirectoryExists($halDir);
        $this->writeJson($halDir.'/'.$hal['normalization']['run_name'].'_UPLOAD.json', [
            'raw_eval_results' => ['native-hal-id' => ['score' => 1.0]],
            'task_costs' => ['native-hal-id' => ['total_cost' => 0.03]],
            'wall_clock_times' => ['native-hal-id' => 4.5],
            'total_usage' => ['input_tokens' => 30, 'output_tokens' => 5],
        ]);
        $halUnit = (new NativeResultNormalizer)->normalize('hal_harness', $hal, $this->root);
        $this->assertTrue($halUnit['runs'][0]['success']);
        $this->assertSame(0.03, $halUnit['runs'][0]['total_cost_usd']);

        $halMini = $this->entry('django__django-11790', [
            'benchmark' => 'swebench_verified_mini',
            'task_id' => 'django__django-11790',
        ]);
        $halMini['model_id'] = 'verboo_kimi_k2_7';
        $halMiniDir = $this->scratch.'/swebench_verified_mini/'.$halMini['normalization']['run_name'];
        File::ensureDirectoryExists($halMiniDir);
        $this->writeJson($halMiniDir.'/'.$halMini['normalization']['run_name'].'_UPLOAD.json', [
            'results' => [
                'accuracy' => 0.0,
                'successful_tasks' => [],
                'failed_tasks' => ['django__django-11790'],
                'total_cost' => 0.0,
                'latencies' => [],
            ],
            'raw_eval_results' => [
                'error_ids' => ['django__django-11790'],
                'resolved_ids' => [],
            ],
            'wall_clock_times' => ['django__django-11790' => 2.8],
            'total_cost' => 0.0,
            'total_usage' => [],
        ]);
        $halMiniUnit = (new NativeResultNormalizer)->normalize('hal_harness', $halMini, $this->root);
        $this->assertFalse($halMiniUnit['runs'][0]['success']);
        $this->assertSame(0.0, $halMiniUnit['runs'][0]['total_cost_usd']);
        $this->assertSame(2.8, $halMiniUnit['runs'][0]['latency_sec']);

        $aider = $this->entry('aider_case', ['native_task_id' => 'anagram']);
        $aiderDir = $this->root.'/tmp.benchmarks/2026-'.$aider['normalization']['run_name']
            .'/python/exercises/practice/anagram';
        File::ensureDirectoryExists($aiderDir);
        $this->writeJson($aiderDir.'/.aider.results.json', [
            'testcase' => 'anagram',
            'model' => 'claude-sonnet-5',
            'tests_outcomes' => [false, true],
            'duration' => 5.0,
            'cost' => 0.02,
            'prompt_tokens' => 50,
            'completion_tokens' => 10,
        ]);
        $aiderUnit = (new NativeResultNormalizer)->normalize('aider_polyglot', $aider, $this->root);
        $this->assertSame('aider_case', $aiderUnit['results'][0]['testcase']);
        $this->assertSame([false, true], $aiderUnit['results'][0]['tests_outcomes']);
    }

    /** @return array<string, mixed> */
    private function entry(string $caseId, array $case): array
    {
        return [
            'execution_id' => 'ne_test_'.str_replace('-', '_', $caseId),
            'case_id' => $caseId,
            'arm_id' => 'claude_sonnet_5@bare',
            'cli_model' => 'claude-sonnet-5',
            'native_agent' => 'claude-code',
            'repetition' => 2,
            'max_seconds' => 30,
            'normalization' => [
                'scratch_dir' => $this->scratch,
                'run_name' => 'rivals_run_r2',
                'temperature' => 0.2,
                'case' => ['case_id' => $caseId] + $case,
                'judge_config' => ['judge' => 'pinned'],
            ],
        ];
    }

    private function writeHarborJob(array $entry, array $trials): void
    {
        $dir = $this->scratch.'/'.$entry['normalization']['run_name'];
        File::ensureDirectoryExists($dir);
        $this->writeJson($dir.'/result.json', ['trial_results' => $trials]);
    }

    private function writeJson(string $path, array $data): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
