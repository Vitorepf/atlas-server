<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the cross-cycle pattern miner is live at the operator surface: a token recurring across >=2 injected
 * cycle episodes surfaces as a pattern, while too few episodes report insufficient_episodes (never a crash).
 */
final class AtlasLoopCrossCyclePatternsCommandTest extends TestCase
{
    public function test_recurring_token_across_episodes_is_a_pattern(): void
    {
        $this->app->bind('atlas.loop.cross_cycle.episodes', fn (): array => [
            ['episode_id' => 'e1', 'tokens' => ['orphan_wiring', 'complexity']],
            ['episode_id' => 'e2', 'tokens' => ['orphan_wiring', 'docs']],
            ['episode_id' => 'e3', 'tokens' => ['orphan_wiring']],
        ]);

        $exit = Artisan::call('atlas:loop:cross-cycle-patterns', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $decoded['status']);
        $this->assertContains('orphan_wiring', array_column($decoded['patterns'], 'token'), 'a token in >=2 episodes is a cross-cycle pattern');
    }

    public function test_too_few_episodes_reports_insufficient_without_crashing(): void
    {
        $this->app->bind('atlas.loop.cross_cycle.episodes', fn (): array => [
            ['episode_id' => 'only', 'tokens' => ['lonely']],
        ]);

        $exit = Artisan::call('atlas:loop:cross-cycle-patterns', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('insufficient_episodes', $decoded['status']);
        $this->assertSame([], $decoded['patterns']);
    }
}
