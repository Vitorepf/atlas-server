<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\OwnerFlowPlanSliceCycleExecutor;
use ReflectionMethod;
use Tests\TestCase;

final class OwnerFlowPlanSliceCycleExecutorTest extends TestCase
{
    public function test_slice_provider_fit_overrides_non_explicit_runner_default(): void
    {
        config()->set('atlas_dev.provider.default_provider', 'minimax_m27_cli');
        config()->set('atlas.ai.providers.minimax_m27_cli.model', 'MiniMax-M2.7');
        config()->set('atlas.ai.providers.cursor_cli.model', 'composer-2.5-fast');

        $selection = $this->providerSelection(
            [
                'provider_fit' => [
                    'preferred_provider' => 'cursor_cli',
                    'preferred_model_family' => 'composer-2.5-fast',
                    'source' => 'atlas_decide',
                ],
            ],
            [
                'provider' => 'minimax_m27_cli',
                'model' => 'MiniMax-M2.7',
                'provider_explicit' => false,
                'model_explicit' => false,
            ],
        );

        $this->assertSame('cursor_cli', $selection['provider']);
        $this->assertSame('composer-2.5-fast', $selection['model']);
        $this->assertSame('slice_provider_fit', $selection['source']);
    }

    public function test_explicit_operator_provider_override_wins_over_slice_provider_fit(): void
    {
        config()->set('atlas.ai.providers.minimax_m27_cli.model', 'MiniMax-M2.7');

        $selection = $this->providerSelection(
            [
                'provider_fit' => [
                    'preferred_provider' => 'cursor_cli',
                    'preferred_model_family' => 'composer-2.5-fast',
                ],
            ],
            [
                'provider' => 'minimax_m27_cli',
                'model' => 'MiniMax-M2.7',
                'provider_explicit' => true,
                'model_explicit' => false,
            ],
        );

        $this->assertSame('minimax_m27_cli', $selection['provider']);
        $this->assertSame('MiniMax-M2.7', $selection['model']);
        $this->assertSame('operator_explicit_provider', $selection['source']);
    }

    /**
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function providerSelection(array $slice, array $context): array
    {
        $executor = new OwnerFlowPlanSliceCycleExecutor(app(AutonomousEvolutionSessionService::class));
        $method = new ReflectionMethod($executor, 'providerSelection');
        $method->setAccessible(true);

        return $method->invoke($executor, $slice, $context);
    }
}
