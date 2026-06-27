<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPerceptionBundle;
use Tests\TestCase;

final class AtlasBrainPerceptionBundleTest extends TestCase
{
    public function test_build_returns_every_perception_block(): void
    {
        $bundle = app(AtlasBrainPerceptionBundle::class);
        $r = $bundle->build('loop');

        self::assertSame('loop', $r['scope']);
        foreach (['brief_histogram', 'result_kind_histogram', 'hint_entropy', 'hint_transitions', 'starvation_trend', 'evidence_freshness', 'path_starvation', 'cascade_outcomes', 'path_diversity_score', 'concentration_hhi', 'path_yield_momentum', 'path_oscillation', 'hint_bursts', 'repeated_refusal_anti_patterns', 'compounding_velocity', 'path_yield_ewma', 'path_streaks', 'path_signal_agreement'] as $key) {
            self::assertArrayHasKey($key, $r);
        }
    }

    public function test_bundle_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPerceptionBundle.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
