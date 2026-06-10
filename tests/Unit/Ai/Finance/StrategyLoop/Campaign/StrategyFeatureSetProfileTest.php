<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyFeatureSetProfile;
use PHPUnit\Framework\TestCase;

final class StrategyFeatureSetProfileTest extends TestCase
{
    public function test_price_only_baseline_feature_set_stays_active(): void
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

    public function test_regime_index_is_activated_with_executable_evidence(): void
    {
        $profile = (new StrategyFeatureSetProfile)->describe('ohlcv-regime-index-v1');

        $this->assertSame('active', $profile['status']);
        $this->assertTrue($profile['allowed_now']);
        $this->assertSame(1, $profile['activation_priority']);
        $this->assertSame('forbidden', $profile['execution_surface']);
        // A ativação só vale com evidência executável de cada requisito do contrato.
        foreach (['pre_register_feature_family', 'prove_no_future_window_leakage', 'compare_against_price_only_baseline'] as $req) {
            $this->assertContains($req, $profile['activation_requirements']);
            $this->assertArrayHasKey($req, $profile['activation_evidence']);
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $profile['derived_feature_code_hash'], 'manifest exige o sha256 real do código das features');
        $this->assertNotEmpty($profile['feature_window_policy']);
    }

    public function test_funding_set_is_activated_funding_only_with_publish_time_evidence(): void
    {
        $profile = (new StrategyFeatureSetProfile)->describe('derivatives-funding-oi-v1');

        $this->assertSame('active', $profile['status']);
        $this->assertTrue($profile['allowed_now']);
        $this->assertSame(2, $profile['activation_priority']);
        $this->assertSame('forbidden', $profile['execution_surface']);
        $this->assertSame('funding_only_open_interest_deferred_no_deep_history_source', $profile['scope']);
        foreach (['AP_contract_for_derivatives_data', 'funding_timestamp_policy', 'survivorship_and_exchange_coverage_controls'] as $req) {
            $this->assertContains($req, $profile['activation_requirements']);
            $this->assertArrayHasKey($req, $profile['activation_evidence']);
        }
        // OI segue explicitamente adiado dentro do set — não pode sumir em silêncio.
        $this->assertStringContainsString('DEFERRED', (string) $profile['activation_evidence']['open_interest']);
        $this->assertNotEmpty($profile['publish_time_policy']);
        // Manifest: hash por símbolo da fita congelada (sha256 real quando a fita existe).
        foreach (['BTCUSDT', 'ETHUSDT', 'SOLUSDT'] as $symbol) {
            $this->assertArrayHasKey($symbol, $profile['funding_source_hash']);
        }
    }

    public function test_active_and_deferred_lists_are_disjoint_and_complete(): void
    {
        $profiler = new StrategyFeatureSetProfile;

        foreach ($profiler->activeFeatureSetIds() as $id) {
            $this->assertTrue((bool) $profiler->describe($id)['allowed_now'], $id.' listado como ativo deve estar allowed_now');
        }
        foreach ($profiler->deferredFeatureSetIds() as $id) {
            $profile = $profiler->describe($id);
            $this->assertFalse((bool) $profile['allowed_now'], $id.' listado como deferred não pode estar allowed_now');
            $this->assertTrue((bool) $profile['ap_required_for_activation'], $id.' deferred exige AP para ativar');
        }
        $this->assertSame([], array_intersect($profiler->activeFeatureSetIds(), $profiler->deferredFeatureSetIds()));
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
        $this->assertNotContains('ohlcv_regime_index_v1', $backlogIds, 'ativado: não pode continuar listado como deferred');
        $this->assertNotContains('derivatives_funding_oi_v1', $backlogIds, 'ativado (funding-only): não pode continuar listado como deferred');
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
