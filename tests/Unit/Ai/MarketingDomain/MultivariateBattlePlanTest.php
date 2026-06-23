<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\MultivariateBattlePlan;
use PHPUnit\Framework\TestCase;

/**
 * Locks the multivariate orthogonal plan: given an asset and axis arrays, the plan generates the
 * full cartesian product of variants, each one orchestrated with the angle/hook PINNED, the
 * awareness PINNED in the asset clone, the hollowness checked, and a recommendation surfaced. This
 * is what lets the operator split-test by AXIS, not by random page.
 */
class MultivariateBattlePlanTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'core_promise' => 'lose weight without injections',
            'big_idea' => 'three hormones in sync',
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'persuasion_devices' => ['authority' => ['Melania Trump'], 'conspiracy' => ['big pharma hides it']],
            'metrics' => ['result_claims' => ['63 lbs em 2 meses']],
            'target_geo' => 'US / English',
            'awareness_level' => 'problem_aware',
        ]);
    }

    public function test_generates_cartesian_product_of_axes(): void
    {
        $plan = (new MultivariateBattlePlan)->plan($this->asset(), [
            'angles' => ['hidden_cause', 'common_enemy'],
            'hooks' => ['hook_callout_specific', 'hook_warning'],
            'awarenesses' => ['problem_aware'],
            'max_iterations' => 1,
        ]);

        $this->assertSame(4, $plan['n_variants']);  // 2 × 2 × 1
        $this->assertCount(4, $plan['variants']);
    }

    public function test_each_variant_carries_its_axes_and_audit(): void
    {
        $plan = (new MultivariateBattlePlan)->plan($this->asset(), [
            'angles' => ['hidden_cause'],
            'hooks' => ['hook_callout_specific'],
            'awarenesses' => ['problem_aware'],
            'max_iterations' => 1,
        ]);
        $v = $plan['variants'][0];

        $this->assertArrayHasKey('axes', $v);
        $this->assertSame('hidden_cause', $v['axes']['angle']);
        $this->assertSame('hook_callout_specific', $v['axes']['hook']);
        $this->assertSame('problem_aware', $v['axes']['awareness']);
        $this->assertArrayHasKey('overall_score', $v);
        $this->assertArrayHasKey('hollowness', $v);
        $this->assertArrayHasKey('html', $v);
    }

    public function test_pinned_axes_actually_differentiate_variants(): void
    {
        $plan = (new MultivariateBattlePlan)->plan($this->asset(), [
            'angles' => ['common_enemy', 'forbidden_discovery'],
            'hooks' => ['hook_callout_specific'],
            'awarenesses' => ['problem_aware'],
            'max_iterations' => 1,
        ]);
        $kickers = array_unique(array_map(fn ($v) => (string) ($v['bridge']['kicker'] ?? ''), $plan['variants']));
        $this->assertGreaterThanOrEqual(2, count($kickers),
            'Different angles must produce different kickers (pin_angle wired in seed).');
    }

    public function test_recommendation_picks_non_flagged_high_scorer(): void
    {
        $plan = (new MultivariateBattlePlan)->plan($this->asset(), [
            'angles' => ['hidden_cause'],
            'hooks' => ['hook_callout_specific'],
            'awarenesses' => ['problem_aware'],
            'max_iterations' => 1,
        ]);

        $this->assertNotNull($plan['recommended']);
        $this->assertFalse((bool) ($plan['recommended']['flagged'] ?? false));
    }
}
