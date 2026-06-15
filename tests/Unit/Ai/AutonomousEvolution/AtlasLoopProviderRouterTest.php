<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderRouter;
use PHPUnit\Framework\TestCase;

/**
 * Lever 5 provider routing: cheap tier (MiniMax) for bulk/exploratory classes, strong tier
 * (codex/gpt-5.5) for load-bearing implementation — fail-safe to the default in every doubt.
 */
final class AtlasLoopProviderRouterTest extends TestCase
{
    private function routing(): array
    {
        return [
            'enabled' => true,
            'cheap_provider' => 'minimax_m27',
            'cheap_model' => 'MiniMax-M3',
            'cheap_classes' => ['characterization_test', 'edge_fix'],
        ];
    }

    public function test_cheap_class_routes_to_minimax_when_configured(): void
    {
        $r = (new AtlasLoopProviderRouter())->route('characterization_test', 'hermes_cli', 'gpt-5.5', $this->routing(), fn () => true);
        $this->assertSame('minimax_m27', $r['provider']);
        $this->assertSame('MiniMax-M3', $r['model']);
        $this->assertSame('cheap', $r['tier']);
    }

    public function test_load_bearing_class_stays_strong(): void
    {
        $r = (new AtlasLoopProviderRouter())->route('refactor_extract_class', 'hermes_cli', 'gpt-5.5', $this->routing(), fn () => true);
        $this->assertSame('hermes_cli', $r['provider']);
        $this->assertSame('gpt-5.5', $r['model']);
        $this->assertSame('strong', $r['tier']);
    }

    public function test_fail_safe_when_cheap_provider_unconfigured(): void
    {
        // A cheap-eligible class but the cheap provider's CLI is not configured => fall back to strong.
        $r = (new AtlasLoopProviderRouter())->route('characterization_test', 'hermes_cli', 'gpt-5.5', $this->routing(), fn () => false);
        $this->assertSame('hermes_cli', $r['provider'], 'never route to an unconfigured provider');
        $this->assertSame('strong', $r['tier']);
    }

    public function test_disabled_is_byte_identical_default(): void
    {
        $routing = $this->routing();
        $routing['enabled'] = false;
        $r = (new AtlasLoopProviderRouter())->route('characterization_test', 'hermes_cli', 'gpt-5.5', $routing, fn () => true);
        $this->assertSame('hermes_cli', $r['provider']);
        $this->assertSame('gpt-5.5', $r['model']);
        $this->assertSame('strong', $r['tier']);
    }
}
