<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeAntigravitySdkInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeClaudeCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeCodexCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeCursorCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeCursorSdkInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeGeminiCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeMinimaxM27CliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeMinimaxM27InvocationDriver;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use Tests\TestCase;

/**
 * Focused contract tests for AtlasForgeProviderInvocationDriverRouter (factory-critical runtime).
 */
final class AtlasForgeProviderInvocationDriverRouterTest extends TestCase
{
    public function test_focused_unit_test_path_is_same_name_coverage(): void
    {
        $this->assertSame(
            'tests/Unit/Ai/Programming/AtlasForgeProviderInvocationDriverRouterTest.php',
            AtlasForgeProviderInvocationDriverRouter::focusedUnitTestPath(),
        );
    }

    public function test_claude_codex_has_composite_runtime_driver_when_council_arms_are_registered(): void
    {
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);

        $this->assertTrue($router->supports(AtlasForgeProviderInvocationDriverRouter::DRIVER_CLAUDE_CODEX));
        $this->assertTrue($router->hasRuntimeDriver(AtlasForgeProviderInvocationDriverRouter::DRIVER_CLAUDE_CODEX));
    }

    public function test_plan_for_claude_codex_reports_driver_available_without_missing_driver_blocker(): void
    {
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);

        $plan = $router->plan(
            AtlasForgeProviderInvocationDriverRouter::DRIVER_CLAUDE_CODEX,
            'council_default',
            ['schema_version' => 'atlas.forge.provider_invocation_prompt.v1'],
        );

        $this->assertTrue($plan['supports']);
        $this->assertTrue($plan['driver_available']);
        $this->assertNotSame(
            AtlasForgeProviderInvocationDriverRouter::BLOCKER_PROVIDER_INVOCATION_NOT_CONFIGURED,
            $plan['driver_blocker'],
        );
    }

    public function test_driver_status_for_claude_codex_is_composite_not_unknown_driver_missing(): void
    {
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);

        $status = $router->driverStatus(AtlasForgeProviderInvocationDriverRouter::DRIVER_CLAUDE_CODEX);

        $this->assertSame(AtlasForgeProviderInvocationDriverRouter::DRIVER_CLAUDE_CODEX, $status['provider']);
        $this->assertTrue($status['runtime_present']);
        $this->assertNotContains(
            AtlasForgeProviderInvocationDriverRouter::BLOCKER_PROVIDER_DRIVER_MISSING,
            $status['blockers'] ?? [],
        );
    }

    public function test_invoke_blocks_claude_codex_without_calling_single_cli_delegate(): void
    {
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);

        $result = $router->invoke(
            AtlasForgeProviderInvocationDriverRouter::DRIVER_CLAUDE_CODEX,
            'council_default',
            ['schema_version' => 'atlas.forge.provider_invocation_prompt.v1'],
        );

        $this->assertSame(
            AtlasForgeProviderInvocationDriverRouter::BLOCKER_PROVIDER_INVOCATION_NOT_CONFIGURED,
            $result['blocker'],
        );
        $this->assertFalse($result['provider_called']);
        $this->assertFalse($result['external_provider_call']);
    }

    public function test_minimax_m27_is_canonical_driver(): void
    {
        $this->assertContains(
            AtlasForgeProviderInvocationDriverRouter::DRIVER_MINIMAX_M27,
            AtlasForgeProviderInvocationDriverRouter::CANONICAL_DRIVERS,
        );
    }

    public function test_minimax_m27_cli_is_canonical_driver(): void
    {
        $this->assertContains(
            AtlasForgeProviderInvocationDriverRouter::DRIVER_MINIMAX_M27_CLI,
            AtlasForgeProviderInvocationDriverRouter::CANONICAL_DRIVERS,
        );
    }

    public function test_minimax_m27_has_runtime_driver(): void
    {
        $router = $this->routerWithStubMinimaxDrivers(configured: false);

        $this->assertTrue($router->hasRuntimeDriver(AtlasForgeProviderInvocationDriverRouter::DRIVER_MINIMAX_M27));
    }

    public function test_minimax_m27_cli_has_runtime_driver(): void
    {
        $router = $this->routerWithStubMinimaxDrivers(configured: false);

        $this->assertTrue($router->hasRuntimeDriver(AtlasForgeProviderInvocationDriverRouter::DRIVER_MINIMAX_M27_CLI));
    }

    public function test_plan_with_minimax_m27_unconfigured_returns_driver_blocker(): void
    {
        $router = $this->routerWithStubMinimaxDrivers(configured: false);

        $plan = $router->plan(
            AtlasForgeProviderInvocationDriverRouter::DRIVER_MINIMAX_M27,
            'MiniMax-M3',
            ['schema_version' => 'atlas.forge.provider_invocation_prompt.v1'],
        );

        $this->assertTrue($plan['supports']);
        $this->assertTrue($plan['driver_available']);
        $this->assertSame(
            AtlasForgeProviderInvocationDriverRouter::BLOCKER_PROVIDER_DRIVER_NOT_CONFIGURED,
            $plan['driver_blocker'],
        );
    }

    public function test_driver_status_includes_minimax_m27(): void
    {
        $router = $this->routerWithStubMinimaxDrivers(configured: false);

        $status = $router->driverStatus();

        $providers = array_column($status['drivers'], 'provider');
        $this->assertContains(
            AtlasForgeProviderInvocationDriverRouter::DRIVER_MINIMAX_M27,
            $providers,
        );
    }

    /**
     * Builds a router where all non-MiniMax drivers are resolved from the
     * container and the two MiniMax drivers are replaced with lightweight mocks
     * that return a controlled configured() payload.
     */
    private function routerWithStubMinimaxDrivers(bool $configured): AtlasForgeProviderInvocationDriverRouter
    {
        $minimaxDriver = $this->createMock(AtlasForgeMinimaxM27InvocationDriver::class);
        $minimaxDriver->method('provider')->willReturn(AtlasForgeMinimaxM27InvocationDriver::PROVIDER);
        $minimaxDriver->method('configured')->willReturn([
            'schema_version' => 'atlas.forge.provider_driver_config_status.v1',
            'provider' => AtlasForgeMinimaxM27InvocationDriver::PROVIDER,
            'configured' => $configured,
            'runtime_present' => true,
            'blockers' => $configured ? [] : ['minimax_m27_disabled'],
        ]);

        $minimaxCliDriver = $this->createMock(AtlasForgeMinimaxM27CliInvocationDriver::class);
        $minimaxCliDriver->method('provider')->willReturn(AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER);
        $minimaxCliDriver->method('configured')->willReturn([
            'schema_version' => 'atlas.forge.provider_driver_config_status.v1',
            'provider' => AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
            'configured' => $configured,
            'runtime_present' => true,
            'blockers' => $configured ? [] : ['minimax_m27_cli_disabled'],
        ]);

        return new AtlasForgeProviderInvocationDriverRouter(
            app(AtlasForgeClaudeCliInvocationDriver::class),
            app(AtlasForgeCodexCliInvocationDriver::class),
            app(AtlasForgeGeminiCliInvocationDriver::class),
            app(AtlasForgeAntigravitySdkInvocationDriver::class),
            app(AtlasForgeCursorSdkInvocationDriver::class),
            app(AtlasForgeCursorCliInvocationDriver::class),
            $minimaxDriver,
            $minimaxCliDriver,
        );
    }
}
