<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * Governs which information a strategy campaign may use.
 *
 * Today the live search is deliberately price-only. Future indices, news,
 * on-chain, derivatives, or order-book data must enter as explicit feature
 * sets with data manifests and anti-lookahead controls before they can run.
 */
final class StrategyFeatureSetProfile
{
    public const PRICE_ONLY = 'price_only_v1';

    /**
     * @return array<string,mixed>
     */
    public function describe(string $featureSetId = self::PRICE_ONLY): array
    {
        $id = $this->normalize($featureSetId);
        $profile = match ($id) {
            self::PRICE_ONLY => [
                'status' => 'active',
                'active_in_default_search' => true,
                'allowed_now' => true,
                'input_families' => ['ohlcv_price_history'],
                'source_manifest_requirements' => ['market_data_sha256', 'scoring_range', 'holdout_range', 'confirmation_holdout_range'],
                'candidate_access_policy' => 'candidate_may_read_price_features_only_cannot_modify_data_costs_or_harness',
                'activation_requirements' => [],
                'ap_required_for_activation' => false,
                'activation_priority' => 0,
                'activation_phase' => 'active_baseline',
                'research_value' => 'price_only_control_group_for_every_future_feature_set',
                'data_risk' => 'low',
                'lookahead_risk' => 'low',
                'suitable_timeframes' => ['1d', '4h'],
                'activation_decision' => 'active_default',
            ],
            'ohlcv_regime_index_v1' => $this->deferred(
                'derived_price_index',
                ['market_data_sha256', 'derived_feature_code_hash', 'feature_window_policy'],
                ['pre_register_feature_family', 'prove_no_future_window_leakage', 'compare_against_price_only_baseline'],
                1,
                'first_future_candidate',
                'regime_volatility_liquidity_context_from_existing_ohlcv',
                'low',
                'medium',
                ['1d', '4h'],
            ),
            'cross_asset_context_v1' => $this->deferred(
                'cross_market_context',
                ['primary_market_data_sha256', 'context_market_data_sha256', 'timestamp_alignment_policy'],
                ['AP_contract_for_cross_asset_features', 'point_in_time_join_proof', 'separate_feature_campaign_budget'],
                3,
                'market_context_after_derivatives',
                'btc_eth_sol_relative_strength_and_correlation_context',
                'medium',
                'high',
                ['1d', '4h'],
            ),
            'derivatives_funding_oi_v1' => $this->deferred(
                'crypto_derivatives_context',
                ['funding_source_hash', 'open_interest_source_hash', 'publish_time_policy'],
                ['AP_contract_for_derivatives_data', 'funding_timestamp_policy', 'survivorship_and_exchange_coverage_controls'],
                2,
                'crypto_native_context_after_price_regimes',
                'funding_open_interest_basis_and_crowding_context',
                'medium',
                'high',
                ['1d', '4h'],
            ),
            'onchain_flow_v1' => $this->deferred(
                'onchain_context',
                ['onchain_source_hash', 'entity_mapping_policy', 'publish_time_policy'],
                ['AP_contract_for_onchain_data', 'latency_model', 'entity_mapping_audit'],
                5,
                'long_horizon_optional',
                'exchange_flow_and_network_activity_context_for_slow_campaigns',
                'high',
                'high',
                ['1d', '1w', '1mo'],
            ),
            'news_sentiment_v1' => $this->deferred(
                'news_sentiment_context',
                ['source_snapshot_hash', 'publication_timestamp_policy', 'dedupe_policy'],
                ['AP_contract_for_news_features', 'publication_time_no_lookahead_proof', 'source_reliability_review'],
                6,
                'late_experimental_only',
                'event_context_only_after_cleaner_market_structure_features_fail_or_plateau',
                'very_high',
                'very_high',
                ['1d'],
            ),
            'orderbook_microstructure_v1' => $this->deferred(
                'microstructure_context',
                ['orderbook_snapshot_hash', 'spread_liquidity_model_hash', 'latency_policy'],
                ['AP_contract_for_microstructure_data', 'variable_slippage_model', 'intraday_second_engine_support'],
                4,
                'intraday_cost_context_after_higher_timeframe_baselines',
                'spread_depth_slippage_and_liquidity_context_for_intraday_campaigns',
                'high',
                'high',
                ['4h', '1h', '15m'],
            ),
            default => [
                'status' => 'blocked_unknown_feature_set',
                'active_in_default_search' => false,
                'allowed_now' => false,
                'input_families' => [],
                'source_manifest_requirements' => ['explicit_feature_set_contract'],
                'candidate_access_policy' => 'blocked_until_feature_set_profile_exists',
                'activation_requirements' => ['define_feature_set_profile', 'define_data_manifest', 'prove_no_lookahead'],
                'ap_required_for_activation' => true,
                'activation_priority' => 999,
                'activation_phase' => 'blocked_unknown',
                'research_value' => 'unknown_until_profiled',
                'data_risk' => 'unknown',
                'lookahead_risk' => 'unknown',
                'suitable_timeframes' => [],
                'activation_decision' => 'blocked',
            ],
        };

        return [
            'schema_version' => 'atlas.finance.strategy_feature_set.v1',
            'feature_set_id' => $id,
            'feature_set_transfer_policy' => 'do_not_compare_or_merge_across_feature_sets_without_explicit_campaign',
            'lookahead_policy' => 'every_feature_value_must_be_available_at_or_before_the_bar_decision_time',
            'data_manifest_policy' => 'every_non_price_input_requires_source_hash_time_range_and_publish_time_policy',
            'execution_surface' => 'forbidden',
            'propose_only' => true,
        ] + $profile;
    }

    /** @return list<array<string,mixed>> */
    public function deferredBacklog(): array
    {
        return array_map(
            fn (string $id): array => $this->describe($id),
            [
                'ohlcv_regime_index_v1',
                'derivatives_funding_oi_v1',
                'cross_asset_context_v1',
                'orderbook_microstructure_v1',
                'onchain_flow_v1',
                'news_sentiment_v1',
            ],
        );
    }

    /** @return list<array<string,mixed>> */
    public function activationRoadmap(): array
    {
        $roadmap = [
            $this->describe(self::PRICE_ONLY),
            ...$this->deferredBacklog(),
        ];
        usort(
            $roadmap,
            static fn (array $a, array $b): int => (int) ($a['activation_priority'] ?? 999) <=> (int) ($b['activation_priority'] ?? 999),
        );

        return $roadmap;
    }

    public function normalize(string $featureSetId): string
    {
        $id = strtolower(trim($featureSetId));
        $id = str_replace(['-', ' '], '_', $id);

        return $id !== '' ? $id : self::PRICE_ONLY;
    }

    /**
     * @param  list<string>  $manifestRequirements
     * @param  list<string>  $activationRequirements
     * @param  list<string>  $suitableTimeframes
     * @return array<string,mixed>
     */
    private function deferred(string $family, array $manifestRequirements, array $activationRequirements, int $priority, string $phase, string $researchValue, string $dataRisk, string $lookaheadRisk, array $suitableTimeframes): array
    {
        return [
            'status' => 'deferred_until_feature_contract_and_data_controls',
            'active_in_default_search' => false,
            'allowed_now' => false,
            'input_families' => [$family],
            'source_manifest_requirements' => $manifestRequirements,
            'candidate_access_policy' => 'blocked_until_governed_feature_campaign',
            'activation_requirements' => $activationRequirements,
            'ap_required_for_activation' => true,
            'activation_priority' => $priority,
            'activation_phase' => $phase,
            'research_value' => $researchValue,
            'data_risk' => $dataRisk,
            'lookahead_risk' => $lookaheadRisk,
            'suitable_timeframes' => $suitableTimeframes,
            'activation_decision' => 'deferred_fail_closed',
        ];
    }
}
