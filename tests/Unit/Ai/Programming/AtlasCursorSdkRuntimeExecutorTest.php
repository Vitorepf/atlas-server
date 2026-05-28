<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasCursorSdkRuntimeExecutor;
use App\Services\Ai\Programming\AtlasForgeCursorSdkInvocationDriver;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Focused contract tests for AtlasCursorSdkRuntimeExecutor (factory-critical runtime).
 */
final class AtlasCursorSdkRuntimeExecutorTest extends TestCase
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

    public function test_focused_unit_test_path_is_same_name_coverage(): void
    {
        $this->assertSame(
            'tests/Unit/Ai/Programming/AtlasCursorSdkRuntimeExecutorTest.php',
            AtlasCursorSdkRuntimeExecutor::focusedUnitTestPath(),
        );
    }

    public function test_process_status_constants_match_contract(): void
    {
        $this->assertSame('completed', AtlasCursorSdkRuntimeExecutor::STATUS_COMPLETED);
        $this->assertSame('failed', AtlasCursorSdkRuntimeExecutor::STATUS_FAILED);
        $this->assertSame('timed_out', AtlasCursorSdkRuntimeExecutor::STATUS_TIMED_OUT);
        $this->assertSame('blocked', AtlasCursorSdkRuntimeExecutor::STATUS_BLOCKED);
    }

    public function test_configured_fail_closed_when_disabled_without_provider_call(): void
    {
        $runtime = app(AtlasCursorSdkRuntimeExecutor::class);
        $status = $runtime->configured();

        $this->assertSame('atlas.provider.cursor_sdk.status.v1', $status['schema_version']);
        $this->assertSame(AtlasForgeCursorSdkInvocationDriver::PROVIDER, $status['provider']);
        $this->assertFalse($status['configured']);
        $this->assertContains('cursor_sdk_disabled', $status['blockers']);
        $this->assertTrue($status['external_provider_call_possible']);
        $this->assertTrue($status['provider_tokens_may_be_spent']);
        $this->assertSame('executor_only_after_decision_receipt', $status['runtime_boundary']['authority']);
    }

    public function test_plan_never_calls_provider_and_requires_receipt_model_and_scope(): void
    {
        config()->set('atlas.ai.providers.cursor_sdk.enabled', true);
        config()->set('atlas.ai.providers.cursor_sdk.module', 'node:fs');
        putenv('CURSOR_API_KEY=test-key');

        $runtime = app(AtlasCursorSdkRuntimeExecutor::class);
        $plan = $runtime->plan([
            'model' => '',
            'workspace' => ['path' => base_path()],
            'scope_contract' => [
                'allowed_files' => [],
                'forbidden_files' => ['.env'],
            ],
        ]);

        $this->assertSame('atlas.provider.cursor_sdk.invocation_request.v1', $plan['schema_version']);
        $this->assertFalse($plan['provider_called']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['provider_tokens_spent']);
        $this->assertFalse($plan['plan_safe']);
        $this->assertContains('decision_receipt_required', $plan['blockers']);
        $this->assertContains('cursor_sdk_model_required', $plan['blockers']);
        $this->assertContains('cursor_sdk_allowed_files_required', $plan['blockers']);
    }

    public function test_plan_resolves_workspace_from_workspace_path_alias(): void
    {
        config()->set('atlas.ai.providers.cursor_sdk.enabled', true);
        config()->set('atlas.ai.providers.cursor_sdk.module', 'node:fs');
        putenv('CURSOR_API_KEY=test-key');

        $runtime = app(AtlasCursorSdkRuntimeExecutor::class);
        $plan = $runtime->plan([
            'model' => 'composer-latest',
            'decision_receipt_id' => 'receipt_1',
            'decision_receipt_hash' => hash('sha256', 'receipt_1'),
            'workspace_path' => base_path(),
            'scope_contract' => [
                'allowed_files' => ['app/Foo.php'],
                'forbidden_files' => ['.env'],
            ],
        ]);

        $this->assertNotContains('cursor_sdk_workspace_required', $plan['blockers']);
        $this->assertTrue($plan['plan_safe']);
    }

    public function test_invoke_blocks_before_adapter_when_manifest_is_incomplete(): void
    {
        config()->set('atlas.ai.providers.cursor_sdk.enabled', true);
        config()->set('atlas.ai.providers.cursor_sdk.module', 'node:fs');
        putenv('CURSOR_API_KEY=test-key');

        $runtime = app(AtlasCursorSdkRuntimeExecutor::class);
        $result = $runtime->invoke([
            'model' => 'composer-latest',
            'workspace' => ['path' => base_path()],
            'scope_contract' => [
                'allowed_files' => [],
                'forbidden_files' => ['.env'],
            ],
        ]);

        $this->assertSame('atlas.provider.cursor_sdk.invocation_result.v1', $result['schema_version']);
        $this->assertFalse($result['provider_called']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertSame(AtlasCursorSdkRuntimeExecutor::STATUS_BLOCKED, $result['process_status']);
        $this->assertContains('decision_receipt_required', $result['blockers']);
        $this->assertContains('cursor_sdk_allowed_files_required', $result['blockers']);
    }

    public function test_invoke_uses_process_factory_when_plan_is_safe(): void
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
                'changed_files' => ['app/Foo.php'],
                'blockers' => [],
                'note' => 'fake cursor adapter ok',
            ], JSON_UNESCAPED_SLASHES);

            return new Process([PHP_BINARY, '-r', 'echo '.var_export($payload, true).';'], $cwd, $env, null, $timeout);
        });

        $result = $runtime->invoke($this->validManifest());

        $this->assertTrue($result['provider_called']);
        $this->assertTrue($result['external_provider_call']);
        $this->assertSame('composer-latest', $result['model_observed']);
        $this->assertSame(AtlasCursorSdkRuntimeExecutor::STATUS_COMPLETED, $result['process_status']);
        $this->assertSame(['app/Foo.php'], $result['changed_files']);
        $this->assertSame([], $result['blockers']);
    }

    /**
     * @return array<string,mixed>
     */
    private function validManifest(): array
    {
        return [
            'model' => 'composer-latest',
            'workspace' => ['path' => base_path()],
            'decision_receipt_id' => 'receipt_1',
            'decision_receipt_hash' => hash('sha256', 'receipt_1'),
            'scope_contract' => [
                'allowed_files' => ['app/Foo.php'],
                'forbidden_files' => ['.env'],
            ],
            'timeout_seconds' => 5,
        ];
    }
}
