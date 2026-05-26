<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Runtime\RunWorkerDispatcher;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;

final class DesktopOperationalSmokeTest extends AtlasDevHttpTestCase
{
    public function test_desktop_plan_process_run_worker_show_stream_and_history_resume_contract(): void
    {
        config()->set('atlas_dev.efficient.desktop_enabled', true);
        config()->set('atlas_dev.efficient.run_dispatch_mode', 'process');

        $dispatcher = new DesktopSmokeRunWorkerDispatcher(pid: 9898);
        $this->app->instance(RunWorkerDispatcher::class, $dispatcher);

        $plan = $this->postPlan([
            ...$this->defaultRepairPayload(),
            'surface_id' => 'atlas_desktop_ai',
            'surface_context' => ['thread_id' => 'desktop-thread-1'],
        ]);

        $runId = (string) $plan['run_id'];
        $taskContractHash = (string) $plan['hashes']['task_contract'];
        $workspaceHash = (string) $plan['workspace_hash'];
        $confirmationToken = (string) $plan['confirmation']['token'];
        $compactSdd = $this->app->make(ReceiptStorage::class)->read($runId, ArtifactNames::COMPACT_SDD);
        $this->assertIsArray($compactSdd);
        $this->assertSame('atlas_desktop_ai', $plan['surface_id']);
        $this->assertSame('desktop-thread-1', $plan['thread_id']);
        $this->assertSame('atlas_dev_fast_path', $plan['routing']['kind']);

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $runId,
                'task_contract_hash' => $taskContractHash,
                'confirmation_token' => $confirmationToken,
                'operator_confirmed' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.state', 'queued')
            ->assertJsonPath('data.dispatch_mode', 'process')
            ->assertJsonPath('data.worker_pid', 9898);

        $this->assertSame($runId, $dispatcher->calls[0]['run_id'] ?? null);
        $this->assertSame($taskContractHash, $dispatcher->calls[0]['task_contract_hash'] ?? null);
        $this->assertSame($compactSdd['compact_sdd_hash'], $dispatcher->calls[0]['expected_compact_sdd_hash'] ?? null);

        $stream = $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/'.$runId.'/stream');
        $stream->assertStatus(200);
        $this->assertSame('snapshot-replay-then-close', $stream->headers->get('X-Atlas-Stream-Mode'));
        $streamBody = (string) $stream->streamedContent();
        $this->assertStringContainsString('event: phase', $streamBody);
        $this->assertStringContainsString('event: stream_closed', $streamBody);

        $this->app->instance(RunExecutor::class, new PersistingDesktopSmokeExecutor(
            storage: $this->app->make(ReceiptStorage::class),
            workspaceHash: $workspaceHash,
        ));

        $this->artisan('atlas:dev:run-worker', [
            'run_id' => $runId,
            '--task-contract-hash' => $taskContractHash,
            '--expected-compact-sdd-hash' => (string) $compactSdd['compact_sdd_hash'],
        ])->assertExitCode(0);

        $show = $this->withHeaders($this->headers)
            ->getJson('/ai/interactions/atlas-dev/runs/'.$runId)
            ->assertStatus(200)
            ->assertJsonPath('data.state', 'complete')
            ->assertJsonPath('data.has_receipt', true)
            ->assertJsonPath('data.completion_state', 'passed')
            ->assertJsonPath('data.receipt.run_id', $runId)
            ->assertJsonPath('data.receipt.completion.status', 'passed')
            ->assertJsonPath('data.receipt.tests.0.ok', true)
            ->assertJsonPath('data.run_execution.status', 'complete')
            ->assertJsonPath('data.run_execution.completion_state', 'passed');

        $index = $this->withHeaders($this->headers)
            ->getJson('/ai/interactions/atlas-dev/runs?workspace_hash='.$workspaceHash.'&thread_id=desktop-thread-1&limit=5')
            ->assertStatus(200)
            ->assertJsonPath('data.workspace_hash', $workspaceHash)
            ->assertJsonPath('data.thread_id', 'desktop-thread-1')
            ->assertJsonPath('data.items.0.run_id', $runId)
            ->assertJsonPath('data.items.0.surface_id', 'atlas_desktop_ai')
            ->assertJsonPath('data.items.0.completion_state', 'passed')
            ->assertJsonPath('data.items.0.last_receipt_hash', str_repeat('a', 64));

        foreach ([$show->getContent(), $index->getContent()] as $body) {
            $this->assertIsString($body);
            $this->assertStringNotContainsString($this->tmpWorkspace, $body);
            $this->assertStringNotContainsString($this->tmpStorage, $body);
            $this->assertStringNotContainsString('/Users/', $body);
            $this->assertStringNotContainsString('/private/var/', $body);
            $this->assertStringNotContainsString('/var/folders/', $body);
        }
    }
}

final class PersistingDesktopSmokeExecutor implements RunExecutor
{
    public function __construct(
        private readonly ReceiptStorage $storage,
        private readonly string $workspaceHash,
    ) {}

    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        ?string $expectedCompactSddHash = null,
    ): RunExecutionResult {
        $diffHash = str_repeat('d', 64);
        $receiptHash = str_repeat('a', 64);
        $scopeHash = str_repeat('b', 64);

        $this->storage->writeAtomic($runId, ArtifactNames::DIFF_PARSE_RESULT, [
            'schema_version' => 'atlas.dev.diff_parse_result.v1',
            'mode' => 'patch',
            'diff' => "--- app/Services/Foo/FooService.php\n+++ app/Services/Foo/FooService.php\n@@ -1 +1 @@\n-class FooService {}\n+class FooService { public function ok(): bool { return true; } }",
            'diff_hash' => $diffHash,
            'changed_files' => ['app/Services/Foo/FooService.php'],
            'errors' => [],
        ]);

        $this->storage->writeAtomic($runId, ArtifactNames::VERIFICATION_RECEIPT, [
            'schema_version' => 'atlas.dev.verification_receipt.v1',
            'run_id' => $runId,
            'task_contract_hash' => $taskContract->taskContractHash,
            'task_kind' => 'repair',
            'risk_level' => 'R2',
            'workspace_hash' => $this->workspaceHash,
            'provider' => 'claude_cli',
            'model' => 'claude_cli:sonnet',
            'provider_safe' => true,
            'prompt_projection_hash' => $promptProjection->promptProjectionHash,
            'context_pack_hash' => 'context-hash',
            'diff_hash' => $diffHash,
            'changed_files' => ['app/Services/Foo/FooService.php'],
            'file_hashes' => [],
            'completion' => [
                'status' => 'passed',
                'honesty_flags' => [],
                'residual_risks' => [],
            ],
            'gates' => [
                [
                    'name' => 'scope_guard_light',
                    'status' => 'passed',
                    'required' => true,
                    'fresh' => true,
                    'evidence_ref' => 'scope_guard_receipt:'.$scopeHash,
                    'waiver_reason' => null,
                ],
                [
                    'name' => 'verification_gate',
                    'status' => 'passed',
                    'required' => true,
                    'fresh' => true,
                    'evidence_ref' => null,
                    'waiver_reason' => null,
                ],
            ],
            'tests' => [
                [
                    'command' => 'php artisan test tests/Unit/Services/Foo/FooServiceTest.php',
                    'ok' => true,
                    'exit_code' => 0,
                    'duration_ms' => 42,
                    'output_hash' => str_repeat('e', 64),
                    'output_path' => null,
                ],
            ],
            'evidence_refs' => [],
            'repair' => [
                'attempt_count' => 0,
                'failure_capsule_refs' => [],
                'converted_to_green' => false,
            ],
            'cost' => [
                'provider_calls' => 1,
                'tokens_in' => 100,
                'tokens_out' => 50,
                'estimated_cost_usd' => 0.01,
                'wall_time_ms' => 1200,
            ],
            'escalation' => [
                'recommended' => false,
                'target' => null,
                'reasons' => [],
                'decision_ref' => null,
            ],
            'scope_guard_receipt_hash' => $scopeHash,
            'receipt_hash' => $receiptHash,
        ]);

        return new RunExecutionResult(
            completionState: 'passed',
            scopeGuardStatus: 'passed',
            verificationStatus: 'passed',
            persistedReceiptPaths: [
                'verification_receipt.json' => 'receipts/'.$runId.'/verification_receipt.json',
            ],
            providerCallSummary: [
                'provider' => 'claude_cli',
                'model_family' => 'sonnet',
                'provider_calls' => 1,
                'exit_code' => 0,
                'duration_ms' => 1200,
                'tokens_in' => 100,
                'tokens_out' => 50,
                'estimated_cost_usd' => 0.01,
                'error_codes' => [],
            ],
            verificationReceiptHash: $receiptHash,
            scopeGuardReceiptHash: $scopeHash,
            diffHash: $diffHash,
        );
    }
}

final class DesktopSmokeRunWorkerDispatcher implements RunWorkerDispatcher
{
    /** @var list<array{run_id:string,task_contract_hash:string,expected_compact_sdd_hash:?string}> */
    public array $calls = [];

    public function __construct(private readonly ?int $pid) {}

    public function dispatch(string $runId, string $taskContractHash, ?string $expectedCompactSddHash = null): ?int
    {
        $this->calls[] = [
            'run_id' => $runId,
            'task_contract_hash' => $taskContractHash,
            'expected_compact_sdd_hash' => $expectedCompactSddHash,
        ];

        return $this->pid;
    }
}
