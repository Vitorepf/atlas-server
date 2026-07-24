<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\FundingTape;
use App\Services\Ai\Finance\StrategyLoop\Strategy\RegimeAdaptiveStrategy;

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
            // ATIVADO 2026-06-10 (operador): era o priority-1 do backlog. Cada requisito
            // de ativação tem evidência executável registrada em activation_evidence.
            'ohlcv_regime_index_v1' => [
                'status' => 'active',
                'active_in_default_search' => true,
                'allowed_now' => true,
                'input_families' => ['derived_price_index'],
                'source_manifest_requirements' => ['market_data_sha256', 'derived_feature_code_hash', 'feature_window_policy'],
                'candidate_access_policy' => 'candidate_may_read_derived_regime_features_only_cannot_modify_data_costs_or_harness',
                'activation_requirements' => ['pre_register_feature_family', 'prove_no_future_window_leakage', 'compare_against_price_only_baseline'],
                'activation_evidence' => [
                    'pre_register_feature_family' => 'features fixas pré-registradas: kaufman_efficiency_ratio + realized_vol_percentile (RegimeAdaptiveStrategy, família regime-adaptive-v1)',
                    'prove_no_future_window_leakage' => 'RegimeAdaptiveStrategyLookAheadTest::test_no_lookahead_prefix_invariance',
                    'compare_against_price_only_baseline' => 'scenario key inclui feature_set_id: campanhas regime rodam separadas; cenários price_only seguem como grupo de controle',
                    'activated_at' => '2026-06-10',
                    'activated_by' => 'operator',
                ],
                'derived_feature_code_hash' => $this->regimeFeatureCodeHash(),
                'feature_window_policy' => 'all_feature_windows_end_at_decision_bar_t_and_vol_rank_history_is_itself_causal',
                'ap_required_for_activation' => false,
                'activation_priority' => 1,
                'activation_phase' => 'active_regime_index',
                'research_value' => 'regime_volatility_liquidity_context_from_existing_ohlcv',
                'data_risk' => 'low',
                'lookahead_risk' => 'medium',
                'suitable_timeframes' => ['1d', '4h'],
                'activation_decision' => 'activated_2026_06_10_operator_requirements_implemented',
            ],
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
            // ATIVADO 2026-06-10 (operador, salto 3) — escopo FUNDING-ONLY: a Binance só
            // mantém ~30d de histórico de open interest, então OI permanece adiado DENTRO
            // do set até existir fonte com histórico profundo auditável.
            'derivatives_funding_oi_v1' => [
                'status' => 'active',
                'active_in_default_search' => true,
                'allowed_now' => true,
                'input_families' => ['crypto_derivatives_context'],
                'scope' => 'funding_only_open_interest_deferred_no_deep_history_source',
                'source_manifest_requirements' => ['funding_source_hash', 'publish_time_policy'],
                'candidate_access_policy' => 'candidate_may_read_funding_features_only_cannot_modify_data_costs_or_harness',
                'activation_requirements' => ['AP_contract_for_derivatives_data', 'funding_timestamp_policy', 'survivorship_and_exchange_coverage_controls'],
                'activation_evidence' => [
                    'AP_contract_for_derivatives_data' => 'este profile + família funding-extreme-v1 (FundingExtremeStrategy) definem o contrato: fita congelada injetada, candidato nunca toca dados',
                    'funding_timestamp_policy' => 'FundingExtremeStrategyLookAheadTest::test_no_lookahead_prefix_invariance_truncating_funding_tape (evento conhecível só a partir de funding_time)',
                    'survivorship_and_exchange_coverage_controls' => 'universo fixo BTC/ETH/SOL perp USDT da Binance (mesmos símbolos do baseline de preço; sem seleção pós-fato); dumps mensais públicos data.binance.vision',
                    'open_interest' => 'DEFERRED: Binance não publica histórico profundo de OI (~30d) — ativar OI exige nova fonte + nova evidência',
                    'activated_at' => '2026-06-10',
                    'activated_by' => 'operator',
                ],
                'funding_source_hash' => $this->fundingSourceHashes(),
                'publish_time_policy' => 'funding_event_knowable_at_funding_time_strategy_reads_only_events_with_funding_time_lte_decision_bar_close',
                'ap_required_for_activation' => false,
                'activation_priority' => 2,
                'activation_phase' => 'active_funding_context',
                'research_value' => 'funding_open_interest_basis_and_crowding_context',
                'data_risk' => 'medium',
                'lookahead_risk' => 'high',
                'suitable_timeframes' => ['1d', '4h'],
                'activation_decision' => 'activated_2026_06_10_operator_funding_only_requirements_implemented',
            ],
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

    /**
     * Fonte única da verdade sobre quais feature sets estão ATIVOS. Auditorias e
     * registry derivam daqui — nunca hardcodar ids ativos/deferred fora do profile.
     *
     * @return list<string>
     */
    public function activeFeatureSetIds(): array
    {
        return [
            self::PRICE_ONLY,
            'ohlcv_regime_index_v1',
            'derivatives_funding_oi_v1',
        ];
    }

    /** @return list<string> */
    public function deferredFeatureSetIds(): array
    {
        return [
            'cross_asset_context_v1',
            'orderbook_microstructure_v1',
            'onchain_flow_v1',
            'news_sentiment_v1',
        ];
    }

    /** @return list<array<string,mixed>> */
    public function deferredBacklog(): array
    {
        return array_map(
            fn (string $id): array => $this->describe($id),
            $this->deferredFeatureSetIds(),
        );
    }

    /** @return list<array<string,mixed>> */
    public function activationRoadmap(): array
    {
        $roadmap = [
            ...array_map(fn (string $id): array => $this->describe($id), $this->activeFeatureSetIds()),
            ...$this->deferredBacklog(),
        ];
        usort(
            $roadmap,
            static fn (array $a, array $b): int => (int) ($a['activation_priority'] ?? 999) <=> (int) ($b['activation_priority'] ?? 999),
        );

        return $roadmap;
    }

    /**
     * Manifest requirement `derived_feature_code_hash`: hash do arquivo-fonte que
     * computa as features de regime. Muda o código => muda o hash => o manifest da
     * campanha denuncia. Fail-closed: arquivo ausente vira um valor não-hash.
     */
    /**
     * Manifest requirement `funding_source_hash`: sha256 por símbolo da fita congelada
     * de funding. Fail-closed: fita ausente vira um valor não-hash visível no manifest.
     *
     * @return array<string,string>
     */
    private function fundingSourceHashes(): array
    {
        $tape = FundingTape::default();
        $out = [];
        foreach (['BTCUSDT', 'ETHUSDT', 'SOLUSDT'] as $symbol) {
            $out[$symbol] = $tape->sha256($symbol);
        }

        return $out;
    }

    private function regimeFeatureCodeHash(): string
    {
        $path = (new \ReflectionClass(RegimeAdaptiveStrategy::class))->getFileName();
        $hash = is_string($path) && is_file($path) ? hash_file('sha256', $path) : false;

        return $hash !== false ? $hash : 'feature_code_file_missing';
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
