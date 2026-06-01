<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeMinimaxM27CliInvocationDriver;
use App\Services\Ai\Programming\AtlasMinimaxM27CliRuntimeExecutor;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract tests for AtlasForgeMinimaxM27CliInvocationDriver (factory-critical runtime).
 */
final class AtlasForgeMinimaxM27CliInvocationDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Bootstrap a minimal Laravel Application so the config() helper resolves
        // without launching a full HTTP kernel or loading service providers.
        $app = new Application(dirname(__DIR__, 4));
        $app->singleton('config', fn () => new ConfigRepository([
            'atlas' => [
                'ai' => [
                    'providers' => [
                        'minimax_m27_cli' => [
                            'timeout_seconds' => 120,
                            'auth_mode' => 'token_plan_key',
                        ],
                    ],
                ],
            ],
        ]));
        $app->instance('config', $app->make('config'));
    }

    public function test_provider_returns_minimax_m27_cli_constant(): void
    {
        $driver = new AtlasForgeMinimaxM27CliInvocationDriver($this->stubRuntime());

        $this->assertSame('minimax_m27_cli', AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER);
        $this->assertSame('minimax_m27_cli', $driver->provider());
    }

    public function test_focused_unit_test_path_matches_this_file(): void
    {
        $this->assertSame(
            'tests/Unit/Ai/Programming/AtlasForgeMinimaxM27CliInvocationDriverTest.php',
            AtlasForgeMinimaxM27CliInvocationDriver::focusedUnitTestPath(),
        );
    }

    public function test_supports_rejects_other_providers(): void
    {
        $driver = new AtlasForgeMinimaxM27CliInvocationDriver($this->stubRuntime());

        $this->assertFalse($driver->supports('cursor_sdk', 'minimax-m3'));
        $this->assertFalse($driver->supports('claude_cli', null));
        $this->assertFalse($driver->supports('antigravity_sdk', 'minimax-m3'));
    }

    public function test_supports_accepts_null_and_blank_model(): void
    {
        $driver = new AtlasForgeMinimaxM27CliInvocationDriver($this->stubRuntime());

        $this->assertTrue($driver->supports('minimax_m27_cli', null));
        $this->assertTrue($driver->supports('minimax_m27_cli', ''));
        $this->assertTrue($driver->supports('minimax_m27_cli', '   '));
    }

    #[DataProvider('supportedModelPrefixProvider')]
    public function test_supports_accepts_minimax_m3_model(string $model): void
    {
        $driver = new AtlasForgeMinimaxM27CliInvocationDriver($this->stubRuntime());

        $this->assertTrue($driver->supports('minimax_m27_cli', $model));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function supportedModelPrefixProvider(): array
    {
        return [
            'canonical' => ['MiniMax-M3'],
            'lowercase' => ['minimax-m3'],
            'uppercase normalized' => ['MINIMAX-M3'],
        ];
    }

    public function test_supports_rejects_legacy_or_variant_models(): void
    {
        $driver = new AtlasForgeMinimaxM27CliInvocationDriver($this->stubRuntime());

        $this->assertFalse($driver->supports('minimax_m27_cli', 'MiniMax-M2.7'));
        $this->assertFalse($driver->supports('minimax_m27_cli', 'MiniMax-M3-highspeed'));
        $this->assertFalse($driver->supports('minimax_m27_cli', 'minimax-m3-pro'));
    }

    public function test_plan_metadata_denies_completion_claim(): void
    {
        $captured = null;
        $driver = new AtlasForgeMinimaxM27CliInvocationDriver($this->stubRuntime($captured));

        $plan = $driver->plan($this->validRequest());

        $this->assertSame('atlas.forge.provider_driver_plan.v1', $plan['schema_version']);
        $this->assertFalse($plan['provider_called']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertIsArray($captured);
        $this->assertFalse((bool) data_get($captured, 'metadata.completion_claim_allowed'));
    }

    public function test_plan_metadata_atlas_decide_authority(): void
    {
        $captured = null;
        $driver = new AtlasForgeMinimaxM27CliInvocationDriver($this->stubRuntime($captured));

        $driver->plan($this->validRequest());

        $this->assertIsArray($captured);
        $this->assertSame('atlas_decide', data_get($captured, 'metadata.provider_authority'));
        $this->assertSame('none', data_get($captured, 'metadata.routing_effect'));
    }

    public function test_plan_metadata_paygo_enabled_false(): void
    {
        $captured = null;
        $driver = new AtlasForgeMinimaxM27CliInvocationDriver($this->stubRuntime($captured));

        $driver->plan($this->validRequest());

        $this->assertIsArray($captured);
        $this->assertFalse((bool) data_get($captured, 'metadata.paygo_enabled'));
    }

    public function test_plan_never_calls_provider(): void
    {
        $driver = new AtlasForgeMinimaxM27CliInvocationDriver($this->stubRuntime());

        $plan = $driver->plan($this->validRequest());

        $this->assertFalse($plan['provider_called']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['provider_tokens_spent']);
    }

    public function test_invoke_passes_workspace_path(): void
    {
        $captured = null;
        $driver = new AtlasForgeMinimaxM27CliInvocationDriver($this->stubRuntime($captured));

        $request = $this->validRequest();
        $driver->invoke($request);

        $this->assertIsArray($captured);
        $this->assertSame('/tmp/atlas-minimax-workspace', data_get($captured, 'workspace.path'));
    }

    /**
     * @return array<string,mixed>
     */
    private function validRequest(): array
    {
        return [
            'model' => 'minimax-m3-base',
            'cwd' => '/tmp/atlas-minimax-workspace',
            'decision_receipt_id' => 'receipt_minimax_1',
            'decision_receipt_hash' => hash('sha256', 'receipt_minimax_1'),
            'prompt' => [
                'schema_version' => 'atlas.forge.provider_invocation_prompt.v1',
                'decision_receipt_id' => 'receipt_minimax_1',
                'decision_receipt_hash' => hash('sha256', 'receipt_minimax_1'),
                'scope_contract' => [
                    'allowed_files' => ['app/Foo.php'],
                    'forbidden_files' => ['.env'],
                ],
            ],
        ];
    }

    private function stubRuntime(?array &$capturedManifest = null): AtlasMinimaxM27CliRuntimeExecutor
    {
        $runtime = $this->createMock(AtlasMinimaxM27CliRuntimeExecutor::class);
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
