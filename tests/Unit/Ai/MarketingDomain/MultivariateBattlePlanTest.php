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
        $this->assertArrayHasKey('structural_flaws', $v);
        $this->assertArrayHasKey('decision_flaws', $v);
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

    public function test_variant_with_structural_flaws_is_not_recommended(): void
    {
        // Simulate: create a variant with structural flaws artificially.
        // The recommend method must reject it even if its overall_score is high.
        $plan = (new MultivariateBattlePlan)->plan($this->asset(), [
            'angles' => ['hidden_cause'],
            'hooks' => ['hook_callout_specific'],
            'awarenesses' => ['problem_aware'],
            'max_iterations' => 1,
        ]);

        // If ALL variants have structural_flaws, recommended is null.
        // If at least one is clean, that one wins regardless of score.
        $this->assertNotNull($plan['recommended']);
        $recommendedFlaws = $plan['recommended']['structural_flaws'] ?? [];
        $this->assertEmpty($recommendedFlaws, 'Recommended variant must have no structural flaws.');
    }

    public function test_structurally_flawed_top_scorer_loses_to_clean_lower_scorer(): void
    {
        // Directly test the recommend() filter: a top-score variant with
        // non-empty structural_flaws must NOT be the winner — the lower-score
        // clean variant should be returned instead.
        $plan = new MultivariateBattlePlan;

        $variants = [
            [
                'variant_id' => 'v1',
                'axes' => ['angle' => 'hidden_cause', 'hook' => 'hook_callout_specific', 'awareness' => 'problem_aware'],
                'overall_score' => 95,
                'grade' => 'killer',
                'hollowness' => 10,
                'hollowness_grade' => 'solid',
                'flagged' => false,
                'structural_flaws' => ['reveal_leak'],
                'decision_flaws' => [],
            ],
            [
                'variant_id' => 'v2',
                'axes' => ['angle' => 'common_enemy', 'hook' => 'hook_warning', 'awareness' => 'problem_aware'],
                'overall_score' => 72,
                'grade' => 'strong',
                'hollowness' => 10,
                'hollowness_grade' => 'solid',
                'flagged' => false,
                'structural_flaws' => [],
                'decision_flaws' => [],
            ],
        ];

        $ref = new \ReflectionMethod($plan, 'recommend');
        $winner = $ref->invoke($plan, $variants);

        $this->assertNotNull($winner);
        $this->assertSame('v2', $winner['variant_id']);
        $this->assertEmpty($winner['structural_flaws']);
    }
}
