<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Contracts\BenchmarkSuiteAdapter;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Core\NativeExecutionReceipt;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NativeRunnerLoggingTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_native_runner_logging_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_failed_native_unit_persists_readable_logs_and_exact_reason(): void
    {
        $adapter = new class implements BenchmarkSuiteAdapter
        {
            public function suiteId(): string
            {
                return 'tau2_bench';
            }

            public function listCases(array $filters = []): array
            {
                return [['case_id' => 'runner_log_probe', 'task_type' => 'tool_use', 'title' => 'runner log probe']];
            }

            public function planCommands(RunPlan $plan): array
            {
                return [];
            }

            public function ingestResults(string $runDir): array
            {
                return [];
            }
        };
        $arm = (new ArmRegistry)->parse('claude_sonnet_5@bare', 'tau2_bench');
        $plan = RunPlan::make(
            'tau2_bench',
            ['runner_log_probe'],
            [$arm],
            1,
            ['max_usd' => 0.0, 'max_minutes' => 1],
            42,
        );
        $planData = $plan->data;
        $planData['environment']['approve_provider_spend'] = true;
        $plan = RunPlan::fromArray($planData);
        $plan->persist();
        $scratch = RunPaths::runDir($plan->runId()).'/native_scratch/probe';
        $proofFailure = 'atlas_dev_provider_pre_response_failure:'
            .'candidate_preparation_blocked:provider_unavailable:provider_failure:timeout';
        $proofJson = json_encode([
            'schema_version' => 'atlas.rivals2.atlas_dev_bridge_receipt.v2',
            'status' => 'failed',
            'failure_reason' => $proofFailure,
        ], JSON_THROW_ON_ERROR);
        $probeCode = '@mkdir('.var_export($scratch, true).', 0755, true); '
            .'file_put_contents('.var_export($scratch.'/.rivals_atlas_dev_bridge.json', true)
            .', '.var_export($proofJson, true).'); '
            .'fwrite(STDOUT, "unit stdout\\n"); '
            .'fwrite(STDERR, "Traceback (most recent call last):\\nValueError: No tasks matched the filter ssb_0034\\n"); '
            .'exit(7);';
        $commands = [[
            'case_id' => 'runner_log_probe',
            'arm_id' => $arm['arm_id'],
            'repetition' => 1,
            'command' => 'php inline log probe',
            'argv' => [
                PHP_BINARY,
                '-r',
                $probeCode,
            ],
            'normalization' => [
                'case' => ['native_task_id' => 'runner_log_probe'],
                'scratch_dir' => $scratch,
            ],
        ]];
        $manifest = NativeExecutionManifest::fromPlan($plan, $adapter, $commands);
        $manifestPath = $manifest->persist();
        $entry = $manifest->entries()[0];

        $runnerArgs = [
            PHP_BINARY,
            base_path('scripts/rivals-native-runner.php'),
            '--manifest='.$manifestPath,
            '--cwd='.base_path(),
            '--execution-id='.$entry['execution_id'],
            '--approve-provider-spend',
        ];
        $process = new Process($runnerArgs, base_path(), [
            'ATLAS_RIVALS2_STORAGE' => $this->storage,
        ]);
        $process->setTimeout(30);
        $process->run();

        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        $receipts = NativeExecutionReceipt::loadAll($plan->runId());
        $this->assertNotEmpty(
            $receipts,
            "runner stdout:\n{$process->getOutput()}\nrunner stderr:\n{$process->getErrorOutput()}",
        );
        $receipt = $receipts[0];
        $this->assertSame('environment_failure', $receipt->data['status']);
        $expectedFailureReason =
            'normalization_failed:tau2_results_missing; native_stderr_cause=ValueError: No tasks matched the filter ssb_0034'
            .'; runtime_proof_cause='.$proofFailure;
        $this->assertSame($expectedFailureReason, $receipt->data['failure_reason']);
        foreach (['stdout', 'stderr'] as $stream) {
            $this->assertTrue($receipt->data[$stream]['present']);
            $this->assertNotEmpty($receipt->data[$stream]['path']);
            $this->assertFileExists(
                RunPaths::runDir($plan->runId()).'/'.$receipt->data[$stream]['path'],
            );
        }
        $stdout = (string) file_get_contents(
            RunPaths::runDir($plan->runId()).'/'.$receipt->data['stdout']['path'],
        );
        $stderr = (string) file_get_contents(
            RunPaths::runDir($plan->runId()).'/'.$receipt->data['stderr']['path'],
        );
        $this->assertStringContainsString('unit stdout', $stdout);
        $this->assertStringContainsString('ValueError: No tasks matched the filter ssb_0034', $stderr);
        $this->assertStringContainsString('[rivals-normalizer] tau2_results_missing', $stderr);

        $runnerPayload = json_decode($process->getOutput(), true);
        $this->assertSame($expectedFailureReason, $runnerPayload['executions'][0]['failure_reason'] ?? null);

        File::ensureDirectoryExists($scratch);
        $staleProof = $scratch.'/.rivals_atlas_dev_bridge.json';
        file_put_contents($staleProof, json_encode([
            'schema_version' => 'atlas.rivals2.atlas_dev_bridge_receipt.v2',
            'status' => 'passed',
            'failure_reason' => null,
            'attempt_marker' => 'must_be_archived_not_reused',
        ]));

        $retry = new Process($runnerArgs, base_path(), [
            'ATLAS_RIVALS2_STORAGE' => $this->storage,
        ]);
        $retry->setTimeout(30);
        $retry->run();

        $this->assertSame(1, $retry->getExitCode());
        $attempts = glob(
            RunPaths::nativeReceiptsDir($plan->runId()).'/attempts/'
                .$entry['execution_id'].'/attempt-*.receipt.json',
        ) ?: [];
        $this->assertCount(1, $attempts);
        $archived = json_decode((string) file_get_contents($attempts[0]), true);
        $this->assertSame('environment_failure', $archived['status'] ?? null);
        $this->assertNotEmpty($archived['failure_reason'] ?? null);
        $this->assertSame(
            'must_be_archived_not_reused',
            data_get($archived, 'archived_runtime_proofs.0.payload.attempt_marker'),
        );
        $liveProof = json_decode((string) file_get_contents($staleProof), true);
        $this->assertSame($proofFailure, $liveProof['failure_reason'] ?? null);
        $this->assertArrayNotHasKey('attempt_marker', $liveProof);
        $this->assertFileIsReadable(
            RunPaths::runDir($plan->runId()).'/'.$archived['archived_result_path'],
        );
        foreach (['stdout', 'stderr'] as $stream) {
            $this->assertFileIsReadable(
                RunPaths::runDir($plan->runId()).'/'.$archived[$stream]['path'],
            );
        }
    }

    public function test_completed_manifest_unit_is_reused_without_reexecuting_command(): void
    {
        $adapter = new class implements BenchmarkSuiteAdapter
        {
            public function suiteId(): string
            {
                return 'tau2_bench';
            }

            public function listCases(array $filters = []): array
            {
                return [['case_id' => 'reuse_probe', 'task_type' => 'tool_use', 'title' => 'reuse probe']];
            }

            public function planCommands(RunPlan $plan): array
            {
                return [];
            }

            public function ingestResults(string $runDir): array
            {
                return [];
            }
        };
        $arm = (new ArmRegistry)->parse('claude_sonnet_5@bare', 'tau2_bench');
        $plan = RunPlan::make(
            'tau2_bench',
            ['reuse_probe'],
            [$arm],
            1,
            ['max_usd' => 0.0, 'max_minutes' => 1],
            42,
        );
        $planData = $plan->data;
        $planData['environment']['approve_provider_spend'] = true;
        $plan = RunPlan::fromArray($planData);
        $plan->persist();
        $manifest = NativeExecutionManifest::fromPlan($plan, $adapter, [[
            'case_id' => 'reuse_probe',
            'arm_id' => $arm['arm_id'],
            'repetition' => 1,
            'command' => 'must never execute',
            'argv' => [PHP_BINARY, '-r', 'exit(99);'],
            'normalization' => [
                'case' => ['native_task_id' => 'reuse_probe'],
                'scratch_dir' => RunPaths::runDir($plan->runId()).'/native_scratch/reuse',
            ],
        ]]);
        $manifestPath = $manifest->persist();
        $entry = $manifest->entries()[0];
        $resultPath = RunPaths::runDir($plan->runId()).'/'.$entry['expected_result_path'];
        File::ensureDirectoryExists(dirname($resultPath));
        file_put_contents($resultPath, '{"already":"complete"}');
        $logDir = RunPaths::nativeReceiptsDir($plan->runId()).'/logs';
        File::ensureDirectoryExists($logDir);
        $stdoutPath = $logDir.'/'.$entry['execution_id'].'.stdout.log';
        $stderrPath = $logDir.'/'.$entry['execution_id'].'.stderr.log';
        file_put_contents($stdoutPath, 'persisted stdout');
        file_put_contents($stderrPath, '');
        NativeExecutionReceipt::fromArray([
            'schema_version' => NativeExecutionReceipt::SCHEMA,
            'run_id' => $plan->runId(),
            'execution_id' => $entry['execution_id'],
            'manifest_hash' => $manifest->hash(),
            'command_hash' => $entry['command_hash'],
            'expected_result_path' => $entry['expected_result_path'],
            'result_sha256' => hash_file('sha256', $resultPath),
            'status' => 'success',
            'failure_reason' => null,
            'exit_code' => 0,
            'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'wall_ms' => 1,
            'cost_usd' => 0.0,
            'stdout' => [
                'present' => true,
                'path' => 'native_execution_receipts/logs/'.$entry['execution_id'].'.stdout.log',
                'sha256' => hash_file('sha256', $stdoutPath),
            ],
            'stderr' => [
                'present' => true,
                'path' => 'native_execution_receipts/logs/'.$entry['execution_id'].'.stderr.log',
                'sha256' => hash_file('sha256', $stderrPath),
            ],
            'runner' => ['version' => 'rivals-native-runner-v2', 'mode' => 'execute'],
            'provider_binding' => null,
        ])->persist();

        $process = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-native-runner.php'),
            '--manifest='.$manifestPath,
            '--cwd='.base_path(),
            '--execution-id='.$entry['execution_id'],
            '--approve-provider-spend',
        ], base_path(), ['ATLAS_RIVALS2_STORAGE' => $this->storage]);
        $process->setTimeout(30);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $payload = json_decode($process->getOutput(), true);
        $this->assertSame('success', data_get($payload, 'executions.0.status'));
        $this->assertTrue(data_get($payload, 'executions.0.idempotent_replay'));
        $this->assertSame('{"already":"complete"}', file_get_contents($resultPath));
    }
}
