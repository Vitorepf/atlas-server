<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasAntigravitySdkRuntimeExecutor;
use App\Services\Ai\Programming\AtlasForgeAntigravitySdkInvocationDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract tests for AtlasForgeAntigravitySdkInvocationDriver (factory-critical runtime).
 */
final class AtlasForgeAntigravitySdkInvocationDriverTest extends TestCase
{
    public function test_provider_returns_antigravity_sdk_constant(): void
    {
        $driver = new AtlasForgeAntigravitySdkInvocationDriver($this->stubRuntime());

        $this->assertSame('antigravity_sdk', AtlasForgeAntigravitySdkInvocationDriver::PROVIDER);
        $this->assertSame('antigravity_sdk', $driver->provider());
    }

    public function test_focused_unit_test_path_is_same_name_coverage(): void
    {
        $this->assertSame(
            'tests/Unit/Ai/Programming/AtlasForgeAntigravitySdkInvocationDriverTest.php',
            AtlasForgeAntigravitySdkInvocationDriver::focusedUnitTestPath(),
        );
    }

    public function test_supports_rejects_other_providers(): void
    {
        $driver = new AtlasForgeAntigravitySdkInvocationDriver($this->stubRuntime());

        $this->assertFalse($driver->supports('cursor_sdk', 'gemini-3.5-flash'));
        $this->assertFalse($driver->supports('claude_cli', null));
    }

    public function test_supports_accepts_null_or_blank_model(): void
    {
        $driver = new AtlasForgeAntigravitySdkInvocationDriver($this->stubRuntime());

        $this->assertTrue($driver->supports('antigravity_sdk', null));
        $this->assertTrue($driver->supports('antigravity_sdk', ''));
        $this->assertTrue($driver->supports('antigravity_sdk', '   '));
    }

    #[DataProvider('supportedModelProvider')]
    public function test_supports_accepts_known_model_prefixes(string $model): void
    {
        $driver = new AtlasForgeAntigravitySdkInvocationDriver($this->stubRuntime());

        $this->assertTrue($driver->supports('antigravity_sdk', $model));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function supportedModelProvider(): array
    {
        return [
            'antigravity prefix' => ['antigravity-pro'],
            'gemini prefix' => ['gemini-3.5-flash'],
            'claude prefix' => ['claude-sonnet-4'],
            'gpt prefix' => ['gpt-5-mini'],
            'oss prefix' => ['oss-local'],
            'uppercase normalized' => ['GEMINI-3.5-FLASH'],
        ];
    }

    public function test_supports_rejects_unknown_model_prefix(): void
    {
        $driver = new AtlasForgeAntigravitySdkInvocationDriver($this->stubRuntime());

        $this->assertFalse($driver->supports('antigravity_sdk', 'unknown-model'));
    }

    public function test_plan_manifest_metadata_denies_completion_claim(): void
    {
        $captured = null;
        $driver = new AtlasForgeAntigravitySdkInvocationDriver($this->stubRuntime($captured));

        $plan = $driver->plan($this->validRequest());

        $this->assertSame('atlas.forge.provider_driver_plan.v1', $plan['schema_version']);
        $this->assertFalse($plan['provider_called']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertIsArray($captured);
        $this->assertFalse((bool) data_get($captured, 'metadata.completion_claim_allowed'));
        $this->assertSame('atlas_decide', data_get($captured, 'metadata.provider_authority'));
        $this->assertSame('none', data_get($captured, 'metadata.routing_effect'));
    }

    public function test_plan_resolves_workspace_from_request_workspace_path_when_cwd_missing(): void
    {
        $captured = null;
        $driver = new AtlasForgeAntigravitySdkInvocationDriver($this->stubRuntime($captured));

        $request = $this->validRequest();
        unset($request['cwd']);
        $request['workspace'] = ['path' => '/repos/atlas-workspace'];

        $driver->plan($request);

        $this->assertSame('/repos/atlas-workspace', data_get($captured, 'workspace.path'));
    }

    /**
     * @return array<string,mixed>
     */
    private function validRequest(): array
    {
        return [
            'model' => 'gemini-3.5-flash',
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

    private function stubRuntime(?array &$capturedManifest = null): AtlasAntigravitySdkRuntimeExecutor
    {
        $runtime = $this->createMock(AtlasAntigravitySdkRuntimeExecutor::class);
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
                'argv_preview' => ['python3', 'adapter.py', '<manifest.json>'],
            ];
        });
        $runtime->method('invoke')->willReturnCallback(function (array $manifest) use (&$capturedManifest): array {
            $capturedManifest = $manifest;

            return [
                'blockers' => [],
                'provider_called' => false,
            ];
        });

        return $runtime;
    }
}
