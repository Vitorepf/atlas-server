<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeMinimaxM27InvocationDriver;
use App\Services\Ai\Programming\AtlasMinimaxM27RuntimeExecutor;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract tests for AtlasForgeMinimaxM27InvocationDriver (factory-critical runtime).
 */
final class AtlasForgeMinimaxM27InvocationDriverTest extends TestCase
{
    public function test_provider_returns_minimax_m27_constant(): void
    {
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime());

        $this->assertSame('minimax_m27', AtlasForgeMinimaxM27InvocationDriver::PROVIDER);
        $this->assertSame('minimax_m27', $driver->provider());
    }

    public function test_focused_unit_test_path_matches_this_file(): void
    {
        $this->assertSame(
            'tests/Unit/Ai/Programming/AtlasForgeMinimaxM27InvocationDriverTest.php',
            AtlasForgeMinimaxM27InvocationDriver::focusedUnitTestPath(),
        );
    }

    public function test_supports_rejects_other_providers(): void
    {
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime());

        $this->assertFalse($driver->supports('cursor_sdk', null));
        $this->assertFalse($driver->supports('claude_cli', null));
    }

    public function test_supports_accepts_null_model(): void
    {
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime());

        $this->assertTrue($driver->supports('minimax_m27', null));
    }

    public function test_supports_accepts_blank_model(): void
    {
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime());

        $this->assertTrue($driver->supports('minimax_m27', ''));
        $this->assertTrue($driver->supports('minimax_m27', '   '));
    }

    public function test_supports_accepts_minimax_m27_model_prefix(): void
    {
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime());

        $this->assertTrue($driver->supports('minimax_m27', 'MiniMax-M2.7'));
        $this->assertTrue($driver->supports('minimax_m27', 'minimax-m2.7-pro'));
    }

    public function test_supports_rejects_unknown_model_prefix(): void
    {
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime());

        $this->assertFalse($driver->supports('minimax_m27', 'gpt-4'));
        $this->assertFalse($driver->supports('minimax_m27', 'cursor-pro'));
    }

    public function test_plan_manifest_metadata_denies_completion_claim(): void
    {
        $captured = null;
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime($captured));

        $plan = $driver->plan($this->validRequest());

        $this->assertSame('atlas.forge.provider_driver_plan.v1', $plan['schema_version']);
        $this->assertFalse($plan['provider_called']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertIsArray($captured);
        $this->assertFalse((bool) data_get($captured, 'metadata.completion_claim_allowed'));
    }

    public function test_plan_manifest_metadata_has_atlas_decide_authority(): void
    {
        $captured = null;
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime($captured));

        $driver->plan($this->validRequest());

        $this->assertIsArray($captured);
        $this->assertSame('atlas_decide', data_get($captured, 'metadata.provider_authority'));
    }

    public function test_plan_metadata_billing_mode_is_token_plan(): void
    {
        $captured = null;
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime($captured));

        $driver->plan($this->validRequest());

        $this->assertIsArray($captured);
        $this->assertSame('token_plan_request_based', data_get($captured, 'metadata.billing_mode'));
    }

    public function test_plan_metadata_paygo_enabled_false(): void
    {
        $captured = null;
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime($captured));

        $driver->plan($this->validRequest());

        $this->assertIsArray($captured);
        $this->assertFalse((bool) data_get($captured, 'metadata.paygo_enabled'));
    }

    public function test_plan_resolves_workspace_from_cwd(): void
    {
        $captured = null;
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime($captured));

        $driver->plan($this->validRequest());

        $this->assertIsArray($captured);
        $this->assertSame('/tmp/atlas-workspace', data_get($captured, 'workspace.path'));
    }

    public function test_plan_resolves_workspace_from_request_workspace_path(): void
    {
        $captured = null;
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime($captured));

        $request = $this->validRequest();
        unset($request['cwd']);
        $request['workspace'] = ['path' => '/repos/atlas-workspace'];

        $driver->plan($request);

        $this->assertIsArray($captured);
        $this->assertSame('/repos/atlas-workspace', data_get($captured, 'workspace.path'));
    }

    public function test_plan_never_calls_provider(): void
    {
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime());

        $plan = $driver->plan($this->validRequest());

        $this->assertFalse($plan['provider_called']);
        $this->assertFalse($plan['external_provider_call']);
    }

    public function test_invoke_passes_decision_receipt_to_manifest(): void
    {
        $captured = null;
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime($captured));

        $request = $this->validRequest();
        $driver->invoke($request);

        $this->assertIsArray($captured);
        $this->assertSame('receipt_1', data_get($captured, 'decision_receipt_id'));
        $this->assertSame(hash('sha256', 'receipt_1'), data_get($captured, 'decision_receipt_hash'));
    }

    public function test_driver_result_never_contains_completion_claim_true(): void
    {
        $driver = new AtlasForgeMinimaxM27InvocationDriver($this->stubRuntime());

        $result = $driver->invoke($this->validRequest());

        $this->assertArrayNotHasKey('completion_claim', $result);
        $this->assertArrayNotHasKey('completion_claim_allowed', $result);
        $this->assertFalse((bool) ($result['provider_called'] ?? false));
        $this->assertFalse((bool) ($result['external_provider_call'] ?? false));
    }

    /**
     * @return array<string,mixed>
     */
    private function validRequest(): array
    {
        return [
            'model' => 'MiniMax-M2.7',
            'cwd' => '/tmp/atlas-workspace',
            'decision_receipt_id' => 'receipt_1',
            'decision_receipt_hash' => hash('sha256', 'receipt_1'),
            'prompt' => [
                'schema_version' => 'atlas.forge.provider_invocation_prompt.v1',
                'decision_receipt_id' => 'receipt_1',
                'decision_receipt_hash' => hash('sha256', 'receipt_1'),
                'scope_contract' => [
                    'allowed_files' => ['app/Foo.php'],
                    'forbidden_files' => ['.env'],
                ],
            ],
        ];
    }

    private function stubRuntime(?array &$capturedManifest = null): AtlasMinimaxM27RuntimeExecutor
    {
        $runtime = $this->createMock(AtlasMinimaxM27RuntimeExecutor::class);
        $runtime->method('configured')->willReturn([
            'configured' => true,
            'blockers' => [],
        ]);
        $runtime->method('plan')->willReturnCallback(function (array $manifest) use (&$capturedManifest): array {
            $capturedManifest = $manifest;

            return [
                'configured' => true,
                'plan_safe' => true,
                'blockers' => [],
                'argv_preview' => ['https://api.minimax.io/anthropic/v1/messages', '<manifest_hash>'],
            ];
        });
        $runtime->method('invoke')->willReturnCallback(function (array $manifest) use (&$capturedManifest): array {
            $capturedManifest = $manifest;

            return [
                'blockers' => [],
                'provider_called' => false,
                'external_provider_call' => false,
            ];
        });

        return $runtime;
    }
}
