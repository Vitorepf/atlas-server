<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionOriginatorCadencePolicy;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorCoverageRuntimeBridge;
use Tests\TestCase;

final class AtlasExternalBrainOriginatorCoverageRuntimeBridgeTest extends TestCase
{
    public function test_route_to_gaps_true_names_undercovered_targets(): void
    {
        $route = (new AtlasExternalBrainOriginatorCoverageRuntimeBridge)->route([
            'route_to_gaps' => true,
            'reasons' => ['roadmap_gaps_overcovered'],
            'roadmap_coverage' => [
                'overcovered_gaps' => ['theme_a'],
                'next_batch_should_target' => ['theme_b', 'theme_c'],
            ],
        ]);

        self::assertTrue($route['pivot_required']);
        self::assertSame(['theme_b', 'theme_c'], $route['target_gaps']);
        self::assertSame('theme_b', $route['next_theme']);
    }

    public function test_route_to_gaps_false_names_no_targets(): void
    {
        $route = (new AtlasExternalBrainOriginatorCoverageRuntimeBridge)->route([
            'route_to_gaps' => false,
            'reasons' => ['no_saturation_or_diversity_concern'],
            'roadmap_coverage' => ['next_batch_should_target' => ['theme_b']],
        ]);

        self::assertFalse($route['pivot_required']);
        self::assertSame([], $route['target_gaps']);
        self::assertNull($route['next_theme']);
    }

    public function test_cadence_policy_pivots_to_undercovered_gaps_when_route_translated(): void
    {
        $route = (new AtlasExternalBrainOriginatorCoverageRuntimeBridge)->route([
            'route_to_gaps' => true,
            'reasons' => ['roadmap_gaps_overcovered'],
            'roadmap_coverage' => ['next_batch_should_target' => ['undercovered_theme']],
        ]);

        $decision = (new AtlasSelfConstructionOriginatorCadencePolicy)->decide([
            'claimable_depth' => 1,
            'blocked_count' => 0,
            'coverage_bridge' => $route,
        ]);

        self::assertSame(AtlasSelfConstructionOriginatorCadencePolicy::ACTION_SEED_NOW, $decision['action']);
        self::assertSame(['undercovered_theme'], $decision['target_gaps']);
        self::assertContains('pivot_to_undercovered_gaps', $decision['reasons']);
    }

    public function test_cadence_policy_has_no_target_gaps_when_pivot_not_required(): void
    {
        $route = (new AtlasExternalBrainOriginatorCoverageRuntimeBridge)->route([
            'route_to_gaps' => false,
        ]);

        $decision = (new AtlasSelfConstructionOriginatorCadencePolicy)->decide([
            'claimable_depth' => 1,
            'blocked_count' => 0,
            'coverage_bridge' => $route,
        ]);

        self::assertSame([], $decision['target_gaps']);
    }
}
