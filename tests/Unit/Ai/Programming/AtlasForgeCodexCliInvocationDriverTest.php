<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeCodexCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeProviderCommandAllowlistService;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationFailureClassifier;
use App\Services\Ai\Programming\AtlasForgeProviderProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Focused contract tests for AtlasForgeCodexCliInvocationDriver (factory-critical runtime).
 */
final class AtlasForgeCodexCliInvocationDriverTest extends TestCase
{
    public function test_provider_returns_codex_cli_constant(): void
    {
        $driver = $this->driver();

        $this->assertSame('codex_cli', AtlasForgeCodexCliInvocationDriver::PROVIDER);
        $this->assertSame('codex_cli', $driver->provider());
    }

    public function test_focused_unit_test_path_is_same_name_coverage(): void
    {
        $this->assertSame(
            'tests/Unit/Ai/Programming/AtlasForgeCodexCliInvocationDriverTest.php',
            AtlasForgeCodexCliInvocationDriver::focusedUnitTestPath(),
        );
    }

    public function test_supports_rejects_other_providers(): void
    {
        $driver = $this->driver();

        $this->assertFalse($driver->supports('claude_cli', 'gpt-5'));
        $this->assertFalse($driver->supports('cursor_cli', null));
    }

    public function test_supports_accepts_null_or_blank_model(): void
    {
        $driver = $this->driver();

        $this->assertTrue($driver->supports('codex_cli', null));
        $this->assertTrue($driver->supports('codex_cli', ''));
    }

    #[DataProvider('supportedModelProvider')]
    public function test_supports_accepts_known_model_prefixes(string $model): void
    {
        $driver = $this->driver();

        $this->assertTrue($driver->supports('codex_cli', $model));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function supportedModelProvider(): array
    {
        return [
            'gpt prefix' => ['gpt-5.5'],
            'codex prefix' => ['codex-mini'],
            'o1 prefix' => ['o1-preview'],
            'o3 prefix' => ['o3-pro'],
            'uppercase normalized' => ['GPT-5-MINI'],
        ];
    }

    public function test_supports_rejects_unknown_model_prefix(): void
    {
        $driver = $this->driver();

        $this->assertFalse($driver->supports('codex_cli', 'claude-sonnet-4'));
    }

    public function test_plan_does_not_call_provider(): void
    {
        $driver = $this->driver();

        $plan = $driver->plan(['model' => 'gpt-5.5']);

        $this->assertSame('atlas.forge.provider_driver_plan.v1', $plan['schema_version']);
        $this->assertFalse($plan['provider_called']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['provider_tokens_spent']);
    }

    public function test_configured_status_schema_includes_codex_contract_fields(): void
    {
        $driver = $this->driver();

        $status = $driver->configured();

        $this->assertSame('atlas.forge.provider_driver_config_status.v1', $status['schema_version']);
        $this->assertSame('codex_cli', $status['provider']);
        $this->assertContains('codex', $status['allowed_binaries']);
        $configuredBinary = config('atlas.ai.providers.codex_cli.binary');
        if (is_string($configuredBinary) && trim($configuredBinary) !== '') {
            $this->assertContains(trim($configuredBinary), $status['allowed_binaries']);
        }
        $this->assertSame(['gpt-', 'codex', 'o1', 'o3'], $status['model_prefixes']);
        $this->assertIsBool($status['configured']);
        $this->assertIsArray($status['blockers']);
        if (! (bool) $status['configured']) {
            $this->assertNotSame([], $status['blockers']);
        }
    }

    private function driver(): AtlasForgeCodexCliInvocationDriver
    {
        $allowlist = $this->createMock(AtlasForgeProviderCommandAllowlistService::class);
        $allowlist->method('evaluate')->willReturn([
            'allowed' => true,
            'blockers' => [],
        ]);

        $runner = $this->createMock(AtlasForgeProviderProcessRunner::class);
        $classifier = $this->createMock(AtlasForgeProviderInvocationFailureClassifier::class);

        return new AtlasForgeCodexCliInvocationDriver($allowlist, $runner, $classifier);
    }
}
