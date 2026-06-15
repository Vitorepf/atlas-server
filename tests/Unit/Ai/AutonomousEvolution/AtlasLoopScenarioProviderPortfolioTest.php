<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopScenarioProviderPortfolio;
use Tests\TestCase;

/**
 * Lever 4 — the cross-provider portfolio. Resolution is config-LAST (a task that pins a provider never
 * reads the container), and providerFor() rotates the N attempts across the engines for decorrelation.
 */
final class AtlasLoopScenarioProviderPortfolioTest extends TestCase
{
    public function test_task_scenario_providers_win_and_are_cleaned_and_deduped(): void
    {
        $pf = new AtlasLoopScenarioProviderPortfolio;
        $list = $pf->resolve(['scenario_providers' => [' codex ', 'minimax_m27', 'codex', '', 7]], 'hermes_cli');
        $this->assertSame(['codex', 'minimax_m27'], $list);
    }

    public function test_task_single_provider_override_never_reads_config(): void
    {
        // Pin an absurd config portfolio; the explicit task provider must still win WITHOUT consulting it.
        config(['atlas.loop.scenario_provider_portfolio' => ['should_not_be_used']]);
        $pf = new AtlasLoopScenarioProviderPortfolio;
        $this->assertSame(['test_provider'], $pf->resolve(['provider' => 'test_provider'], 'hermes_cli'));
    }

    public function test_config_portfolio_is_used_when_task_pins_nothing(): void
    {
        config(['atlas.loop.scenario_provider_portfolio' => ['hermes_cli', 'codex', 'minimax_m27']]);
        $pf = new AtlasLoopScenarioProviderPortfolio;
        $this->assertSame(['hermes_cli', 'codex', 'minimax_m27'], $pf->resolve([], 'hermes_cli'));
    }

    public function test_falls_back_to_single_default_when_nothing_configured(): void
    {
        config(['atlas.loop.scenario_provider_portfolio' => []]);
        $pf = new AtlasLoopScenarioProviderPortfolio;
        $this->assertSame(['hermes_cli'], $pf->resolve([], 'hermes_cli'));
    }

    public function test_provider_for_rotates_the_portfolio_by_attempt_index(): void
    {
        $pf = new AtlasLoopScenarioProviderPortfolio;
        $task = ['scenario_providers' => ['a', 'b', 'c']];
        $this->assertSame('a', $pf->providerFor($task, 0, 'd'));
        $this->assertSame('b', $pf->providerFor($task, 1, 'd'));
        $this->assertSame('c', $pf->providerFor($task, 2, 'd'));
        $this->assertSame('a', $pf->providerFor($task, 3, 'd'), 'cycles back');
    }
}
