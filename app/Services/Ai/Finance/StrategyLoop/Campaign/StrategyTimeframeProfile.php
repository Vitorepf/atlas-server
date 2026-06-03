<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * Classifies a strategy campaign timeframe before the search runs.
 *
 * The loop must never treat 5m scalping, 4h intraday swing, 1d swing, and 1mo
 * position research as interchangeable. This profile is metadata only: it does
 * not weaken any honesty gate and it never enables parallel campaigns.
 */
final class StrategyTimeframeProfile
{
    /**
     * @return array<string,mixed>
     */
    public function describe(string $interval): array
    {
        $normalized = $this->normalize($interval);
        $periodsPerYear = $this->periodsPerYear($normalized);

        $profile = match ($normalized) {
            '1m', '3m', '5m', '15m' => [
                'horizon_bucket' => 'high_frequency_intraday',
                'trade_style' => 'scalping_or_day_trade',
                'roadmap_readiness' => 'deferred_until_microstructure_controls',
                'active_in_default_roadmap' => false,
                'minimum_recommended_bars' => 50_000,
                'data_controls' => [
                    'intraday_ohlcv_hash_required',
                    'liquidity_and_spread_model_required',
                    'variable_slippage_stress_required',
                    'survivorship_universe_control_required',
                ],
                'activation_requirements' => [
                    'fresh_intraday_market_data',
                    'slippage_model_scaled_by_liquidity_and_volatility',
                    'second_engine_replay_support_for_intraday',
                    'separate_campaign_budget_pre_registration',
                ],
            ],
            '30m', '1h', '2h', '4h' => [
                'horizon_bucket' => 'intraday_swing',
                'trade_style' => 'day_trade_or_multi_day_swing',
                'roadmap_readiness' => $normalized === '4h' ? 'ready_when_data_available' : 'deferred_until_data_manifest',
                'active_in_default_roadmap' => $normalized === '4h',
                'minimum_recommended_bars' => 10_000,
                'data_controls' => [
                    'intraday_ohlcv_hash_required',
                    'liquidity_aware_cost_stress_recommended',
                    'separate_holdout_registry_required',
                ],
                'activation_requirements' => [
                    'fresh_intraday_market_data',
                    'separate_campaign_budget_pre_registration',
                    'scenario_specific_null_reporting',
                ],
            ],
            '1d' => [
                'horizon_bucket' => 'daily_swing',
                'trade_style' => 'swing_trade',
                'roadmap_readiness' => 'active_benchmark',
                'active_in_default_roadmap' => true,
                'minimum_recommended_bars' => 1_500,
                'data_controls' => [
                    'daily_ohlcv_hash_required',
                    'validation_and_confirmation_holdouts_required',
                    'regime_report_required',
                ],
                'activation_requirements' => [
                    'pre_registered_campaign_budget',
                    'scenario_specific_null_reporting',
                ],
            ],
            '1w', '1mo' => [
                'horizon_bucket' => 'position',
                'trade_style' => 'long_horizon_position',
                'roadmap_readiness' => $normalized === '1w' ? 'deferred_until_weekly_depth' : 'deferred_until_monthly_depth',
                'active_in_default_roadmap' => false,
                'minimum_recommended_bars' => $normalized === '1w' ? 300 : 120,
                'data_controls' => [
                    'long_horizon_history_depth_required',
                    'cycle_regime_report_required',
                    'low_trade_count_guard_required',
                ],
                'activation_requirements' => [
                    'fresh_long_horizon_market_data',
                    'holdout_design_with_enough_cycles',
                    'separate_campaign_budget_pre_registration',
                ],
            ],
            default => [
                'horizon_bucket' => 'unknown',
                'trade_style' => 'unprofiled',
                'roadmap_readiness' => 'blocked_until_timeframe_profiled',
                'active_in_default_roadmap' => false,
                'minimum_recommended_bars' => null,
                'data_controls' => [
                    'explicit_timeframe_profile_required',
                ],
                'activation_requirements' => [
                    'define_periods_per_year',
                    'define_data_controls',
                    'define_holdout_design',
                ],
            ],
        };

        return [
            'schema_version' => 'atlas.finance.strategy_timeframe_profile.v1',
            'interval' => $interval,
            'normalized_interval' => $normalized,
            'periods_per_year' => $periodsPerYear,
            'timeframe_transfer_policy' => 'do_not_transfer_between_timeframes_without_new_campaign',
            'knowledge_policy' => 'record_results_per_symbol_interval_family_and_regime',
        ] + $profile;
    }

    public function periodsPerYear(string $interval): float
    {
        return match ($this->normalize($interval)) {
            '1m' => 60 * 24 * 365,
            '3m' => 20 * 24 * 365,
            '5m' => 12 * 24 * 365,
            '15m' => 4 * 24 * 365,
            '30m' => 2 * 24 * 365,
            '1h' => 24 * 365,
            '2h' => 12 * 365,
            '4h' => 6 * 365,
            '6h' => 4 * 365,
            '8h' => 3 * 365,
            '12h' => 2 * 365,
            '1w' => 52,
            '1mo' => 12,
            default => 365,
        };
    }

    /**
     * Search controls that differ by timeframe. These are defaults for campaign
     * pre-registration; command-line overrides stay explicit and auditable.
     *
     * @return array<string,mixed>
     */
    public function campaignPolicy(string $interval): array
    {
        $profile = $this->describe($interval);
        $normalized = (string) $profile['normalized_interval'];

        $policy = match ((string) $profile['horizon_bucket']) {
            'high_frequency_intraday' => [
                'default_min_trades' => 120,
                'default_holdout_min_trades' => 60,
                'default_holdout_max_reuse' => 250,
                'cost_stress_multiplier' => 3.0,
                'requires_explicit_activation' => true,
                'policy_note' => 'Scalping/day-trade research requires microstructure, spread, and variable slippage controls before default activation.',
            ],
            'intraday_swing' => [
                'default_min_trades' => 40,
                'default_holdout_min_trades' => 20,
                'default_holdout_max_reuse' => 750,
                'cost_stress_multiplier' => 2.0,
                'requires_explicit_activation' => $normalized !== '4h',
                'policy_note' => 'Intraday swing needs more trades than daily campaigns and remains scenario-specific.',
            ],
            'daily_swing' => [
                'default_min_trades' => 20,
                'default_holdout_min_trades' => 10,
                'default_holdout_max_reuse' => 1000,
                'cost_stress_multiplier' => 2.0,
                'requires_explicit_activation' => false,
                'policy_note' => 'Daily swing benchmark keeps the original hard honesty thresholds.',
            ],
            'position' => [
                'default_min_trades' => 8,
                'default_holdout_min_trades' => 4,
                'default_holdout_max_reuse' => 100,
                'cost_stress_multiplier' => 2.0,
                'requires_explicit_activation' => true,
                'policy_note' => 'Long-horizon research needs cycle-depth controls before default activation.',
            ],
            default => [
                'default_min_trades' => 20,
                'default_holdout_min_trades' => 10,
                'default_holdout_max_reuse' => 100,
                'cost_stress_multiplier' => 2.0,
                'requires_explicit_activation' => true,
                'policy_note' => 'Unknown timeframe is blocked until explicitly profiled.',
            ],
        };

        return [
            'schema_version' => 'atlas.finance.strategy_timeframe_policy.v1',
            'interval' => $interval,
            'normalized_interval' => $normalized,
            'horizon_bucket' => $profile['horizon_bucket'],
            'trade_style' => $profile['trade_style'],
            'roadmap_readiness' => $profile['roadmap_readiness'],
            'minimum_recommended_bars' => $profile['minimum_recommended_bars'],
            'activation_requirements' => $profile['activation_requirements'],
            'policy_scope' => 'symbol_interval_family_campaign',
            'transfer_policy' => 'do_not_reuse_between_timeframes_without_new_campaign',
        ] + $policy;
    }

    public function normalize(string $interval): string
    {
        $raw = trim($interval);
        $lower = strtolower($raw);
        if ($raw === '1M' || in_array($lower, ['1mo', '1mon', '1month', '1monthly'], true)) {
            return '1mo';
        }

        return $lower !== '' ? $lower : '1d';
    }
}
