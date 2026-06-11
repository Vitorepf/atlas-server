<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

use App\Console\Commands\AtlasFinanceStrategySearchCommand;

/**
 * Requirement-by-requirement audit for the final scientific campaign plan.
 *
 * This is a completion proof surface for the operator's plan, not a trading
 * gate. It deliberately checks the plan shape, not whether a strategy won.
 */
final class StrategyLoopPlanCompletionAudit
{
    /** @return array<string,mixed> */
    public function audit(bool $dryRun = false, ?string $campaignId = null, bool $includeRuntime = false): array
    {
        $scientific = (new StrategyLoopScientificReadinessAudit)->audit($dryRun, $campaignId, $includeRuntime);
        $operational = (new StrategyLoopOperationalAudit)->audit($dryRun, $campaignId, $includeRuntime);
        $adversarial = (new StrategyLoopAdversarialAudit)->audit();
        $registry = StrategyScenarioRegistry::default($dryRun)->load();
        $resolvedCampaignId = (string) ($scientific['campaign_id'] ?? $operational['campaign_id'] ?? $campaignId ?? '');
        $campaign = $resolvedCampaignId !== '' ? $this->readCampaign($dryRun, $resolvedCampaignId) : [];

        $checks = [
            $this->check(
                'legacy_execution_closed_as_null_campaign',
                $this->legacyExecutionClosed($dryRun),
                'legacy BTCUSDT-1d trend breakout loop is captured as NULL_HOLDOUT_EXHAUSTED with exhausted holdout',
            ),
            $this->check(
                'campaign_system_preregisters_budget_and_isolated_artifacts',
                $this->campaignSystemReady($dryRun, $resolvedCampaignId, $campaign),
                'campaign owns campaign.json, ledger, holdout ledger, data manifest, dirs, budget, seed, costs, data hash, and holdouts',
            ),
            $this->check(
                'search_command_supports_scientific_campaign_controls',
                $this->searchCommandControlsReady(),
                'search command exposes campaign id, ledger, seed, max rounds, holdout generation, no-ledger, dry-run-ledger, second engine, feature set, and timeframe controls',
            ),
            $this->check(
                'holdout_governance_consumable_and_fail_closed',
                $this->holdoutGovernanceReady($campaign, $adversarial),
                'holdouts have validation/confirmation roles, reuse limits/status, and exhausted holdout blocks certification',
            ),
            $this->check(
                'champion_quarantine_prevents_direct_certification',
                $this->quarantineReady($campaign, $adversarial),
                'round winners promote only; hard gates require campaign N, fresh holdout, replay, rediscovery, cost stress, neighborhood, and second engine',
            ),
            $this->check(
                'evolution_uses_islands_and_pareto_without_weakening_judge',
                $this->evolutionDesignReady($campaign),
                'internal search uses conservative/aggressive/robustness islands and four Pareto objectives while final judge remains hard',
            ),
            $this->check(
                'crypto_roadmap_is_sequential_and_scenario_specific',
                $this->cryptoRoadmapReady($registry),
                'roadmap covers BTC/ETH daily and 4h (SOL removed by operator) across families, one scenario at a time',
            ),
            $this->check(
                'focused_campaign_continuation_uses_fresh_holdout_generations',
                $this->focusedContinuationReady($operational, $adversarial),
                'active campaigns are resumed, zero-candidate nulls cannot close a scenario, and exhausted holdouts retry the same scenario while fresh generations remain',
            ),
            $this->check(
                'timeframe_and_feature_set_policy_are_fail_closed',
                $this->timeframeAndFeaturePolicyReady($registry),
                '5m/15m/1mo and future indices are registered as deferred; news is late experimental only',
            ),
            $this->check(
                'timeframe_specific_cost_stress_is_applied',
                $this->checkPassed($adversarial, 'timeframe_cost_stress_multiplier_is_applied'),
                'cost-stress hardening is applied from timeframe policy rather than fixed globally',
            ),
            $this->check(
                'second_engine_gate_available_and_non_circular',
                $this->secondEngineReady($campaign, $adversarial),
                'independent Python replay supports implemented families and freqtrade adapter fails closed for pinned external reports',
            ),
            $this->check(
                'knowledge_product_records_certified_or_null_verdicts',
                $this->knowledgeProductReady($dryRun, $registry, $operational),
                'campaigns produce CERTIFIED/NULL/INCONCLUSIVE knowledge, null reports, research evidence, and scenario matrix entries',
            ),
            $this->check(
                'propose_only_no_money_no_broker_no_execution',
                $this->proposeOnlyReady($operational, $adversarial, $scientific),
                'all audits preserve propose-only, live trading forbidden, and no execution surface',
            ),
            $this->check(
                'runtime_is_single_loop_when_requested',
                ! $includeRuntime || $this->checkPassed($operational, 'runtime_single_strategy_process'),
                $includeRuntime ? 'runtime audit confirms exactly one active strategy process' : 'runtime check not requested for this audit run',
            ),
        ];

        $passed = array_reduce($checks, static fn (bool $ok, array $check): bool => $ok && (bool) $check['passed'], true);

        return [
            'schema_version' => 'atlas.finance.strategy_loop_plan_completion_audit.v1',
            'generated_at' => gmdate('c'),
            'status' => $passed ? 'pass' : 'fail',
            'score' => [
                'passed' => count(array_filter($checks, static fn (array $check): bool => (bool) $check['passed'])),
                'total' => count($checks),
            ],
            'campaign_id' => $resolvedCampaignId !== '' ? $resolvedCampaignId : null,
            'symbol' => $scientific['symbol'] ?? ($operational['symbol'] ?? null),
            'interval' => $scientific['interval'] ?? ($operational['interval'] ?? null),
            'strategy_family' => $scientific['strategy_family'] ?? ($operational['strategy_family'] ?? null),
            'checks' => $checks,
            'readiness_status' => $scientific['status'] ?? null,
            'operational_status' => $operational['status'] ?? null,
            'adversarial_status' => $adversarial['status'] ?? null,
            'completion_policy' => 'plan_items_are_proven_by_current_artifacts_not_by_claim',
            'platform_policy' => 'scientific_campaigns_not_stronger_bruteforce',
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];
    }

    /** @return array<string,mixed> */
    private function readCampaign(bool $dryRun, string $campaignId): array
    {
        $path = $this->campaignRoot($dryRun).'/'.StrategyCampaignStore::sanitizeId($campaignId).'/campaign.json';
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function legacyExecutionClosed(bool $dryRun): bool
    {
        $dir = $this->campaignRoot($dryRun).'/BTCUSDT-1d-trend-breakout-v1-legacy';
        $campaign = $this->readJson($dir.'/campaign.json');
        $report = $this->readJson($dir.'/legacy-null-report.json');

        return (string) ($campaign['verdict'] ?? '') === 'NULL_HOLDOUT_EXHAUSTED'
            && (string) data_get($campaign, 'holdout.status', '') === StrategyCampaignStore::HOLDOUT_EXHAUSTED
            && (string) ($report['verdict'] ?? '') === 'NULL_HOLDOUT_EXHAUSTED'
            && (int) data_get($report, 'summary.total_candidates', 0) > 0
            && (bool) ($campaign['propose_only'] ?? false) === true
            && (string) ($campaign['live_trading'] ?? '') === 'forbidden';
    }

    /** @param array<string,mixed> $campaign */
    private function campaignSystemReady(bool $dryRun, string $campaignId, array $campaign): bool
    {
        if ($campaignId === '' || $campaign === []) {
            return false;
        }
        $dir = $this->campaignRoot($dryRun).'/'.StrategyCampaignStore::sanitizeId($campaignId);
        foreach (['campaign.json', 'ledger.jsonl', 'holdout-ledger.jsonl', 'data-manifest.json'] as $file) {
            if (! is_file($dir.'/'.$file)) {
                return false;
            }
        }
        foreach (['workers', 'proposals', 'artifacts'] as $subdir) {
            if (! is_dir($dir.'/'.$subdir)) {
                return false;
            }
        }

        return (string) ($campaign['symbol'] ?? '') !== ''
            && (string) ($campaign['interval'] ?? '') !== ''
            && (string) ($campaign['strategy_family'] ?? '') !== ''
            && (int) data_get($campaign, 'pre_registered_budget.max_rounds', 0) > 0
            && (int) data_get($campaign, 'pre_registered_budget.candidates_per_round', 0) > 0
            && (is_numeric($campaign['seed_base'] ?? null) || is_numeric($campaign['seed'] ?? null))
            && (string) data_get($campaign, 'data_manifest.sha256', '') !== ''
            && is_numeric(data_get($campaign, 'cost_profile.fee_bps'))
            && is_numeric(data_get($campaign, 'cost_profile.slippage_bps'))
            && (string) data_get($campaign, 'holdout.holdout_id', '') !== ''
            && (string) data_get($campaign, 'confirmation_holdout.holdout_id', '') !== '';
    }

    private function searchCommandControlsReady(): bool
    {
        $path = (new \ReflectionClass(AtlasFinanceStrategySearchCommand::class))->getFileName();
        $source = is_string($path) && is_file($path) ? (string) file_get_contents($path) : '';
        foreach ([
            '{--campaign-id=',
            '{--ledger=',
            '{--seed=',
            '{--max-rounds=',
            '{--holdout-generation=',
            '{--no-ledger',
            '{--dry-run-ledger',
            '{--second-engine=',
            '{--feature-set=',
            '{--allow-deferred-timeframe',
        ] as $needle) {
            if (! str_contains($source, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $adversarial
     */
    private function holdoutGovernanceReady(array $campaign, array $adversarial): bool
    {
        return in_array((string) data_get($campaign, 'holdout.status', ''), [StrategyCampaignStore::HOLDOUT_FRESH, StrategyCampaignStore::HOLDOUT_ACTIVE, StrategyCampaignStore::HOLDOUT_EXHAUSTED], true)
            && (int) data_get($campaign, 'holdout.max_reuse', 0) > 0
            && (string) data_get($campaign, 'confirmation_holdout.status', '') === StrategyCampaignStore::HOLDOUT_RESERVED
            && (int) data_get($campaign, 'confirmation_holdout.max_reuse', 0) >= 1
            && $this->checkPassed($adversarial, 'exhausted_holdout_blocks_certification')
            && $this->checkPassed($adversarial, 'holdout_reuse_not_double_counted');
    }

    /**
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $adversarial
     */
    private function quarantineReady(array $campaign, array $adversarial): bool
    {
        $criteria = (array) ($campaign['promotion_criteria'] ?? []);
        foreach ([
            'campaign_penalty_required',
            'scenario_penalty_required',
            'fresh_holdout_required',
            'cost_stress_required',
            'cost_stress_2x_required',
            'neighborhood_robustness_required',
            'second_engine_required',
            'cross_campaign_rediscovery_required',
        ] as $gate) {
            if ((bool) ($criteria[$gate] ?? false) !== true) {
                return false;
            }
        }

        return (float) ($criteria['round_dsr_min'] ?? 0.0) >= 0.95
            && (float) ($criteria['pbo_max'] ?? 1.0) <= 0.2
            && (float) ($criteria['cost_stress_multiplier'] ?? 0.0) >= 2.0
            && is_numeric(data_get($campaign, 'pre_registered_budget.scenario_prior_trials'))
            && (string) data_get($campaign, 'pre_registered_budget.scenario_trial_accounting', '') !== ''
            && (string) data_get($campaign, 'cross_campaign_rediscovery.scope', '') === 'same_symbol_interval_family_and_coarse_parameter_signature'
            && $this->checkPassed($adversarial, 'missing_second_engine_blocks_certification')
            && $this->checkPassed($adversarial, 'scenario_level_trial_penalty_blocks_certification')
            && $this->checkPassed($adversarial, 'divergent_second_engine_fails')
            && $this->checkPassed($adversarial, 'candidate_signature_family_scoped');
    }

    /** @param array<string,mixed> $campaign */
    private function evolutionDesignReady(array $campaign): bool
    {
        $islands = array_values((array) data_get($campaign, 'search_design.islands', []));
        $objectives = array_values((array) data_get($campaign, 'search_design.pareto_objectives', []));

        return $islands === ['conservative', 'aggressive', 'robustness']
            && $objectives === StrategyParetoSelector::defaultObjectives()
            && str_contains((string) data_get($campaign, 'search_design.note', ''), 'final judge');
    }

    /** @param array<string,mixed> $registry */
    private function cryptoRoadmapReady(array $registry): bool
    {
        if ((bool) ($registry['do_not_start_in_parallel'] ?? false) !== true) {
            return false;
        }
        // SOL removido por ordem do operador (2026-06-11): foco BTC e no máximo ETH;
        // a fila sequencial é o gargalo e cenários SOL atrasavam BTC-4h/ETH-4h.
        $requiredMarkets = [
            'BTCUSDT-1d',
            'ETHUSDT-1d',
            'BTCUSDT-4h',
            'ETHUSDT-4h',
        ];
        $requiredFamilies = ['trend-breakout-v1', 'mean-reversion-v1', 'momentum-v1'];
        $seen = [];
        foreach ((array) ($registry['sequential_roadmap'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $market = strtoupper((string) ($entry['symbol'] ?? '')).'-'.(string) ($entry['interval'] ?? '');
            $family = (string) ($entry['strategy_family'] ?? '');
            if ($market !== '' && $family !== '') {
                $seen[$market][$family] = true;
            }
            if (! is_array($entry['research_rationale'] ?? null) || ! is_array($entry['feature_set'] ?? null)) {
                return false;
            }
        }

        foreach ($requiredMarkets as $market) {
            foreach ($requiredFamilies as $family) {
                if (! isset($seen[$market][$family])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string,mixed> $registry */
    private function timeframeAndFeaturePolicyReady(array $registry): bool
    {
        $deferredIntervals = [];
        foreach ((array) ($registry['deferred_timeframe_backlog'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $deferredIntervals[(string) ($entry['interval'] ?? '')] = true;
            if ((string) ($entry['reason'] ?? '') !== 'registered_as_deferred_research_hypothesis_not_active_roadmap') {
                return false;
            }
        }
        $featureRoadmap = [];
        foreach ((array) ($registry['feature_set_activation_roadmap'] ?? []) as $entry) {
            if (is_array($entry)) {
                $featureRoadmap[(string) ($entry['feature_set_id'] ?? '')] = $entry;
            }
        }

        // FONTE ÚNICA: ativo/deferred derivado do profile (fim do whack-a-mole por ativação).
        $profiler = new StrategyFeatureSetProfile;
        foreach ($profiler->activeFeatureSetIds() as $activeId) {
            if ((bool) data_get($featureRoadmap, $activeId.'.allowed_now', false) !== true) {
                return false;
            }
        }
        foreach ($profiler->deferredFeatureSetIds() as $deferredId) {
            if ((bool) data_get($featureRoadmap, $deferredId.'.allowed_now', true) !== false) {
                return false;
            }
        }

        return isset($deferredIntervals['5m'], $deferredIntervals['15m'], $deferredIntervals['1mo'])
            && (int) data_get($featureRoadmap, StrategyFeatureSetProfile::PRICE_ONLY.'.activation_priority', 999) === 0
            && (int) data_get($featureRoadmap, 'ohlcv_regime_index_v1.activation_priority', 999) < (int) data_get($featureRoadmap, 'derivatives_funding_oi_v1.activation_priority', 999)
            && (int) data_get($featureRoadmap, 'derivatives_funding_oi_v1.activation_priority', 999) < (int) data_get($featureRoadmap, 'cross_asset_context_v1.activation_priority', 999)
            && (int) data_get($featureRoadmap, 'news_sentiment_v1.activation_priority', 0) > (int) data_get($featureRoadmap, 'onchain_flow_v1.activation_priority', 999)
            && (string) data_get($featureRoadmap, 'news_sentiment_v1.activation_phase', '') === 'late_experimental_only';
    }

    /**
     * @param array<string,mixed> $operational
     * @param array<string,mixed> $adversarial
     */
    private function focusedContinuationReady(array $operational, array $adversarial): bool
    {
        return $this->checkPassed($operational, 'campaign_holdout_generation_manifested')
            && $this->checkPassed($adversarial, 'zero_candidate_null_cannot_close_scenario')
            && $this->checkPassed($adversarial, 'holdout_exhaustion_retries_fresh_generation')
            && $this->checkPassed($adversarial, 'search_reuse_budget_does_not_close_family')
            && $this->checkPassed($adversarial, 'active_scenario_resume_preempts_fresh_generation_retry');
    }

    /**
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $adversarial
     */
    private function secondEngineReady(array $campaign, array $adversarial): bool
    {
        return class_exists(FreqtradeSecondEngineAdapter::class)
            && class_exists(ExternalPythonTrendBreakoutReplay::class)
            && in_array((string) data_get($campaign, 'second_engine.mode', ''), ['python-replay', 'independent-replay', 'freqtrade'], true)
            && $this->checkPassed($adversarial, 'python_second_engine_supports_all_implemented_families')
            && $this->checkPassed($adversarial, 'freqtrade_scenario_mismatch_fails_closed')
            && $this->checkPassed($adversarial, 'freqtrade_live_path_fails_closed');
    }

    /**
     * @param array<string,mixed> $registry
     * @param array<string,mixed> $operational
     */
    private function knowledgeProductReady(bool $dryRun, array $registry, array $operational): bool
    {
        if (! $this->checkPassed($operational, 'scenario_knowledge_matrix_present')
            || ! $this->checkPassed($operational, 'research_evidence_propose_only_surface')) {
            return false;
        }
        $matrix = array_values(array_filter((array) ($registry['scenario_knowledge_matrix'] ?? []), 'is_array'));
        if ($matrix === []) {
            return false;
        }
        $reports = [];
        foreach (glob($this->campaignRoot($dryRun).'/*/null-report.json') ?: [] as $path) {
            $reports[] = $this->readJson($path);
        }
        foreach (glob($this->campaignRoot($dryRun).'/*/legacy-null-report.json') ?: [] as $path) {
            $reports[] = $this->readJson($path);
        }
        foreach ($reports as $report) {
            $verdict = (string) ($report['verdict'] ?? '');
            if (in_array($verdict, ['CERTIFIED', 'NULL_WEAK', 'NULL_STRONG', 'NULL_HOLDOUT_EXHAUSTED', 'NULL_FAMILY_EXHAUSTED', 'INCONCLUSIVE'], true)
                && (bool) ($report['propose_only'] ?? true) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $operational
     * @param array<string,mixed> $adversarial
     * @param array<string,mixed> $scientific
     */
    private function proposeOnlyReady(array $operational, array $adversarial, array $scientific): bool
    {
        return (bool) ($operational['propose_only'] ?? false) === true
            && (string) ($operational['live_trading'] ?? '') === 'forbidden'
            && (bool) ($adversarial['propose_only'] ?? false) === true
            && (string) ($adversarial['live_trading'] ?? '') === 'forbidden'
            && (bool) ($scientific['propose_only'] ?? false) === true
            && (string) ($scientific['live_trading'] ?? '') === 'forbidden'
            && $this->checkPassed($operational, 'code_no_execution_surface')
            && $this->checkPassed($adversarial, 'no_execution_surface_scanner_catches_broker_path');
    }

    /** @param array<string,mixed> $payload */
    private function checkPassed(array $payload, string $name): bool
    {
        foreach ((array) ($payload['checks'] ?? []) as $check) {
            if (is_array($check) && (string) ($check['name'] ?? '') === $name) {
                return (bool) ($check['passed'] ?? false);
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function campaignRoot(bool $dryRun): string
    {
        return $dryRun
            ? storage_path('framework/atlas/finance/dry-run-campaigns')
            : storage_path('atlas/finance/campaigns');
    }

    /** @param array<string,mixed> $extra */
    private function check(string $name, bool $passed, string $detail, array $extra = []): array
    {
        return [
            'name' => $name,
            'passed' => $passed,
            'detail' => $detail,
        ] + $extra;
    }
}
