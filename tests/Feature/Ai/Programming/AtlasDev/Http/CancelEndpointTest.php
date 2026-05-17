<?php

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use Illuminate\Support\Facades\Artisan;

final class CancelEndpointTest extends AtlasDevHttpTestCase
{
    public function test_cancel_persists_operator_cancellation_and_show_reports_cancelled(): void
    {
        $plan = $this->postPlan($this->defaultRepairPayload());
        $runId = $plan['run_id'];

        $this->app->make(ReceiptStorage::class)->writeMonotonic(
            $runId,
            ArtifactNames::RUN_EXECUTION_STATE_BASE,
            [
                'schema_version' => 'atlas.dev.run_execution_state.v1',
                'run_id' => $runId,
                'status' => 'running',
                'recorded_at' => now()->toISOString(),
                'worker_pid' => 999998,
                'process_group_id' => 999999,
                'task_contract_hash' => $plan['hashes']['task_contract'],
            ],
        );

        $this->withHeaders($this->headers)
            ->postJson("/ai/interactions/atlas-dev/runs/{$runId}/cancel", [
                'reason' => 'operator changed scope',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.run_id', $runId)
            ->assertJsonPath('data.state', 'complete')
            ->assertJsonPath('data.completion_state', 'cancelled')
            ->assertJsonPath('data.reason', 'operator changed scope')
            ->assertJsonPath('data.worker_pid', 999998)
            ->assertJsonPath('data.process_group_id', 999999)
            ->assertJsonPath('data.signal_sent', false)
            ->assertJsonPath('data.signal_target', 'process_group')
            ->assertJsonPath('data.persisted_artifact_refs.run_cancellation', "receipts/{$runId}/run_cancellation.json");

        $this->assertTrue($this->app->make(ReceiptStorage::class)->exists($runId, ArtifactNames::RUN_CANCELLATION));

        $this->withHeaders($this->headers)
            ->get("/ai/interactions/atlas-dev/runs/{$runId}")
            ->assertStatus(200)
            ->assertJsonPath('data.state', 'complete')
            ->assertJsonPath('data.completion_state', 'cancelled')
            ->assertJsonPath('data.run_execution.status', 'cancelled')
            ->assertJsonPath('data.persisted_artifact_refs.run_cancellation', "receipts/{$runId}/run_cancellation.json");
    }

    public function test_cancel_is_idempotent_and_preserves_first_reason(): void
    {
        $plan = $this->postPlan($this->defaultRepairPayload());
        $runId = $plan['run_id'];

        $this->withHeaders($this->headers)
            ->postJson("/ai/interactions/atlas-dev/runs/{$runId}/cancel", ['reason' => 'first'])
            ->assertStatus(200)
            ->assertJsonPath('data.reason', 'first');

        $this->withHeaders($this->headers)
            ->postJson("/ai/interactions/atlas-dev/runs/{$runId}/cancel", ['reason' => 'second'])
            ->assertStatus(200)
            ->assertJsonPath('data.reason', 'first');
    }

    public function test_worker_exits_before_provider_call_when_cancelled(): void
    {
        $fakeExecutor = FakeRunExecutor::passing();
        $this->app->instance(RunExecutor::class, $fakeExecutor);

        $plan = $this->postPlan($this->defaultRepairPayload());
        $runId = $plan['run_id'];

        $this->withHeaders($this->headers)
            ->postJson("/ai/interactions/atlas-dev/runs/{$runId}/cancel", ['reason' => 'cancel before start'])
            ->assertStatus(200);

        $exitCode = Artisan::call('atlas:dev:run-worker', [
            'run_id' => $runId,
            '--task-contract-hash' => $plan['hashes']['task_contract'],
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertCount(0, $fakeExecutor->calls);

        $latest = $this->app->make(ReceiptStorage::class)
            ->readLatestVersion($runId, ArtifactNames::RUN_EXECUTION_STATE_BASE);

        $this->assertIsArray($latest);
        $this->assertSame('cancelled', $latest['status']);
    }
}
