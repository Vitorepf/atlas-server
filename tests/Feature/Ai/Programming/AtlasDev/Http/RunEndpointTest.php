<?php

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Models\AtlasDevConfirmationToken;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Runtime\RunWorkerDispatcher;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use Illuminate\Support\Carbon;

final class RunEndpointTest extends AtlasDevHttpTestCase
{
    private FakeRunExecutor $fakeExecutor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeExecutor = FakeRunExecutor::passing();
        $this->app->instance(RunExecutor::class, $this->fakeExecutor);
    }

    /**
     * @return array{run_id:string, task_contract_hash:string, confirmation_token:string}
     */
    private function plan(): array
    {
        $payload = $this->defaultRepairPayload();
        $data = $this->postPlan($payload);
        $this->assertSame('atlas_dev_fast_path', $data['routing']['kind']);
        $this->assertIsArray($data['confirmation']);

        return [
            'run_id' => $data['run_id'],
            'task_contract_hash' => $data['hashes']['task_contract'],
            'confirmation_token' => $data['confirmation']['token'],
        ];
    }

    /**
     * @return array{run_id:string, task_contract_hash:string, confirmation_token:string}
     */
    private function desktopPlan(): array
    {
        config()->set('atlas_dev.efficient.desktop_enabled', true);

        $payload = $this->defaultRepairPayload();
        $payload['surface_id'] = 'atlas_desktop_ai';
        $data = $this->postPlan($payload);
        $this->assertSame('atlas_dev_fast_path', $data['routing']['kind']);
        $this->assertIsArray($data['confirmation']);

        return [
            'run_id' => $data['run_id'],
            'task_contract_hash' => $data['hashes']['task_contract'],
            'confirmation_token' => $data['confirmation']['token'],
        ];
    }

    public function test_run_with_valid_token_executes_and_returns_completion_state(): void
    {
        $plan = $this->plan();

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.completion_state', 'passed');
        $response->assertJsonPath('data.scope_guard_status', 'passed');
        $response->assertJsonPath('data.verification_status', 'passed');
        $response->assertJsonPath('data.provider_call.provider', 'claude_cli');
        $response->assertJsonPath('data.provider_call.model_family', 'sonnet');
        $response->assertJsonPath('data.provider_call.provider_calls', 1);
        $this->assertCount(1, $this->fakeExecutor->calls);
    }

    public function test_run_after_response_mode_accepts_without_inline_completion_payload(): void
    {
        config()->set('atlas_dev.efficient.run_dispatch_mode', 'after_response');
        $plan = $this->plan();

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.ok', true);
        $response->assertJsonPath('data.run_id', $plan['run_id']);
        $response->assertJsonPath('data.state', 'queued');
        $response->assertJsonPath('data.completion_state', null);
        $this->assertArrayNotHasKey('provider_call', $response->json('data'));
    }

    public function test_run_process_mode_dispatches_worker_without_inline_provider_execution(): void
    {
        config()->set('atlas_dev.efficient.run_dispatch_mode', 'process');
        $dispatcher = new CapturingRunWorkerDispatcher(pid: 4242);
        $this->app->instance(RunWorkerDispatcher::class, $dispatcher);

        $plan = $this->plan();
        $tokenRow = AtlasDevConfirmationToken::query()
            ->where('run_id', $plan['run_id'])
            ->firstOrFail();

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.ok', true);
        $response->assertJsonPath('data.run_id', $plan['run_id']);
        $response->assertJsonPath('data.state', 'queued');
        $response->assertJsonPath('data.completion_state', null);
        $response->assertJsonPath('data.dispatch_mode', 'process');
        $response->assertJsonPath('data.worker_pid', 4242);

        $this->assertCount(0, $this->fakeExecutor->calls, 'Process mode must not run the provider inside the HTTP request.');
        $this->assertSame([
            'run_id' => $plan['run_id'],
            'task_contract_hash' => $plan['task_contract_hash'],
            'expected_compact_sdd_hash' => $tokenRow->compact_sdd_hash,
        ], $dispatcher->calls[0] ?? null);

        $storage = $this->app->make(ReceiptStorage::class);
        $latestState = $storage->readLatestVersion($plan['run_id'], ArtifactNames::RUN_EXECUTION_STATE_BASE);
        $this->assertIsArray($latestState);
        $this->assertSame('queued', $latestState['status']);
        $this->assertSame('process', $latestState['dispatch_mode']);
        $this->assertSame(4242, $latestState['worker_pid']);
    }

    public function test_run_process_mode_records_failed_dispatch_without_inline_provider_execution(): void
    {
        config()->set('atlas_dev.efficient.run_dispatch_mode', 'process');
        $this->app->instance(RunWorkerDispatcher::class, new ThrowingRunWorkerDispatcher);

        $plan = $this->plan();

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ]);

        $response->assertStatus(500);
        $response->assertJsonPath('error.code', 'ATLAS_DEV_RUN_DISPATCH_FAILED');
        $this->assertCount(0, $this->fakeExecutor->calls, 'Dispatch failure must not fall back to inline provider execution.');

        $storage = $this->app->make(ReceiptStorage::class);
        $latestState = $storage->readLatestVersion($plan['run_id'], ArtifactNames::RUN_EXECUTION_STATE_BASE);
        $this->assertIsArray($latestState);
        $this->assertSame('failed', $latestState['status']);
        $this->assertSame('ATLAS_DEV_RUN_DISPATCH_FAILED', $latestState['error_code']);
        $this->assertStringNotContainsString('/Users/operator', (string) ($latestState['message'] ?? ''));
    }

    public function test_run_extends_php_execution_time_before_provider_call(): void
    {
        $previous = ini_get('max_execution_time');
        ini_set('max_execution_time', '30');
        config()->set('atlas.ai.timeout_seconds', 600);

        try {
            $plan = $this->plan();

            $this->withHeaders($this->headers)
                ->postJson('/ai/interactions/atlas-dev/run', [
                    'run_id' => $plan['run_id'],
                    'task_contract_hash' => $plan['task_contract_hash'],
                    'confirmation_token' => $plan['confirmation_token'],
                    'operator_confirmed' => true,
                ])
                ->assertStatus(200);

            $this->assertGreaterThanOrEqual(660, $this->fakeExecutor->maxExecutionTimeAtExecute);
        } finally {
            ini_set('max_execution_time', (string) $previous);
        }
    }

    public function test_run_500_error_redacts_token_and_absolute_paths(): void
    {
        $plan = $this->plan();
        $token = $plan['confirmation_token'];

        $this->app->instance(RunExecutor::class, new class($token) implements RunExecutor
        {
            public function __construct(private readonly string $token) {}

            public function execute(
                OperationEnvelope $envelope,
                LightTaskContract $taskContract,
                ProviderPromptProjection $promptProjection,
                string $runId,
                ?string $expectedCompactSddHash = null,
            ): RunExecutionResult {
                throw new \RuntimeException(
                    "provider failed for token {$this->token} at /Users/operator/dev/Atlas/atlas-server/app/Foo.php",
                );
            }
        });

        $response = $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $token,
                'operator_confirmed' => true,
            ]);

        $response->assertStatus(500)
            ->assertJsonPath('error.code', 'ATLAS_DEV_RUN_FAILED');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString('/Users/operator', $body);
        $this->assertStringNotContainsString('/atlas-server/app/Foo.php', $body);
    }

    public function test_run_rejects_when_operator_not_confirmed(): void
    {
        $plan = $this->plan();

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => false,
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'OPERATOR_NOT_CONFIRMED');

        $this->assertCount(0, $this->fakeExecutor->calls);
    }

    public function test_run_rejects_when_operator_confirmed_is_truthy_string(): void
    {
        $plan = $this->plan();

        // Even though Laravel's `boolean` validator accepts "true" as truthy,
        // the controller insists on literal true at the JSON-decoded level.
        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => 'true',
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'OPERATOR_NOT_CONFIRMED');
    }

    public function test_run_rejects_invalid_task_contract_hash(): void
    {
        $plan = $this->plan();

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => str_repeat('f', 64),
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TASK_CONTRACT_HASH_MISMATCH');
    }

    public function test_run_rejects_when_plan_not_persisted(): void
    {
        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => 'dev-9999999999999-deadbeef',
                'task_contract_hash' => str_repeat('a', 64),
                'confirmation_token' => str_repeat('b', 64),
                'operator_confirmed' => true,
            ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PLAN_NOT_FOUND');
    }

    public function test_run_rejects_when_confirmation_token_missing_in_request(): void
    {
        $plan = $this->plan();

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'operator_confirmed' => true,
            ])
            ->assertStatus(422);
    }

    public function test_run_rejects_invalid_confirmation_token(): void
    {
        $plan = $this->plan();

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => str_repeat('0', 64),
                'operator_confirmed' => true,
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CONFIRMATION_TOKEN_INVALID');
    }

    public function test_run_rejects_reused_confirmation_token(): void
    {
        $plan = $this->plan();

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ])
            ->assertStatus(200);

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CONFIRMATION_TOKEN_ALREADY_CONSUMED');
    }

    public function test_run_rejects_expired_confirmation_token(): void
    {
        $plan = $this->plan();

        // Simulate expiration by backdating the DB row for the issued token.
        $row = AtlasDevConfirmationToken::query()
            ->where('run_id', $plan['run_id'])
            ->firstOrFail();
        $row->forceFill(['expires_at' => Carbon::now()->subSecond()])->save();

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CONFIRMATION_TOKEN_EXPIRED');
    }

    public function test_run_disabled_by_feature_flag_returns_503(): void
    {
        $plan = $this->plan();
        config()->set('atlas_dev.efficient.run_enabled', false);

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'ATLAS_DEV_RUN_DISABLED');
    }

    public function test_run_rejects_desktop_surface_when_desktop_flag_is_disabled(): void
    {
        $plan = $this->desktopPlan();
        config()->set('atlas_dev.efficient.desktop_enabled', false);

        $this->withHeaders($this->headers)
            ->postJson('/ai/interactions/atlas-dev/run', [
                'run_id' => $plan['run_id'],
                'task_contract_hash' => $plan['task_contract_hash'],
                'confirmation_token' => $plan['confirmation_token'],
                'operator_confirmed' => true,
            ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'ATLAS_DEV_DESKTOP_DISABLED');

        $this->assertCount(0, $this->fakeExecutor->calls);
    }

    public function test_run_persisted_artifacts_exist_after_plan(): void
    {
        $plan = $this->plan();
        $storage = $this->app->make(ReceiptStorage::class);
        $this->assertTrue($storage->exists($plan['run_id'], ArtifactNames::OPERATION_ENVELOPE));
        $this->assertTrue($storage->exists($plan['run_id'], ArtifactNames::TASK_CONTRACT));
        $this->assertTrue($storage->exists($plan['run_id'], ArtifactNames::PROMPT_PROJECTION));
    }
}

final class CapturingRunWorkerDispatcher implements RunWorkerDispatcher
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

final class ThrowingRunWorkerDispatcher implements RunWorkerDispatcher
{
    public function dispatch(string $runId, string $taskContractHash, ?string $expectedCompactSddHash = null): ?int
    {
        throw new \RuntimeException('proc_open failed for /Users/operator/dev/Atlas/atlas-server');
    }
}
