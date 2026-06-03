<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyFeatureSetProfile;
use PHPUnit\Framework\TestCase;

final class StrategyFeatureSetProfileTest extends TestCase
{
    public function test_price_only_is_the_only_active_default_feature_set(): void
    {
        $profile = (new StrategyFeatureSetProfile)->describe('price-only-v1');

        $this->assertSame('atlas.finance.strategy_feature_set.v1', $profile['schema_version']);
        $this->assertSame(StrategyFeatureSetProfile::PRICE_ONLY, $profile['feature_set_id']);
        $this->assertSame('active', $profile['status']);
        $this->assertTrue($profile['allowed_now']);
        $this->assertTrue($profile['active_in_default_search']);
        $this->assertSame(['ohlcv_price_history'], $profile['input_families']);
        $this->assertSame('forbidden', $profile['execution_surface']);
        $this->assertSame('every_feature_value_must_be_available_at_or_before_the_bar_decision_time', $profile['lookahead_policy']);
        $this->assertSame(0, $profile['activation_priority']);
        $this->assertSame('active_baseline', $profile['activation_phase']);
    }

    public function test_future_indices_are_deferred_and_require_feature_contracts(): void
    {
        $profiler = new StrategyFeatureSetProfile;
        $feature = $profiler->describe('news-sentiment-v1');
        $backlogIds = array_column($profiler->deferredBacklog(), 'feature_set_id');

        $this->assertSame('news_sentiment_v1', $feature['feature_set_id']);
        $this->assertFalse($feature['allowed_now']);
        $this->assertTrue($feature['ap_required_for_activation']);
        $this->assertContains('publication_time_no_lookahead_proof', $feature['activation_requirements']);
        $this->assertContains('cross_asset_context_v1', $backlogIds);
        $this->assertContains('orderbook_microstructure_v1', $backlogIds);
    }

    public function test_activation_roadmap_prioritizes_cleaner_market_structure_before_news(): void
    {
        $roadmap = (new StrategyFeatureSetProfile)->activationRoadmap();
        $byId = [];
        foreach ($roadmap as $entry) {
            $byId[$entry['feature_set_id']] = $entry;
        }

        $this->assertSame(StrategyFeatureSetProfile::PRICE_ONLY, $roadmap[0]['feature_set_id']);
        $this->assertSame(1, $byId['ohlcv_regime_index_v1']['activation_priority']);
        $this->assertLessThan($byId['news_sentiment_v1']['activation_priority'], $byId['derivatives_funding_oi_v1']['activation_priority']);
        $this->assertLessThan($byId['news_sentiment_v1']['activation_priority'], $byId['cross_asset_context_v1']['activation_priority']);
        $this->assertSame('late_experimental_only', $byId['news_sentiment_v1']['activation_phase']);
        $this->assertSame('very_high', $byId['news_sentiment_v1']['lookahead_risk']);
    }
}
