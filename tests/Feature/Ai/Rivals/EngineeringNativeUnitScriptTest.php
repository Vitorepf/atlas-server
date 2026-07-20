<?php

namespace Tests\Feature\Ai\Rivals;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EngineeringNativeUnitScriptTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir().'/rivals_engineering_unit_'.uniqid();
        File::ensureDirectoryExists($this->scratch);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        parent::tearDown();
    }

    public function test_plan_binds_bare_to_raw_verboo_and_atlas_to_real_cli_dev_bridge(): void
    {
        $caseFile = base_path('tests/Fixtures/Rivals/cases/archbench/archbench_adr_000.json');

        $bare = $this->plan($caseFile, 'bare');
        $this->assertSame('rivals-hermes-bare.php', basename($bare['solver_argv'][1]));
        $this->assertSame('bare', $bare['runtime']);
        $this->assertSame('kimi-k2.7', $bare['model']);
        $this->assertStringNotContainsString('atlas-dev', implode(' ', $bare['solver_argv']));

        $atlas = $this->plan($caseFile, 'atlas_dev');
        $this->assertSame('rivals-atlas-dev-bridge.php', basename($atlas['solver_argv'][1]));
        $this->assertSame('atlas_dev', $atlas['runtime']);
        $this->assertStringContainsString('rivals-atlas-dev-bridge.php', implode(' ', $atlas['solver_argv']));
        $this->assertNotSame($bare['solver_argv'][1], $atlas['solver_argv'][1]);
    }

    public function test_plan_rejects_an_unimplemented_suite_or_runtime_before_provider_spend(): void
    {
        $caseFile = base_path('tests/Fixtures/Rivals/cases/archbench/archbench_adr_000.json');
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-engineering-unit.php'),
            '--suite=archbench',
            '--case-file='.$caseFile,
            '--model=kimi-k2.7',
            '--registry-model=kimi-k2.7',
            '--runtime=forge',
            '--scratch='.$this->scratch,
            '--rep=1',
            '--plan',
        ], base_path());
        $process->run();

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString(
            'rivals_engineering_unit_invalid_runtime',
            $process->getErrorOutput(),
        );
    }

    public function test_prepare_only_materializes_targets_that_have_an_edit_template(): void
    {
        $createTargets = [
            'archbench' => 'decision.md',
            'cruxeval' => 'answer.txt',
            'reval' => 'answer.txt',
            'repobench' => 'completion.txt',
            'locagent' => 'localization.json',
            'testeval' => 'tests.py',
            'crosscodeeval' => 'completion.txt',
            'deveval' => 'completion.py',
            'long_code_arena' => 'solution.py',
        ];
        foreach ($createTargets as $suite => $target) {
            $caseFile = glob(
                base_path("tests/Fixtures/Rivals/cases/{$suite}/*.json"),
            )[0];
            $workspace = "{$this->scratch}/create_{$suite}";
            $this->driver(
                $suite,
                'prepare',
                $caseFile,
                base_path("tools/rivals/benchmarks/_prova/{$suite}"),
                $workspace,
            );
            $this->assertFileDoesNotExist(
                "{$workspace}/{$target}",
                "{$suite} create target must be absent from the committed baseline",
            );
        }

        foreach (['evalplus', 'classeval', 'bigcodebench'] as $suite) {
            $caseFile = glob(
                base_path("tests/Fixtures/Rivals/cases/{$suite}/*.json"),
            )[0];
            $workspace = "{$this->scratch}/edit_{$suite}";
            $prepared = $this->driver(
                $suite,
                'prepare',
                $caseFile,
                base_path("tools/rivals/benchmarks/_prova/{$suite}"),
                $workspace,
            );
            $this->assertFileExists($prepared['artifact_target']);
            $this->assertNotSame('', trim(File::get($prepared['artifact_target'])));
        }
    }

    public function test_native_driver_scores_cruxeval_evalplus_and_reval_with_official_evaluators(): void
    {
        $cruxWorkspace = $this->scratch.'/cruxeval';
        $cruxCase = base_path(
            'tests/Fixtures/Rivals/cases/cruxeval/cruxeval_output_000.json',
        );
        $cruxRepo = base_path('tools/rivals/benchmarks/_prova/cruxeval');
        $this->driver('cruxeval', 'prepare', $cruxCase, $cruxRepo, $cruxWorkspace);
        $cruxRow = json_decode(
            (string) file($cruxRepo.'/data/cruxeval.jsonl')[0],
            true,
        );
        File::put($cruxWorkspace.'/answer.txt', (string) $cruxRow['output']);
        $crux = $this->driver(
            'cruxeval',
            'evaluate',
            $cruxCase,
            $cruxRepo,
            $cruxWorkspace,
        );
        $this->assertTrue($crux['valid_result']);
        $this->assertTrue($crux['benchmark_pass']);
        $this->assertSame(1.0, $crux['score']);

        $evalplusWorkspace = $this->scratch.'/evalplus';
        $evalplusCase = base_path(
            'tests/Fixtures/Rivals/cases/evalplus/evalplus_humaneval_000.json',
        );
        $evalplusRepo = base_path('tools/rivals/benchmarks/_prova/evalplus');
        $this->driver(
            'evalplus',
            'prepare',
            $evalplusCase,
            $evalplusRepo,
            $evalplusWorkspace,
        );
        $gold = new Process([
            $evalplusRepo.'/.venv/bin/python',
            '-c',
            'from evalplus.data import get_human_eval_plus; '
                .'p=get_human_eval_plus()["HumanEval/0"]; '
                .'print(p["prompt"]+p["canonical_solution"], end="")',
        ], $evalplusRepo);
        $gold->mustRun();
        File::put($evalplusWorkspace.'/solution.py', $gold->getOutput());
        $evalplus = $this->driver(
            'evalplus',
            'evaluate',
            $evalplusCase,
            $evalplusRepo,
            $evalplusWorkspace,
        );
        $this->assertTrue($evalplus['valid_result']);
        $this->assertTrue($evalplus['benchmark_pass']);
        $this->assertSame(1.0, $evalplus['score']);

        $revalWorkspace = $this->scratch.'/reval';
        $revalCase = base_path('tests/Fixtures/Rivals/cases/reval/reval_000.json');
        $revalRepo = base_path('tools/rivals/benchmarks/_prova/reval');
        $this->driver('reval', 'prepare', $revalCase, $revalRepo, $revalWorkspace);
        $revalScores = [];
        foreach (['YES', 'NO'] as $answer) {
            File::put($revalWorkspace.'/answer.txt', $answer);
            $result = $this->driver(
                'reval',
                'evaluate',
                $revalCase,
                $revalRepo,
                $revalWorkspace,
                'native_artifact_'.$answer.'.json',
            );
            $this->assertTrue($result['valid_result']);
            $revalScores[] = $result['score'];
        }
        sort($revalScores);
        $this->assertSame([0.0, 1.0], $revalScores);

        $revalEmptyWorkspace = $this->scratch.'/reval_empty';
        $revalEmptyCase = base_path('tests/Fixtures/Rivals/cases/reval/reval_002.json');
        $this->driver(
            'reval',
            'prepare',
            $revalEmptyCase,
            $revalRepo,
            $revalEmptyWorkspace,
        );
        File::put($revalEmptyWorkspace.'/answer.txt', '');
        $revalEmpty = $this->driver(
            'reval',
            'evaluate',
            $revalEmptyCase,
            $revalRepo,
            $revalEmptyWorkspace,
            'native_artifact_empty.json',
        );
        $this->assertFalse($revalEmpty['valid_result']);
        $this->assertFalse($revalEmpty['benchmark_pass']);
        $this->assertSame(0.0, $revalEmpty['score']);
        $this->assertSame('reval_empty_answer', $revalEmpty['failure_reason']);
    }

    public function test_testeval_rejects_a_missing_model_artifact_instead_of_reporting_success(): void
    {
        $repo = base_path('tools/rivals/benchmarks/_prova/testeval');
        $case = base_path(
            'tests/Fixtures/Rivals/cases/testeval/testeval_000.json',
        );
        $workspace = $this->scratch.'/testeval_missing_artifact';
        $this->driver('testeval', 'prepare', $case, $repo, $workspace);

        $result = $this->driver(
            'testeval',
            'evaluate',
            $case,
            $repo,
            $workspace,
        );

        $this->assertFalse($result['valid_result']);
        $this->assertSame(0.0, $result['score']);
        $this->assertSame(
            'testeval_tests_missing_or_empty',
            $result['failure_reason'],
        );
    }

    public function test_native_driver_scores_all_remaining_engineering_suites_with_official_evaluators(): void
    {
        $classevalRepo = base_path('tools/rivals/benchmarks/_prova/classeval');
        $classevalCase = base_path(
            'tests/Fixtures/Rivals/cases/classeval/classeval_000.json',
        );
        $classevalWorkspace = $this->scratch.'/classeval';
        $this->driver(
            'classeval',
            'prepare',
            $classevalCase,
            $classevalRepo,
            $classevalWorkspace,
        );
        $classevalData = json_decode(
            File::get($classevalRepo.'/data/ClassEval_data.json'),
            true,
        );
        File::put(
            $classevalWorkspace.'/solution.py',
            $classevalData[0]['solution_code'],
        );
        $classeval = $this->driver(
            'classeval',
            'evaluate',
            $classevalCase,
            $classevalRepo,
            $classevalWorkspace,
        );
        $this->assertTrue($classeval['valid_result']);
        $this->assertSame(1.0, $classeval['score']);

        $repobenchRepo = base_path('tools/rivals/benchmarks/_prova/repobench');
        $repobenchCase = base_path(
            'tests/Fixtures/Rivals/cases/repobench/repobench_python_000.json',
        );
        $repobenchWorkspace = $this->scratch.'/repobench';
        $this->driver(
            'repobench',
            'prepare',
            $repobenchCase,
            $repobenchRepo,
            $repobenchWorkspace,
        );
        $repobenchReference = json_decode(
            File::get(
                dirname($repobenchWorkspace)
                    .'/.repobench_repobench_python_000_upstream.json',
            ),
            true,
        );
        File::put(
            $repobenchWorkspace.'/completion.txt',
            $repobenchReference['next_line'],
        );
        $repobench = $this->driver(
            'repobench',
            'evaluate',
            $repobenchCase,
            $repobenchRepo,
            $repobenchWorkspace,
        );
        $this->assertTrue($repobench['valid_result']);
        $this->assertSame(1.0, $repobench['score']);

        $locagentRepo = base_path('tools/rivals/benchmarks/_prova/locagent');
        $locagentCase = base_path(
            'tests/Fixtures/Rivals/cases/locagent/locagent_000.json',
        );
        $locagentWorkspace = $this->scratch.'/locagent';
        $this->driver(
            'locagent',
            'prepare',
            $locagentCase,
            $locagentRepo,
            $locagentWorkspace,
        );
        File::put(
            $locagentWorkspace.'/localization.json',
            json_encode(['astropy/modeling/separable.py']),
        );
        $locagent = $this->driver(
            'locagent',
            'evaluate',
            $locagentCase,
            $locagentRepo,
            $locagentWorkspace,
        );
        $this->assertTrue($locagent['valid_result']);
        $this->assertSame(1.0, $locagent['score']);

        $debugRepo = base_path('tools/rivals/benchmarks/_prova/debug_gym');
        $debugCase = base_path(
            'tests/Fixtures/Rivals/cases/debug_gym/debug_gym_knapsack.json',
        );
        $debugWorkspace = $this->scratch.'/debug_gym';
        $this->driver(
            'debug_gym',
            'prepare',
            $debugCase,
            $debugRepo,
            $debugWorkspace,
        );
        $fixedPurr = str_replace(
            "        if self.hunger > 10:\n"
                ."            result = self.meow()\n"
                ."            self.feed(\"fish\")\n"
                ."        elif self.hunger > 20:\n"
                ."            result = self.intense_meow()\n"
                ."            self.feed(\"meat\")",
            "        if self.hunger > 20:\n"
                ."            result = self.intense_meow()\n"
                ."            self.feed(\"meat\")\n"
                ."        elif self.hunger > 10:\n"
                ."            result = self.meow()\n"
                ."            self.feed(\"fish\")",
            File::get($debugWorkspace.'/purr_code.py'),
        );
        File::put($debugWorkspace.'/purr_code.py', $fixedPurr);
        $debug = $this->driver(
            'debug_gym',
            'evaluate',
            $debugCase,
            $debugRepo,
            $debugWorkspace,
        );
        $this->assertTrue($debug['valid_result']);
        $this->assertTrue($debug['benchmark_pass']);

        $testevalRepo = base_path('tools/rivals/benchmarks/_prova/testeval');
        $testevalCase = base_path(
            'tests/Fixtures/Rivals/cases/testeval/testeval_000.json',
        );
        $testevalWorkspace = $this->scratch.'/testeval';
        $this->driver(
            'testeval',
            'prepare',
            $testevalCase,
            $testevalRepo,
            $testevalWorkspace,
        );
        File::put(
            $testevalWorkspace.'/tests.py',
            "def test_findMedianSortedArrays():\n"
                ."    solution = Solution()\n"
                ."    assert solution.findMedianSortedArrays([1, 3], [2]) == 2\n",
        );
        $testeval = $this->driver(
            'testeval',
            'evaluate',
            $testevalCase,
            $testevalRepo,
            $testevalWorkspace,
        );
        $this->assertTrue($testeval['valid_result']);
        $this->assertGreaterThan(
            0,
            $testeval['score'],
            File::get($testevalWorkspace.'/native_artifact.json'),
        );

        $crossRepo = base_path('tools/rivals/benchmarks/_prova/crosscodeeval');
        $crossCase = base_path(
            'tests/Fixtures/Rivals/cases/crosscodeeval/crosscodeeval_python_000.json',
        );
        $crossWorkspace = $this->scratch.'/crosscodeeval';
        $this->driver(
            'crosscodeeval',
            'prepare',
            $crossCase,
            $crossRepo,
            $crossWorkspace,
        );
        $crossRow = json_decode(
            (string) file(
                $crossRepo.'/data/python/line_completion_rg1_unixcoder_cosine_sim.jsonl',
            )[0],
            true,
        );
        File::put($crossWorkspace.'/completion.txt', $crossRow['groundtruth']);
        $cross = $this->driver(
            'crosscodeeval',
            'evaluate',
            $crossCase,
            $crossRepo,
            $crossWorkspace,
        );
        $this->assertTrue($cross['valid_result']);
        $this->assertSame(1.0, $cross['score']);

        $bigCodeRepo = base_path('tools/rivals/benchmarks/_prova/bigcodebench');
        $bigCodeCase = base_path(
            'tests/Fixtures/Rivals/cases/bigcodebench/bigcodebench_000.json',
        );
        $bigCodeWorkspace = $this->scratch.'/bigcodebench';
        $this->driver(
            'bigcodebench',
            'prepare',
            $bigCodeCase,
            $bigCodeRepo,
            $bigCodeWorkspace,
        );
        $bigCodeReference = json_decode(
            File::get(
                dirname($bigCodeWorkspace)
                    .'/.bigcodebench_bigcodebench_000_upstream.json',
            ),
            true,
        );
        File::put(
            $bigCodeWorkspace.'/solution.py',
            $bigCodeReference['complete_prompt']
                ."\n"
                .$bigCodeReference['canonical_solution'],
        );
        $bigCode = $this->driver(
            'bigcodebench',
            'evaluate',
            $bigCodeCase,
            $bigCodeRepo,
            $bigCodeWorkspace,
        );
        $this->assertTrue($bigCode['valid_result']);
        $this->assertTrue(
            $bigCode['benchmark_pass'],
            File::get($bigCodeWorkspace.'/native_artifact.json'),
        );

        $devevalRepo = base_path('tools/rivals/benchmarks/_prova/deveval');
        $devevalCase = base_path(
            'tests/Fixtures/Rivals/cases/deveval/deveval_000.json',
        );
        $devevalWorkspace = $this->scratch.'/deveval';
        $devevalRow = json_decode(
            (string) file($devevalRepo.'/data.jsonl')[0],
            true,
        );
        $goldSource = file(
            $devevalRepo.'/Source_Code/'.$devevalRow['completion_path'],
        );
        $goldBody = implode('', array_slice(
            $goldSource,
            $devevalRow['body_position'][0] - 1,
            $devevalRow['body_position'][1] - $devevalRow['body_position'][0] + 1,
        ));
        $this->driver(
            'deveval',
            'prepare',
            $devevalCase,
            $devevalRepo,
            $devevalWorkspace,
        );
        File::put($devevalWorkspace.'/completion.py', $goldBody);
        $deveval = $this->driver(
            'deveval',
            'evaluate',
            $devevalCase,
            $devevalRepo,
            $devevalWorkspace,
        );
        $this->assertTrue($deveval['valid_result']);
        $this->assertTrue(
            $deveval['benchmark_pass'],
            File::get($devevalWorkspace.'/native_artifact.json'),
        );

        $longRepo = base_path('tools/rivals/benchmarks/_prova/long_code_arena');
        $longCase = base_path(
            'tests/Fixtures/Rivals/cases/long_code_arena/long_code_arena_000.json',
        );
        $longWorkspace = $this->scratch.'/long_code_arena';
        $this->driver(
            'long_code_arena',
            'prepare',
            $longCase,
            $longRepo,
            $longWorkspace,
        );
        $longReference = json_decode(
            File::get(
                dirname($longWorkspace)
                    .'/.long_code_arena_long_code_arena_000_upstream.json',
            ),
            true,
        );
        File::put(
            $longWorkspace.'/solution.py',
            $longReference['clean_reference'],
        );
        $long = $this->driver(
            'long_code_arena',
            'evaluate',
            $longCase,
            $longRepo,
            $longWorkspace,
        );
        $this->assertTrue($long['valid_result']);
        $this->assertSame(1.0, $long['score']);
    }

    public function test_all_engineering_case_packs_materialize_and_return_native_measurements(): void
    {
        $suites = [
            'archbench',
            'cruxeval',
            'classeval',
            'repobench',
            'locagent',
            'debug_gym',
            'testeval',
            'evalplus',
            'crosscodeeval',
            'bigcodebench',
            'deveval',
            'long_code_arena',
            'reval',
        ];
        foreach ($suites as $suite) {
            $caseFiles = glob(
                base_path("tests/Fixtures/Rivals/cases/{$suite}/*.json"),
            );
            sort($caseFiles);
            // Piso alinhado ao min_distinct_cases_public=10 do claim gate: pack
            // menor que 10 problemas DISTINTOS nunca sustenta capacidade "medida"
            // (expansão 3→10 em 20/07; réplica não é problema novo).
            $this->assertGreaterThanOrEqual(10, count($caseFiles), $suite);
            foreach ($caseFiles as $index => $caseFile) {
                $repo = base_path("tools/rivals/benchmarks/_prova/{$suite}");
                $workspace = "{$this->scratch}/matrix_{$suite}_{$index}";
                $case = json_decode(File::get($caseFile), true);
                $this->driver(
                    $suite,
                    'prepare',
                    $caseFile,
                    $repo,
                    $workspace,
                );

                if ($suite === 'archbench') {
                    $csv = fopen(
                        (string) getenv('HOME')
                            .'/.cache/archbench/adr/0_shot.csv',
                        'r',
                    );
                    $headers = fgetcsv($csv);
                    $values = null;
                    for ($rowIndex = 0; $rowIndex <= $index; $rowIndex++) {
                        $values = fgetcsv($csv);
                    }
                    fclose($csv);
                    $row = array_combine($headers, $values);
                    File::put($workspace.'/decision.md', $row['decision']);
                } elseif ($suite === 'cruxeval') {
                    $row = json_decode(
                        (string) file($repo.'/data/cruxeval.jsonl')[$index],
                        true,
                    );
                    File::put($workspace.'/answer.txt', $row['output']);
                } elseif ($suite === 'classeval') {
                    $rows = json_decode(
                        File::get($repo.'/data/ClassEval_data.json'),
                        true,
                    );
                    File::put(
                        $workspace.'/solution.py',
                        $rows[$index]['solution_code'],
                    );
                } elseif ($suite === 'repobench') {
                    $reference = $this->privateReference(
                        $workspace,
                        $suite,
                        $case['case_id'],
                    );
                    File::put(
                        $workspace.'/completion.txt',
                        $reference['next_line'],
                    );
                } elseif ($suite === 'locagent') {
                    $reference = $this->privateReference(
                        $workspace,
                        $suite,
                        $case['case_id'],
                    );
                    $goldFile = explode(
                        ':',
                        $reference['edit_functions'][0],
                    )[0];
                    File::put(
                        $workspace.'/localization.json',
                        json_encode([$goldFile]),
                    );
                } elseif ($suite === 'testeval') {
                    $row = json_decode(
                        (string) file($repo.'/data/leetcode-py.jsonl')[
                            (int) $case['native_index']
                        ],
                        true,
                    );
                    File::put(
                        $workspace.'/tests.py',
                        'def test_'.$row['func_name']."():\n"
                            .'    assert callable(getattr(Solution(), '
                            .var_export($row['func_name'], true)."))\n",
                    );
                } elseif ($suite === 'evalplus') {
                    File::put(
                        $workspace.'/solution.py',
                        $this->pythonOutput(
                            $repo,
                            'from evalplus.data import get_human_eval_plus; '
                                .'p=get_human_eval_plus()['
                                .var_export($case['native_task_id'], true)
                                .']; print(p["prompt"]+p["canonical_solution"], end="")',
                        ),
                    );
                } elseif ($suite === 'crosscodeeval') {
                    $row = json_decode(
                        (string) file(
                            $repo
                                .'/data/python/'
                                .'line_completion_rg1_unixcoder_cosine_sim.jsonl',
                        )[$index],
                        true,
                    );
                    File::put(
                        $workspace.'/completion.txt',
                        $row['groundtruth'],
                    );
                } elseif ($suite === 'bigcodebench') {
                    $reference = $this->privateReference(
                        $workspace,
                        $suite,
                        $case['case_id'],
                    );
                    File::put(
                        $workspace.'/solution.py',
                        $reference['complete_prompt']
                            ."\n"
                            .$reference['canonical_solution'],
                    );
                } elseif ($suite === 'deveval') {
                    $row = json_decode(
                        (string) file($repo.'/data.jsonl')[$index],
                        true,
                    );
                    $source = file(
                        $repo.'/Source_Code/'.$row['completion_path'],
                    );
                    File::put(
                        $workspace.'/completion.py',
                        implode('', array_slice(
                            $source,
                            $row['body_position'][0] - 1,
                            $row['body_position'][1]
                                - $row['body_position'][0]
                                + 1,
                        )),
                    );
                } elseif ($suite === 'long_code_arena') {
                    $reference = $this->privateReference(
                        $workspace,
                        $suite,
                        $case['case_id'],
                    );
                    File::put(
                        $workspace.'/solution.py',
                        $reference['clean_reference'],
                    );
                } elseif ($suite === 'reval') {
                    File::put($workspace.'/answer.txt', 'YES');
                }

                $result = $this->driver(
                    $suite,
                    'evaluate',
                    $caseFile,
                    $repo,
                    $workspace,
                );
                $this->assertTrue(
                    $result['valid_result'],
                    File::get($workspace.'/native_artifact.json'),
                );
                $this->assertContains(
                    $result['measurement_type'],
                    ['binary', 'continuous'],
                );
                $this->assertIsNumeric($result['score']);
            }
        }
    }

    /** @return array<string, mixed> */
    private function plan(string $caseFile, string $runtime): array
    {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-engineering-unit.php'),
            '--suite=archbench',
            '--case-file='.$caseFile,
            '--model=kimi-k2.7',
            '--registry-model=kimi-k2.7',
            '--runtime='.$runtime,
            '--scratch='.$this->scratch,
            '--rep=1',
            '--plan',
        ], base_path());
        $process->mustRun();
        $payload = json_decode($process->getOutput(), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function driver(
        string $suite,
        string $action,
        string $caseFile,
        string $repoRoot,
        string $workspace,
        string $artifact = 'native_artifact.json',
    ): array {
        $python = is_file($repoRoot.'/.venv/bin/python')
            ? $repoRoot.'/.venv/bin/python'
            : $repoRoot.'/library_based_code_generation/.venv/bin/python';
        $argv = [
            $python,
            base_path('scripts/rivals_engineering_driver.py'),
            $action,
            '--suite='.$suite,
            '--case-file='.$caseFile,
            '--repo-root='.$repoRoot,
            '--workspace='.$workspace,
        ];
        if ($action === 'evaluate') {
            $argv[] = '--artifact='.$workspace.'/'.$artifact;
        }
        $process = new Process($argv, $repoRoot);
        $process->setTimeout(300);
        $process->mustRun();
        $payload = json_decode(trim($process->getOutput()), true);
        $this->assertIsArray($payload, $process->getOutput());

        return $payload;
    }

    /** @return array<string, mixed> */
    private function privateReference(
        string $workspace,
        string $suite,
        string $caseId,
    ): array {
        return json_decode(
            File::get(
                dirname($workspace)."/.{$suite}_{$caseId}_upstream.json",
            ),
            true,
        );
    }

    private function pythonOutput(string $repoRoot, string $script): string
    {
        $process = new Process([
            $repoRoot.'/.venv/bin/python',
            '-c',
            $script,
        ], $repoRoot);
        $process->setTimeout(300);
        $process->mustRun();

        return $process->getOutput();
    }

}
