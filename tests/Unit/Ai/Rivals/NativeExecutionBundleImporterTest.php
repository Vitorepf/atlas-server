<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\External\Tau2BenchAdapter;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\NativeExecutionBundleImporter;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Core\NativeExecutionReceipt;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class NativeExecutionBundleImporterTest extends TestCase
{
    private string $storage;

    private string $bundle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_bundle_import_test_'.uniqid();
        $this->bundle = sys_get_temp_dir().'/rivals_native_bundle_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        File::deleteDirectory($this->bundle);
        parent::tearDown();
    }

    public function test_production_bundle_is_bound_to_manifest_hashes(): void
    {
        [$plan, $manifest] = $this->manifest();
        $entry = $manifest->entries()[0];
        $sourceResult = $this->bundle.'/'.$entry['expected_result_path'];
        File::ensureDirectoryExists(dirname($sourceResult));
        file_put_contents($sourceResult, json_encode(['native' => 'result']));
        $receipt = $this->receipt($plan->runId(), $manifest, $entry, $sourceResult);
        File::ensureDirectoryExists($this->bundle.'/native_execution_receipts');
        file_put_contents(
            $this->bundle.'/native_execution_receipts/'.$entry['execution_id'].'.json',
            json_encode($receipt->data),
        );

        $summary = (new NativeExecutionBundleImporter)->import(
            $plan->runId(),
            'tau2_bench',
            $this->bundle,
        );

        $this->assertSame('manifest_bound', $summary['mode']);
        $this->assertSame(1, $summary['results_imported']);
        $this->assertFileExists(
            RunPaths::runDir($plan->runId()).'/'.$entry['expected_result_path']
        );
        $this->assertCount(1, NativeExecutionReceipt::loadAll($plan->runId()));
    }

    public function test_tampered_result_is_rejected_before_copy(): void
    {
        [$plan, $manifest] = $this->manifest();
        $entry = $manifest->entries()[0];
        $sourceResult = $this->bundle.'/'.$entry['expected_result_path'];
        File::ensureDirectoryExists(dirname($sourceResult));
        file_put_contents($sourceResult, json_encode(['native' => 'result']));
        $receipt = $this->receipt($plan->runId(), $manifest, $entry, $sourceResult);
        File::ensureDirectoryExists($this->bundle.'/native_execution_receipts');
        file_put_contents(
            $this->bundle.'/native_execution_receipts/'.$entry['execution_id'].'.json',
            json_encode($receipt->data),
        );
        file_put_contents($sourceResult, json_encode(['native' => 'tampered']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rivals_native_result_hash_mismatch');
        (new NativeExecutionBundleImporter)->import(
            $plan->runId(),
            'tau2_bench',
            $this->bundle,
        );
    }

    /** @return array{RunPlan, NativeExecutionManifest} */
    private function manifest(): array
    {
        $adapter = new Tau2BenchAdapter;
        $plan = RunPlan::make(
            $adapter->suiteId(),
            ['airline_task_012'],
            [(new ArmRegistry)->parse('claude_sonnet_5@bare', $adapter->suiteId())],
            1,
            ['max_usd' => 1.0, 'max_minutes' => 5],
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

        return [$plan, $manifest];
    }

    private function receipt(
        string $runId,
        NativeExecutionManifest $manifest,
        array $entry,
        string $resultPath,
    ): NativeExecutionReceipt {
        return NativeExecutionReceipt::fromArray([
            'schema_version' => NativeExecutionReceipt::SCHEMA,
            'run_id' => $runId,
            'execution_id' => $entry['execution_id'],
            'manifest_hash' => $manifest->hash(),
            'command_hash' => $entry['command_hash'],
            'expected_result_path' => $entry['expected_result_path'],
            'result_sha256' => hash_file('sha256', $resultPath),
            'status' => 'success',
            'exit_code' => 0,
            'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'wall_ms' => 1,
            'cost_usd' => 0.01,
            'stdout' => ['present' => false, 'sha256' => null],
            'stderr' => ['present' => false, 'sha256' => null],
            'runner' => ['version' => 'test'],
        ]);
    }
}
