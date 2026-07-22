<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\AtlasDecide\HonestExplorationPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Maxk02HonestExplorationPolicyTest extends TestCase
{
    #[Test]
    public function disabled_policy_is_byte_style_noop(): void
    {
        $out = HonestExplorationPolicy::recommend([
            ['provider' => 'cheap', 'proven_success' => 10, 'failures' => 1, 'cost_units' => 1],
            ['provider' => 'cold', 'proven_success' => 0, 'failures' => 0, 'cost_units' => 1],
        ], ['enabled' => false, 'current_provider' => 'cheap']);

        $this->assertSame('cheap', $out['provider']);
        $this->assertSame('greedy_unchanged', $out['routing_basis']);
        $this->assertFalse($out['exploration_pick']);
    }

    #[Test]
    public function enabled_policy_samples_cold_provider_under_budget(): void
    {
        $out = HonestExplorationPolicy::recommend([
            ['provider' => 'proven', 'proven_success' => 20, 'failures' => 2, 'cost_units' => 1],
            ['provider' => 'cold', 'proven_success' => 0, 'failures' => 0, 'cost_units' => 2],
        ], [
            'enabled' => true,
            'current_provider' => 'proven',
            'privacy_class' => 'normal',
            'budget_used' => 1,
            'budget_cap' => 4,
        ]);

        $this->assertSame('cold', $out['provider']);
        $this->assertSame('exploration', $out['routing_basis']);
        $this->assertTrue($out['exploration_pick']);
        $this->assertSame(3, $out['budget_after']);
    }

    #[Test]
    public function sensitive_classes_never_explore_even_with_budget(): void
    {
        $out = HonestExplorationPolicy::recommend([
            ['provider' => 'proven', 'proven_success' => 20, 'failures' => 2, 'cost_units' => 1],
            ['provider' => 'cold', 'proven_success' => 0, 'failures' => 0, 'cost_units' => 1],
        ], [
            'enabled' => true,
            'current_provider' => 'proven',
            'privacy_class' => 'secret',
            'budget_used' => 0,
            'budget_cap' => 10,
        ]);

        $this->assertSame('proven', $out['provider']);
        $this->assertSame('blocked_sensitive_privacy_class', $out['basis']);
        $this->assertFalse($out['exploration_pick']);
    }

    #[Test]
    public function budget_cap_is_hard(): void
    {
        $out = HonestExplorationPolicy::recommend([
            ['provider' => 'proven', 'proven_success' => 20, 'failures' => 2, 'cost_units' => 1],
            ['provider' => 'cold', 'proven_success' => 0, 'failures' => 0, 'cost_units' => 2],
        ], [
            'enabled' => true,
            'current_provider' => 'proven',
            'privacy_class' => 'normal',
            'budget_used' => 9,
            'budget_cap' => 10,
        ]);

        $this->assertSame('proven', $out['provider']);
        $this->assertSame('budget_exhausted', $out['basis']);
        $this->assertFalse($out['exploration_pick']);
    }
}
