<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\External\Tau2BenchAdapter;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NativeExecutionManifestTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_manifest_test_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_manifest_has_one_unique_hashed_entry_per_case_arm_repetition(): void
    {
        $adapter = new Tau2BenchAdapter;
        $arm = (new ArmRegistry)->parse('claude_sonnet_5@bare', $adapter->suiteId());
        $plan = RunPlan::make(
            $adapter->suiteId(),
            ['airline_task_012', 'airline_task_013'],
            [$arm],
            3,
            ['max_usd' => 6.0, 'max_minutes' => 30],
            42,
        );
        $plan->persist();
        $commands = $adapter->planCommands($plan);
        $manifest = NativeExecutionManifest::fromPlan($plan, $adapter, $commands);
        $path = $manifest->persist();

        $this->assertFileExists($path);
        $this->assertCount(6, $manifest->entries());
        $this->assertCount(6, array_unique(array_column($manifest->entries(), 'execution_id')));
        $this->assertCount(6, array_unique(array_column($manifest->entries(), 'expected_result_path')));
        $this->assertSame(
            $manifest->hash(),
            NativeExecutionManifest::load($plan->runId())->hash(),
        );
        foreach ($manifest->entries() as $entry) {
            $this->assertStringStartsWith('external_results/units/', $entry['expected_result_path']);
            $this->assertSame(1.0, $entry['max_usd']);
        }
        $this->assertDirectoryExists(RunPaths::nativeResultsDir($plan->runId()));

        $dryRun = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-native-runner.php'),
            '--manifest='.$path,
            '--cwd='.base_path(),
            '--dry-run',
        ], base_path());
        $dryRun->run();
        $this->assertTrue($dryRun->isSuccessful(), $dryRun->getErrorOutput());
        $payload = json_decode($dryRun->getOutput(), true);
        $this->assertCount(6, $payload['executions'] ?? []);
    }

    public function test_manifest_tamper_fails_closed(): void
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
        $plan->persist();
        $manifest = NativeExecutionManifest::fromPlan($plan, $adapter, $adapter->planCommands($plan));
        $data = $manifest->data;
        $data['entries'][0]['case_id'] = 'tampered';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rivals_native_manifest_hash_mismatch');
        NativeExecutionManifest::fromArray($data);
    }
}
