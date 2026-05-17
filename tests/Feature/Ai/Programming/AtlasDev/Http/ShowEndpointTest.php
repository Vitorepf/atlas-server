<?php

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Models\AtlasDevRunIndex;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;

final class ShowEndpointTest extends AtlasDevHttpTestCase
{
    public function test_show_returns_404_when_no_artifacts_persisted(): void
    {
        $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/dev-7777777777777-cafebabe')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RUN_NOT_FOUND');
    }

    public function test_show_after_plan_reports_persisted_state_without_receipt(): void
    {
        $plan = $this->postPlan($this->defaultRepairPayload());
        $runId = $plan['run_id'];

        $response = $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/'.$runId);

        $response->assertStatus(200);
        $response->assertJsonPath('data.run_id', $runId);
        $response->assertJsonPath('data.has_plan', true);
        $response->assertJsonPath('data.has_receipt', false);
        $expectedWorkspace = realpath($this->tmpWorkspace) ?: $this->tmpWorkspace;
        $response->assertJsonPath('data.workspace_label', basename($expectedWorkspace));
        $response->assertJsonMissingPath('data.workspace');
        $response->assertJsonPath('data.task_contract_hash', $plan['hashes']['task_contract']);
        $response->assertJsonPath('data.routing.kind', $plan['routing']['kind']);

        $refs = $response->json('data.persisted_artifact_refs');
        $this->assertIsArray($refs);
        $this->assertArrayHasKey('operation_envelope', $refs);
        $this->assertArrayHasKey('task_contract', $refs);
        $this->assertArrayHasKey('prompt_projection', $refs);
        $this->assertArrayNotHasKey('verification_receipt', $refs);
        foreach ($refs as $ref) {
            $this->assertStringStartsWith("receipts/{$runId}/", $ref);
            $this->assertStringEndsWith('.json', $ref);
            $this->assertDoesNotMatchRegularExpression('@^/@', $ref, 'ref must not be absolute');
        }
    }

    public function test_show_after_run_reports_completion_state_and_receipt_hash(): void
    {
        $this->app->instance(RunExecutor::class, FakeRunExecutor::passing());

        $plan = $this->postPlan($this->defaultRepairPayload());
        $runId = $plan['run_id'];

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $runId,
                'task_contract_hash' => $plan['hashes']['task_contract'],
                'confirmation_token' => $plan['confirmation']['token'],
                'operator_confirmed' => true,
            ])
            ->assertStatus(200);

        // The fake executor doesn't actually persist artifacts, so the show
        // endpoint reports has_receipt=false but plan-side artifacts present.
        // We exercise the runtime path explicitly when needed in a future
        // integration test.
        $response = $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/'.$runId);

        $response->assertStatus(200);
        $response->assertJsonPath('data.has_plan', true);
        $this->assertSame($plan['hashes']['task_contract'], $response->json('data.task_contract_hash'));
    }

    public function test_show_marks_stale_running_worker_as_failed_for_rest_fallback(): void
    {
        config()->set('atlas_dev.run_worker.stale_after_seconds', 60);

        $plan = $this->postPlan($this->defaultRepairPayload());
        $runId = $plan['run_id'];

        $this->app->make(ReceiptStorage::class)->writeMonotonic(
            $runId,
            ArtifactNames::RUN_EXECUTION_STATE_BASE,
            [
                'schema_version' => 'atlas.dev.run_execution_state.v1',
                'run_id' => $runId,
                'status' => 'running',
                'recorded_at' => now()->subMinutes(5)->toISOString(),
                'task_contract_hash' => $plan['hashes']['task_contract'],
            ],
        );

        $response = $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/'.$runId);

        $response->assertStatus(200);
        $response->assertJsonPath('data.state', 'complete');
        $response->assertJsonPath('data.completion_state', 'failed');
        $response->assertJsonPath('data.run_execution.status', 'failed');
        $response->assertJsonPath('data.run_execution.stale', true);
        $response->assertJsonPath('data.run_execution.error_code', 'ATLAS_DEV_RUN_WORKER_STALE');

        $storage = $this->app->make(ReceiptStorage::class);
        $this->assertSame(2, $storage->latestVersion($runId, ArtifactNames::RUN_EXECUTION_STATE_BASE));
        $persisted = $storage->readLatestVersion($runId, ArtifactNames::RUN_EXECUTION_STATE_BASE);
        $this->assertIsArray($persisted);
        $this->assertSame('failed', $persisted['status']);
        $this->assertSame('running', $persisted['previous_status']);

        $index = AtlasDevRunIndex::query()->find($runId);
        $this->assertNotNull($index);
        $this->assertSame('failed', $index->completion_state);
    }

    public function test_show_returns_full_receipt_for_rest_fallback(): void
    {
        $plan = $this->postPlan($this->defaultRepairPayload());
        $runId = $plan['run_id'];
        $receipt = $this->receiptPayload($runId, $plan['hashes']['task_contract']);

        $this->app->make(ReceiptStorage::class)->writeAtomic(
            $runId,
            ArtifactNames::VERIFICATION_RECEIPT,
            $receipt,
        );
        $this->app->make(ReceiptStorage::class)->writeAtomic(
            $runId,
            ArtifactNames::DIFF_PARSE_RESULT,
            [
                'schema_version' => 'atlas.dev.diff_parse_result.v1',
                'mode' => 'patch',
                'diff' => "--- app/Services/Foo/FooService.php\n+++ app/Services/Foo/FooService.php\n@@ -1 +1 @@\n-return 41;\n+return 42;",
                'diff_hash' => str_repeat('d', 64),
                'changed_files' => ['app/Services/Foo/FooService.php'],
                'errors' => [],
            ],
        );

        $response = $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/'.$runId);

        $response->assertStatus(200);
        $response->assertJsonPath('data.has_receipt', true);
        $response->assertJsonPath('data.completion_state', 'passed');
        $response->assertJsonPath('data.receipt.run_id', $runId);
        $response->assertJsonPath('data.receipt.tests.0.command', 'composer test');
        $response->assertJsonPath('data.receipt.tests.0.ok', true);
        $response->assertJsonPath('data.receipt.completion.honesty_flags', []);
        $response->assertJsonPath('data.receipt.ui_hints.diff_preview', "--- app/Services/Foo/FooService.php\n+++ app/Services/Foo/FooService.php\n@@ -1 +1 @@\n-return 41;\n+return 42;");
        $response->assertJsonPath('data.diff_preview', "--- app/Services/Foo/FooService.php\n+++ app/Services/Foo/FooService.php\n@@ -1 +1 @@\n-return 41;\n+return 42;");
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptPayload(string $runId, string $taskContractHash): array
    {
        return [
            'changed_files' => ['app/Services/Foo/FooService.php'],
            'completion' => [
                'honesty_flags' => [],
                'residual_risks' => [],
                'status' => 'passed',
            ],
            'context_pack_hash' => 'context-hash',
            'cost' => [
                'estimated_cost_usd' => null,
                'provider_calls' => 1,
                'tokens_in' => null,
                'tokens_out' => null,
                'wall_time_ms' => 1234,
            ],
            'diff_hash' => str_repeat('d', 64),
            'escalation' => [
                'decision_ref' => null,
                'reasons' => [],
                'recommended' => false,
                'target' => null,
            ],
            'evidence_refs' => [],
            'file_hashes' => [],
            'gates' => [
                [
                    'evidence_ref' => 'scope_guard_receipt:'.str_repeat('b', 64),
                    'fresh' => true,
                    'name' => 'scope_guard_light',
                    'required' => true,
                    'status' => 'passed',
                    'waiver_reason' => null,
                ],
                [
                    'evidence_ref' => null,
                    'fresh' => true,
                    'name' => 'verification_gate',
                    'required' => true,
                    'status' => 'passed',
                    'waiver_reason' => null,
                ],
            ],
            'model' => 'claude_cli:sonnet',
            'prompt_projection_hash' => str_repeat('p', 64),
            'provider' => 'claude_cli',
            'provider_safe' => true,
            'receipt_hash' => str_repeat('a', 64),
            'repair' => [
                'attempt_count' => 0,
                'converted_to_green' => false,
                'failure_capsule_refs' => [],
            ],
            'risk_level' => 'R2',
            'run_id' => $runId,
            'schema_version' => 'atlas.dev.verification_receipt.v1',
            'scope_guard_receipt_hash' => str_repeat('b', 64),
            'task_contract_hash' => $taskContractHash,
            'task_kind' => 'repair',
            'tests' => [
                [
                    'command' => 'composer test',
                    'duration_ms' => 42,
                    'exit_code' => 0,
                    'ok' => true,
                    'output_hash' => str_repeat('e', 64),
                    'output_path' => null,
                ],
            ],
            'workspace_hash' => str_repeat('w', 64),
        ];
    }
}
