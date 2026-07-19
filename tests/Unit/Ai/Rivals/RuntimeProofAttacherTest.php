<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\External\HarborTerminalBenchAdapter;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Core\NativeExecutionReceipt;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\RuntimeProofAttacher;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RuntimeProofAttacherTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_runtime_proof_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_bare_proof_requires_execute_mode_and_real_usage(): void
    {
        [$plan, $manifest, $binding, $entry] = $this->manifest('bare');
        $this->persistNativeReceipt($plan, $manifest, $entry, 'execute');
        $receipt = $this->receipt($binding);

        $attached = (new RuntimeProofAttacher)->attach(
            $receipt,
            $binding,
            $plan->runId(),
            $entry['expected_result_path'],
        );

        $this->assertTrue(data_get($attached, 'metadata.direct_provider.real_provider'));
        $this->assertSame('verboo', data_get($attached, 'metadata.direct_provider.provider'));
        $this->assertSame('kimi-k2.7', data_get($attached, 'metadata.direct_provider.model'));
    }

    public function test_atlas_proof_is_fail_closed_then_accepts_exact_fair_bridge(): void
    {
        [$plan, $manifest, $binding, $entry] = $this->manifest('atlas_dev');
        $this->persistNativeReceipt($plan, $manifest, $entry, 'execute');
        $attacher = new RuntimeProofAttacher;
        $missing = $attacher->attach(
            $this->receipt($binding),
            $binding,
            $plan->runId(),
            $entry['expected_result_path'],
        );
        $this->assertFalse(data_get($missing, 'metadata.runtime_bridge.real_provider'));
        $this->assertSame(
            \App\Services\Ai\Rivals\Core\FailureClass::ENVIRONMENT,
            $missing['failure_class'] ?? null,
        );

        File::ensureDirectoryExists((string) data_get($entry, 'normalization.scratch_dir'));
        file_put_contents(
            data_get($entry, 'normalization.scratch_dir').'/.rivals_atlas_dev_bridge.json',
            json_encode([
                'schema_version' => 'atlas.rivals2.atlas_dev_bridge_receipt.v1',
                'status' => 'passed',
                'real_provider' => true,
                'provider' => 'hermes_cli',
                'model' => 'kimi-k2.7',
                'fair_mode' => [
                    'single_provider' => true,
                    'decide_disabled' => true,
                    'fallback_disabled' => true,
                ],
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 20,
                    'cost_usd' => 0.0,
                    'present' => true,
                ],
            ]),
        );
        $attached = $attacher->attach(
            $this->receipt($binding),
            $binding,
            $plan->runId(),
            $entry['expected_result_path'],
        );
        $this->assertTrue(data_get($attached, 'metadata.runtime_bridge.real_provider'));
        $this->assertSame('hermes_cli', data_get($attached, 'metadata.runtime_bridge.provider'));
    }

    public function test_atlas_that_responded_and_failed_the_task_is_measured_not_discarded(): void
    {
        [$plan, $manifest, $binding, $entry] = $this->manifest('atlas_dev');
        $this->persistNativeReceipt($plan, $manifest, $entry, 'execute');
        File::ensureDirectoryExists((string) data_get($entry, 'normalization.scratch_dir'));
        file_put_contents(
            data_get($entry, 'normalization.scratch_dir').'/.rivals_atlas_dev_bridge.json',
            (string) json_encode([
                'schema_version' => 'atlas.rivals2.atlas_dev_bridge_receipt.v1',
                'status' => 'passed',
                'real_provider' => true,
                'provider' => 'hermes_cli',
                'model' => 'kimi-k2.7',
                'fair_mode' => [
                    'single_provider' => true,
                    'decide_disabled' => true,
                    'fallback_disabled' => true,
                ],
                // O provider respondeu, mas o Atlas bloqueou/rejeitou a tarefa.
                // Isto é uma derrota medida, não uma falha de ambiente.
                'task_ok' => false,
                'completion_state' => 'blocked',
                'provider_call' => [
                    'provider_calls' => 1,
                    'error_codes' => [
                        'candidate_preparation_blocked:sandbox_sandbox_git_clone_failed',
                    ],
                ],
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 20,
                    'cost_usd' => 0.0,
                    'present' => true,
                ],
            ]),
        );

        $receipt = $this->receipt($binding) + [
            'status' => 'failure',
            'failure_class' => 'model_failure',
            'failure_reason' => 'swe_bench_live:benchmark_verdict_not_resolved',
        ];
        $attached = (new RuntimeProofAttacher)->attach(
            $receipt,
            $binding,
            $plan->runId(),
            $entry['expected_result_path'],
        );

        $this->assertTrue(
            data_get($attached, 'metadata.runtime_bridge.real_provider'),
            'o Atlas rodou — errar a tarefa não desprova a execução',
        );
        $this->assertNotSame(
            \App\Services\Ai\Rivals\Core\FailureClass::ENVIRONMENT,
            $attached['failure_class'] ?? null,
            'derrota do Atlas é medição, não falha de ambiente — tem de contar no denominador',
        );
        $this->assertSame(
            'terminal_bench:atlas_bridge_error_codes=candidate_preparation_blocked:sandbox_sandbox_git_clone_failed',
            $attached['failure_reason'] ?? null,
        );
    }

    public function test_atlas_attempt_without_usage_is_not_a_proven_provider_response(): void
    {
        // Evidência real de 19/07: provider_calls=1 era contador de tentativa.
        // O recibo também dizia provider_unavailable, tokens null e patch=0.
        // Aceitá-lo mede uma ausência de resposta como se fosse o Atlas.
        [$plan, $manifest, $binding, $entry] = $this->manifest('atlas_dev');
        $this->persistNativeReceipt($plan, $manifest, $entry, 'execute');
        File::ensureDirectoryExists((string) data_get($entry, 'normalization.scratch_dir'));
        file_put_contents(
            data_get($entry, 'normalization.scratch_dir').'/.rivals_atlas_dev_bridge.json',
            (string) json_encode([
                'schema_version' => 'atlas.rivals2.atlas_dev_bridge_receipt.v1',
                'status' => 'passed',
                'real_provider' => true,
                'provider' => 'hermes_cli',
                'model' => 'kimi-k2.7',
                'fair_mode' => [
                    'single_provider' => true,
                    'decide_disabled' => true,
                    'fallback_disabled' => true,
                ],
                'provider_call' => [
                    'provider_calls' => 1,
                    'error_codes' => ['candidate_preparation_blocked:provider_unavailable'],
                ],
                'usage' => [
                    'input_tokens' => null,
                    'output_tokens' => null,
                    'cost_usd' => null,
                    'present' => false,
                ],
            ]),
        );

        $attached = (new RuntimeProofAttacher)->attach(
            $this->receipt($binding),
            $binding,
            $plan->runId(),
            $entry['expected_result_path'],
        );

        $this->assertFalse(data_get($attached, 'metadata.runtime_bridge.real_provider'));
        $this->assertSame(
            \App\Services\Ai\Rivals\Core\FailureClass::ENVIRONMENT,
            $attached['failure_class'] ?? null,
        );
        $this->assertSame(
            'atlas_dev_runtime_proof_usage_missing',
            $attached['failure_reason'] ?? null,
        );
    }

    /** @return array{RunPlan, NativeExecutionManifest, array<string,mixed>, array<string,mixed>} */
    private function manifest(string $runtime): array
    {
        // tau2 não tem runtime atlas_dev (fail-fast pré-spend); terminal_bench tem.
        $adapter = new HarborTerminalBenchAdapter;
        $binding = (new ArmRegistry)->parse('verboo_kimi_k2_7@'.$runtime, $adapter->suiteId());
        $plan = RunPlan::make(
            $adapter->suiteId(),
            ['tb_hello'],
            [$binding],
            1,
            ['max_usd' => 0.0, 'max_minutes' => 5],
            1,
        );
        $data = $plan->data;
        $data['environment']['approve_provider_spend'] = true;
        $plan = RunPlan::fromArray($data);
        $plan->persist();
        $manifest = NativeExecutionManifest::fromPlan(
            $plan,
            $adapter,
            $adapter->planCommands($plan),
        );
        $manifest->persist();

        return [$plan, $manifest, $binding, $manifest->entries()[0]];
    }

    private function persistNativeReceipt(
        RunPlan $plan,
        NativeExecutionManifest $manifest,
        array $entry,
        string $mode,
    ): void {
        $resultPath = RunPaths::runDir($plan->runId()).'/'.$entry['expected_result_path'];
        File::ensureDirectoryExists(dirname($resultPath));
        file_put_contents($resultPath, '{}');
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
                'sha256' => hash('sha256', 'stdout'),
            ],
            'stderr' => [
                'present' => true,
                'path' => 'native_execution_receipts/logs/'.$entry['execution_id'].'.stderr.log',
                'sha256' => hash('sha256', ''),
            ],
            'runner' => ['version' => 'rivals-native-runner-v1', 'mode' => $mode],
            'provider_binding' => [
                'provider' => 'verboo',
                'model_id' => $entry['model_id'],
                'atlas_cli_model' => $entry['atlas_cli_model'],
                'native_model' => $entry['native_model'],
                'base_url_sha256' => hash(
                    'sha256',
                    \App\Services\Ai\Rivals\Core\VerbooEnvironment::BASE_URL,
                ),
            ],
        ])->persist();
    }

    /** @param array<string,mixed> $binding */
    private function receipt(array $binding): array
    {
        return [
            'arm_id' => $binding['arm_id'],
            'tokens_in' => 100,
            'tokens_out' => 20,
            'cost_usd' => 0.0,
            'metadata' => [],
        ];
    }
}
