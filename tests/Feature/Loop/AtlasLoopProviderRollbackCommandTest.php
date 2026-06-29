<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderRollbackPolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Proves the provider rollback policy is live at the operator surface: an open-circuit signal rolls back to the
 * previous stable provider; an open circuit during the architect phase escalates to the hard provider; healthy
 * signals keep the current provider.
 */
final class AtlasLoopProviderRollbackCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The policy constructor requires these provider defaults to exist.
        Config::set('atlas.provider_defaults.execution_runtime', 'minimax');
        Config::set('atlas.provider_defaults.brain_default', 'gpt');
    }

    private function decide(array $signals): array
    {
        $exit = Artisan::call('atlas:loop:provider-rollback', [
            '--signals' => (string) json_encode($signals),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_open_circuit_rolls_back(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->decide(['circuit_state' => 'open']);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.provider_rollback.v1', $d['schema']);
        $this->assertSame(AtlasLoopProviderRollbackPolicy::ROLLBACK_TO_PREVIOUS_STABLE, $d['decision'], (string) json_encode($d));
    }

    public function test_open_circuit_in_architect_phase_escalates(): void
    {
        ['d' => $d] = $this->decide(['circuit_open' => true, 'phase' => 'architect']);

        $this->assertSame(AtlasLoopProviderRollbackPolicy::ESCALATE_TO_HARD_PROVIDER, $d['decision'], (string) json_encode($d));
    }

    public function test_healthy_signals_keep_current_provider(): void
    {
        ['d' => $d] = $this->decide(['circuit_state' => 'closed', 'triangulator_verdict' => 'agree']);

        $this->assertSame(AtlasLoopProviderRollbackPolicy::KEEP_CURRENT_PROVIDER, $d['decision'], (string) json_encode($d));
    }

    public function test_missing_signals_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:provider-rollback', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
