<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Models\AtlasDevRunIndex;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use RuntimeException;

final class AtlasDevRunWorkerCommandTest extends AtlasDevHttpTestCase
{
    public function test_worker_command_executes_persisted_plan_and_marks_run_complete(): void
    {
        $plan = $this->postPlan($this->defaultRepairPayload());
        $compactSdd = $this->app->make(ReceiptStorage::class)->read($plan['run_id'], ArtifactNames::COMPACT_SDD);
        $this->assertIsArray($compactSdd);
        $this->assertIsString($compactSdd['compact_sdd_hash']);

        $fake = FakeRunExecutor::passing();
        $this->app->instance(RunExecutor::class, $fake);

        $this->artisan('atlas:dev:run-worker', [
            'run_id' => $plan['run_id'],
            '--task-contract-hash' => $plan['hashes']['task_contract'],
            '--expected-compact-sdd-hash' => $compactSdd['compact_sdd_hash'],
        ])->assertExitCode(0);

        $this->assertCount(1, $fake->calls);
        $this->assertSame($plan['run_id'], $fake->calls[0]['run_id']);
        $this->assertSame($plan['hashes']['task_contract'], $fake->calls[0]['task_contract_hash']);
        $this->assertSame($compactSdd['compact_sdd_hash'], $fake->calls[0]['expected_compact_sdd_hash']);

        $storage = $this->app->make(ReceiptStorage::class);
        $this->assertSame(2, $storage->latestVersion($plan['run_id'], ArtifactNames::RUN_EXECUTION_STATE_BASE));

        $latestState = $storage->readLatestVersion($plan['run_id'], ArtifactNames::RUN_EXECUTION_STATE_BASE);
        $this->assertIsArray($latestState);
        $this->assertSame('complete', $latestState['status']);
        $this->assertSame('passed', $latestState['completion_state']);
        $this->assertSame($plan['hashes']['task_contract'], $latestState['task_contract_hash']);

        $index = AtlasDevRunIndex::query()->find($plan['run_id']);
        $this->assertNotNull($index);
        $this->assertSame('passed', $index->completion_state);
        $this->assertSame(str_repeat('a', 64), $index->last_receipt_hash);

        $show = $this->withHeaders($this->headers)->getJson('/ai/interactions/atlas-dev/runs/'.$plan['run_id']);
        $show->assertStatus(200);
        $show->assertJsonPath('data.state', 'complete');
        $show->assertJsonPath('data.run_execution.status', 'complete');
        $show->assertJsonPath('data.run_execution.completion_state', 'passed');
    }

    public function test_worker_command_marks_run_failed_when_executor_throws(): void
    {
        $plan = $this->postPlan($this->defaultRepairPayload());
        $this->app->instance(RunExecutor::class, new ThrowingRunWorkerExecutor);

        $this->artisan('atlas:dev:run-worker', [
            'run_id' => $plan['run_id'],
            '--task-contract-hash' => $plan['hashes']['task_contract'],
        ])->assertExitCode(1);

        $storage = $this->app->make(ReceiptStorage::class);
        $this->assertSame(2, $storage->latestVersion($plan['run_id'], ArtifactNames::RUN_EXECUTION_STATE_BASE));

        $latestState = $storage->readLatestVersion($plan['run_id'], ArtifactNames::RUN_EXECUTION_STATE_BASE);
        $this->assertIsArray($latestState);
        $this->assertSame('failed', $latestState['status']);
        $this->assertSame('ATLAS_DEV_RUN_WORKER_FAILED', $latestState['error_code']);
        $this->assertSame($plan['hashes']['task_contract'], $latestState['task_contract_hash']);
        $this->assertStringNotContainsString('/tmp/', (string) ($latestState['message'] ?? ''));

        $index = AtlasDevRunIndex::query()->find($plan['run_id']);
        $this->assertNotNull($index);
        $this->assertSame('failed', $index->completion_state);

        $show = $this->withHeaders($this->headers)->getJson('/ai/interactions/atlas-dev/runs/'.$plan['run_id']);
        $show->assertStatus(200);
        $show->assertJsonPath('data.state', 'complete');
        $show->assertJsonPath('data.completion_state', 'failed');
        $show->assertJsonPath('data.run_execution.status', 'failed');
    }

    public function test_worker_command_installs_sigterm_handler_for_operator_cancellation(): void
    {
        $source = file_get_contents(app_path('Console/Commands/AtlasDevRunWorkerCommand.php'));
        $this->assertIsString($source);

        $this->assertStringContainsString('pcntl_async_signals(true)', $source);
        $this->assertStringContainsString('pcntl_signal($signal', $source);
        $this->assertStringContainsString('posix_setsid()', $source);
        $this->assertStringContainsString('\'process_group_id\' => $processGroupId', $source);
        $this->assertStringContainsString('recordRunState($runId, \'cancelled\'', $source);
        $this->assertStringContainsString("'signal' => 'SIGTERM'", $source);
        $this->assertStringContainsString('updateCompletion($runId, \'cancelled\')', $source);
    }
}

final class ThrowingRunWorkerExecutor implements RunExecutor
{
    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        ?string $expectedCompactSddHash = null,
    ): RunExecutionResult {
        throw new RuntimeException('worker failed while reading /tmp/atlas-dev-secret/path.txt');
    }
}
