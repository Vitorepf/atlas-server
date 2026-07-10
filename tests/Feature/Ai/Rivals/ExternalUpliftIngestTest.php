<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\External\BfclAdapter;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Core\NativeExecutionReceipt;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ExternalUpliftIngestTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_external_uplift_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
        File::copyDirectory(
            base_path('tests/Fixtures/Rivals/cases/bfcl'),
            RunPaths::root().'/external/bfcl/cases',
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_manifest_path_binds_same_native_model_to_distinct_runtime_arms(): void
    {
        $adapter = new BfclAdapter;
        $plan = RunPlan::make(
            'bfcl',
            ['simple'],
            [
                (new ArmRegistry)->parse('verboo_kimi_k2_7@bare', 'bfcl'),
                (new ArmRegistry)->parse('verboo_kimi_k2_7@atlas_dev', 'bfcl'),
            ],
            1,
            ['max_usd' => 0.0, 'max_minutes' => 30],
            42,
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

        foreach ($manifest->entries() as $entry) {
            $resultPath = RunPaths::runDir($plan->runId()).'/'.$entry['expected_result_path'];
            File::ensureDirectoryExists(dirname($resultPath));
            file_put_contents($resultPath, json_encode([
                'model' => 'kimi-k2.7-FC',
                'results' => [[
                    'test_category' => 'simple',
                    'case_id' => 'simple',
                    'run' => 1,
                    'accuracy' => 1.0,
                    'status' => 'success',
                    'duration_sec' => 1.0,
                    'tokens_in' => 100,
                    'tokens_out' => 20,
                    'cost_usd' => 0.0,
                    'field_presence' => [
                        'cost_usd' => ['present' => true, 'reason' => 'verboo_subscription_marginal'],
                    ],
                ]],
            ]));
            NativeExecutionReceipt::fromArray([
                'schema_version' => NativeExecutionReceipt::SCHEMA,
                'run_id' => $plan->runId(),
                'execution_id' => $entry['execution_id'],
                'manifest_hash' => $manifest->hash(),
                'command_hash' => $entry['command_hash'],
                'expected_result_path' => $entry['expected_result_path'],
                'result_sha256' => hash_file('sha256', $resultPath),
                'status' => 'success',
                'exit_code' => 0,
                'started_at' => now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'wall_ms' => 1000,
                'cost_usd' => 0.0,
                'stdout' => ['present' => true, 'sha256' => hash('sha256', 'stdout')],
                'stderr' => ['present' => true, 'sha256' => hash('sha256', '')],
                'runner' => ['version' => 'rivals-native-runner-v1', 'mode' => 'execute'],
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
            if ($entry['runtime'] === 'atlas_dev') {
                File::ensureDirectoryExists(data_get($entry, 'normalization.scratch_dir'));
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
            }
        }

        $receipts = $adapter->ingestResults(RunPaths::runDir($plan->runId()), $plan);
        $this->assertSame(
            ['verboo_kimi_k2_7@atlas_dev', 'verboo_kimi_k2_7@bare'],
            collect($receipts)->map(fn ($receipt) => $receipt->data['arm_id'])->sort()->values()->all(),
        );
        $byArm = collect($receipts)->keyBy(fn ($receipt) => $receipt->data['arm_id']);
        $this->assertTrue(data_get(
            $byArm['verboo_kimi_k2_7@bare']->data,
            'metadata.direct_provider.real_provider',
        ));
        $this->assertTrue(data_get(
            $byArm['verboo_kimi_k2_7@atlas_dev']->data,
            'metadata.runtime_bridge.real_provider',
        ));
    }
}
