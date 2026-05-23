<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasCursorSdkRuntimeExecutor;
use App\Services\Ai\Programming\AtlasForgeCursorSdkInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasForgeCursorSdkDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('CURSOR_API_KEY');
        config()->set('atlas.ai.providers.cursor_sdk.enabled', false);
        config()->set('atlas.ai.providers.cursor_sdk.node', 'node');
        config()->set('atlas.ai.providers.cursor_sdk.module', '@cursor/sdk');
        config()->set('atlas.ai.providers.cursor_sdk.adapter_path', 'runtimes/node/cursor_sdk/adapter.mjs');
        config()->set('atlas.ai.providers.cursor_sdk.runtime_mode', 'local');
        config()->set('atlas.ai.providers.cursor_sdk.billing_mode', 'cursor_account_usage_bucket');
        config()->set('atlas.ai.providers.cursor_sdk.quota_bucket', 'cursor_account_default');
    }

    protected function tearDown(): void
    {
        putenv('CURSOR_API_KEY');
        parent::tearDown();
    }

    public function test_router_lists_cursor_sdk_as_canonical_fail_closed_driver(): void
    {
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);
        $status = $router->driverStatus();

        $providers = array_map(fn (array $driver): string => (string) $driver['provider'], $status['drivers']);

        $this->assertContains('cursor_sdk', $providers);
        $this->assertTrue($router->supports('cursor_sdk'));
        $this->assertTrue($router->hasRuntimeDriver('cursor_sdk'));
        $this->assertFalse($router->isConfigured('cursor_sdk'));
        $this->assertContains('atlas-local', $status['configured_drivers']);
        $this->assertNotContains('cursor_sdk', $status['configured_drivers']);
    }

    public function test_status_blocks_when_disabled_without_provider_call(): void
    {
        $driver = app(AtlasForgeCursorSdkInvocationDriver::class);
        $status = $driver->configured();

        $this->assertSame('atlas.provider.cursor_sdk.status.v1', $status['schema_version']);
        $this->assertFalse($status['configured']);
        $this->assertContains('cursor_sdk_disabled', $status['blockers']);
        $this->assertTrue($status['external_provider_call_possible']);
        $this->assertTrue($status['provider_tokens_may_be_spent']);
    }

    public function test_plan_never_calls_provider_and_requires_model_receipt_and_scope(): void
    {
        config()->set('atlas.ai.providers.cursor_sdk.enabled', true);
        config()->set('atlas.ai.providers.cursor_sdk.module', 'node:fs');
        putenv('CURSOR_API_KEY=test-key');

        $driver = app(AtlasForgeCursorSdkInvocationDriver::class);
        $plan = $driver->plan([
            'model' => '',
            'prompt' => [
                'schema_version' => 'atlas.forge.provider_invocation_prompt.v1',
                'scope_contract' => [
                    'allowed_files' => [],
                    'forbidden_files' => ['.env'],
                ],
            ],
            'cwd' => base_path(),
        ]);

        $this->assertSame('atlas.forge.provider_driver_plan.v1', $plan['schema_version']);
        $this->assertFalse($plan['provider_called']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['provider_tokens_spent']);
        $this->assertContains('decision_receipt_required', $plan['blockers']);
        $this->assertContains('cursor_sdk_model_required', $plan['blockers']);
        $this->assertContains('cursor_sdk_allowed_files_required', $plan['blockers']);
    }

    public function test_invoke_blocks_before_adapter_when_manifest_is_incomplete(): void
    {
        config()->set('atlas.ai.providers.cursor_sdk.enabled', true);
        config()->set('atlas.ai.providers.cursor_sdk.module', 'node:fs');
        putenv('CURSOR_API_KEY=test-key');

        $driver = app(AtlasForgeCursorSdkInvocationDriver::class);
        $result = $driver->invoke([
            'model' => 'composer-latest',
            'prompt' => [
                'schema_version' => 'atlas.forge.provider_invocation_prompt.v1',
                'scope_contract' => [
                    'allowed_files' => [],
                    'forbidden_files' => ['.env'],
                ],
            ],
            'cwd' => base_path(),
        ]);

        $this->assertFalse($result['provider_called']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertContains('decision_receipt_required', $result['blockers']);
        $this->assertContains('cursor_sdk_allowed_files_required', $result['blockers']);
    }

    public function test_runtime_invokes_adapter_only_when_config_receipt_model_and_scope_are_valid(): void
    {
        config()->set('atlas.ai.providers.cursor_sdk.enabled', true);
        config()->set('atlas.ai.providers.cursor_sdk.module', 'node:fs');
        putenv('CURSOR_API_KEY=test-key');

        $runtime = app(AtlasCursorSdkRuntimeExecutor::class);
        $runtime->setProcessFactory(function (array $argv, ?string $cwd, ?array $env, int $timeout): Process {
            $payload = json_encode([
                'schema_version' => 'atlas.provider.cursor_sdk.invocation_result.v1',
                'provider_called' => true,
                'external_provider_call' => true,
                'provider_tokens_spent' => 'unknown',
                'model_observed' => 'composer-latest',
                'runtime_mode' => 'local',
                'billing_mode' => 'cursor_account_usage_bucket',
                'quota_bucket' => 'cursor_account_default',
                'changed_files' => ['app/Foo.php'],
                'tool_events' => [['type' => 'tool_call', 'name' => 'edit', 'status' => 'completed']],
                'artifacts' => [['kind' => 'text', 'sha256' => hash('sha256', 'ok')]],
                'performance_signal' => [
                    'schema_version' => 'atlas.provider.cursor_sdk.performance_signal.v1',
                    'provider' => 'cursor_sdk',
                    'status' => 'succeeded',
                    'routing_effect' => 'none',
                    'advisory_only' => true,
                ],
                'blockers' => [],
                'note' => 'fake cursor adapter ok',
            ], JSON_UNESCAPED_SLASHES);

            return new Process([PHP_BINARY, '-r', 'echo '.var_export($payload, true).';'], $cwd, $env, null, $timeout);
        });

        $driver = new AtlasForgeCursorSdkInvocationDriver($runtime);
        $result = $driver->invoke($this->validRequest());

        $this->assertTrue($result['provider_called']);
        $this->assertTrue($result['external_provider_call']);
        $this->assertSame('composer-latest', $result['model_observed']);
        $this->assertSame('cursor_account_usage_bucket', $result['billing_mode']);
        $this->assertSame(['app/Foo.php'], $result['changed_files']);
        $this->assertSame('none', data_get($result, 'performance_signal.routing_effect'));
    }

    /**
     * @return array<string,mixed>
     */
    private function validRequest(): array
    {
        return [
            'model' => 'composer-latest',
            'prompt' => [
                'schema_version' => 'atlas.forge.provider_invocation_prompt.v1',
                'decision_receipt_id' => 'receipt_1',
                'decision_receipt_hash' => hash('sha256', 'receipt_1'),
                'scope_contract' => [
                    'allowed_files' => ['app/Foo.php'],
                    'forbidden_files' => ['.env'],
                ],
            ],
            'cwd' => base_path(),
            'decision_receipt_id' => 'receipt_1',
            'decision_receipt_hash' => hash('sha256', 'receipt_1'),
            'timeout_seconds' => 5,
        ];
    }
}
