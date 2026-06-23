<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\BattleOutcomeAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Locks the discovery → learning loop: given the live outcomes of N orthogonal variants, the
 * analyzer picks the winner page AND surfaces the winning DECISION per axis (which angle / hook /
 * awareness drove the lift). Without this, the operator only knows "v3 won" — with this, they know
 * "common_enemy + warning hook won, regardless of awareness, promote those".
 */
class BattleOutcomeAnalyzerTest extends TestCase
{
    public function test_picks_the_winner_variant(): void
    {
        $r = (new BattleOutcomeAnalyzer)->analyze([
            ['variant_id' => 'v1', 'axes' => ['angle' => 'hidden_cause', 'hook' => 'h_warning', 'awareness' => 'problem_aware'], 'cvr' => 0.03],
            ['variant_id' => 'v2', 'axes' => ['angle' => 'common_enemy', 'hook' => 'h_warning', 'awareness' => 'problem_aware'], 'cvr' => 0.07],
            ['variant_id' => 'v3', 'axes' => ['angle' => 'common_enemy', 'hook' => 'h_callout', 'awareness' => 'problem_aware'], 'cvr' => 0.05],
        ]);

        $this->assertSame(3, $r['n']);
        $this->assertSame('v2', $r['winner']['variant_id']);
        $this->assertSame('v2', $r['recommendation']['promote']);
    }

    public function test_axis_lift_is_aggregated_per_axis(): void
    {
        $r = (new BattleOutcomeAnalyzer)->analyze([
            ['variant_id' => 'v1', 'axes' => ['angle' => 'A', 'hook' => 'X', 'awareness' => 'Z'], 'cvr' => 0.02],
            ['variant_id' => 'v2', 'axes' => ['angle' => 'A', 'hook' => 'Y', 'awareness' => 'Z'], 'cvr' => 0.04],
            ['variant_id' => 'v3', 'axes' => ['angle' => 'B', 'hook' => 'X', 'awareness' => 'Z'], 'cvr' => 0.08],
            ['variant_id' => 'v4', 'axes' => ['angle' => 'B', 'hook' => 'Y', 'awareness' => 'Z'], 'cvr' => 0.10],
        ]);

        // Angle B avg = 0.09, Angle A avg = 0.03 → B wins
        $this->assertSame('B', array_key_first($r['axis_lift']['angle']));
        // Hook Y avg = 0.07, Hook X avg = 0.05 → Y wins
        $this->assertSame('Y', array_key_first($r['axis_lift']['hook']));
    }

    public function test_axis_insight_when_lift_is_meaningful(): void
    {
        $r = (new BattleOutcomeAnalyzer)->analyze([
            ['variant_id' => 'v1', 'axes' => ['angle' => 'low', 'hook' => 'X', 'awareness' => 'Z'], 'cvr' => 0.02],
            ['variant_id' => 'v2', 'axes' => ['angle' => 'high', 'hook' => 'X', 'awareness' => 'Z'], 'cvr' => 0.08],
        ]);

        $this->assertGreaterThanOrEqual(1, count($r['recommendation']['axis_insights']));
        $this->assertStringContainsString("angle 'high'", $r['recommendation']['axis_insights'][0]);
    }

    public function test_empty_outcomes_returns_neutral(): void
    {
        $r = (new BattleOutcomeAnalyzer)->analyze([]);
        $this->assertSame(0, $r['n']);
        $this->assertNull($r['winner']);
        $this->assertNull($r['recommendation']['promote']);
    }
}
