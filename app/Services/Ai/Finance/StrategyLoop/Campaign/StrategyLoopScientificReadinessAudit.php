<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * End-to-end readiness audit for the scientific campaign platform.
 *
 * This is a proof surface, not a trading gate. It consolidates operational and
 * adversarial audits with platform-level contracts so "ready" means governed
 * campaign science, not a lucky strategy result.
 */
final class StrategyLoopScientificReadinessAudit
{
    /** @return array<string,mixed> */
    public function audit(bool $dryRun = false, ?string $campaignId = null, bool $includeRuntime = false): array
    {
        $operational = (new StrategyLoopOperationalAudit)->audit($dryRun, $campaignId, $includeRuntime);
        $adversarial = (new StrategyLoopAdversarialAudit)->audit();
        $registry = StrategyScenarioRegistry::default($dryRun)->load();
        $resolvedCampaignId = (string) ($operational['campaign_id'] ?? $campaignId ?? '');
        $campaign = $resolvedCampaignId !== '' ? $this->readCampaign($dryRun, $resolvedCampaignId) : [];

        $checks = [
            $this->check('operational_audit_passes', (string) ($operational['status'] ?? '') === 'pass', 'operational campaign audit passes', ['score' => $operational['score'] ?? null]),
            $this->check('adversarial_audit_passes', (string) ($adversarial['status'] ?? '') === 'pass', 'adversarial honesty audit passes', ['score' => $adversarial['score'] ?? null]),
            $this->check('campaign_artifact_shape_ready', $this->campaignArtifactShapeReady($dryRun, $resolvedCampaignId, $campaign), 'campaign directory has required scientific artifacts'),
            $this->check('quarantine_contract_hard_gates_present', $this->quarantineContractReady($campaign), 'promotion criteria require campaign penalty, fresh holdout, cost stress, neighborhood, second engine, and cross-campaign rediscovery'),
            $this->check('registry_sequential_policy_ready', (bool) ($registry['do_not_start_in_parallel'] ?? false) === true, 'registry forbids parallel finance strategy loops'),
            $this->check('roadmap_covers_scenario_dimensions', $this->roadmapCoversScenarioDimensions($registry), 'roadmap covers symbol, timeframe, family, feature set, and research rationale'),
            $this->check('feature_set_activation_fail_closed', $this->featureSetActivationFailClosed($registry), 'future indices are ordered and fail closed behind AP/data/anti-lookahead controls'),
            $this->check('scenario_matrix_research_only', $this->scenarioMatrixResearchOnly($registry), 'scenario knowledge matrix is research-only and scenario-scoped'),
            $this->check('knowledge_outputs_propose_only', $this->knowledgeOutputsProposeOnly($operational, $adversarial), 'audits and evidence remain propose-only with live trading forbidden'),
        ];

        $passed = array_reduce($checks, static fn (bool $ok, array $check): bool => $ok && (bool) $check['passed'], true);

        return [
            'schema_version' => 'atlas.finance.strategy_loop_scientific_readiness_audit.v1',
            'generated_at' => gmdate('c'),
            'status' => $passed ? 'pass' : 'fail',
            'score' => [
                'passed' => count(array_filter($checks, static fn (array $check): bool => (bool) $check['passed'])),
                'total' => count($checks),
            ],
            'campaign_id' => $resolvedCampaignId !== '' ? $resolvedCampaignId : null,
            'symbol' => $operational['symbol'] ?? ($campaign['symbol'] ?? null),
            'interval' => $operational['interval'] ?? ($campaign['interval'] ?? null),
            'strategy_family' => $operational['strategy_family'] ?? ($campaign['strategy_family'] ?? null),
            'checks' => $checks,
            'operational_audit' => [
                'status' => $operational['status'] ?? null,
                'score' => $operational['score'] ?? null,
            ],
            'adversarial_audit' => [
                'status' => $adversarial['status'] ?? null,
                'score' => $adversarial['score'] ?? null,
            ],
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

    /** @param array<string,mixed> $campaign */
    private function campaignArtifactShapeReady(bool $dryRun, string $campaignId, array $campaign): bool
    {
        if ($campaignId === '' || $campaign === []) {
            return false;
        }
        $dir = $this->campaignRoot($dryRun).'/'.StrategyCampaignStore::sanitizeId($campaignId);
        $required = [
            'campaign.json',
            'ledger.jsonl',
            'holdout-ledger.jsonl',
            'data-manifest.json',
        ];
        foreach ($required as $file) {
            if (! is_file($dir.'/'.$file)) {
                return false;
            }
        }
        foreach (['workers', 'proposals', 'artifacts'] as $subdir) {
            if (! is_dir($dir.'/'.$subdir)) {
                return false;
            }
        }

        return (string) ($campaign['status'] ?? '') === 'running' || is_file($dir.'/null-report.json');
    }

    /** @param array<string,mixed> $campaign */
    private function quarantineContractReady(array $campaign): bool
    {
        $criteria = (array) ($campaign['promotion_criteria'] ?? []);
        foreach ([
            'campaign_penalty_required',
            'fresh_holdout_required',
            'cost_stress_2x_required',
            'neighborhood_robustness_required',
            'second_engine_required',
            'cross_campaign_rediscovery_required',
        ] as $gate) {
            if ((bool) ($criteria[$gate] ?? false) !== true) {
                return false;
            }
        }

        return (string) data_get($campaign, 'cross_campaign_rediscovery.scope', '') === 'same_symbol_interval_family_and_coarse_parameter_signature'
            && in_array((string) data_get($campaign, 'second_engine.mode', ''), ['python-replay', 'independent-replay', 'freqtrade'], true);
    }

    /** @param array<string,mixed> $registry */
    private function roadmapCoversScenarioDimensions(array $registry): bool
    {
        $roadmap = array_values(array_filter((array) ($registry['sequential_roadmap'] ?? []), 'is_array'));
        if ($roadmap === []) {
            return false;
        }
        $activeIntervals = [];
        foreach ($roadmap as $entry) {
            $activeIntervals[(string) ($entry['interval'] ?? '')] = true;
            if ((string) ($entry['symbol'] ?? '') === ''
                || (string) ($entry['interval'] ?? '') === ''
                || (string) ($entry['strategy_family'] ?? '') === ''
                || ! is_array($entry['feature_set'] ?? null)
                || ! is_array($entry['research_rationale'] ?? null)) {
                return false;
            }
        }

        return isset($activeIntervals['1d'], $activeIntervals['4h']);
    }

    /** @param array<string,mixed> $registry */
    private function featureSetActivationFailClosed(array $registry): bool
    {
        $roadmap = array_values(array_filter((array) ($registry['feature_set_activation_roadmap'] ?? []), 'is_array'));
        $byId = [];
        foreach ($roadmap as $entry) {
            $byId[(string) ($entry['feature_set_id'] ?? '')] = $entry;
        }

        return (int) data_get($byId, StrategyFeatureSetProfile::PRICE_ONLY.'.activation_priority', 999) === 0
            && (bool) data_get($byId, 'ohlcv_regime_index_v1.allowed_now', true) === false
            && (bool) data_get($byId, 'derivatives_funding_oi_v1.ap_required_for_activation', false) === true
            && (int) data_get($byId, 'derivatives_funding_oi_v1.activation_priority', 999) < (int) data_get($byId, 'news_sentiment_v1.activation_priority', 0)
            && (string) data_get($byId, 'news_sentiment_v1.activation_phase', '') === 'late_experimental_only';
    }

    /** @param array<string,mixed> $registry */
    private function scenarioMatrixResearchOnly(array $registry): bool
    {
        $matrix = array_values(array_filter((array) ($registry['scenario_knowledge_matrix'] ?? []), 'is_array'));
        if ($matrix === []) {
            return false;
        }
        foreach ($matrix as $entry) {
            if ((string) ($entry['schema_version'] ?? '') !== 'atlas.finance.strategy_scenario_knowledge_matrix.v1'
                || (string) ($entry['knowledge_policy'] ?? '') !== 'research_only_not_an_executable_signal'
                || (string) ($entry['transfer_policy'] ?? '') !== 'do_not_transfer_between_assets_timeframes_families_or_feature_sets_without_new_campaign') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $operational
     * @param array<string,mixed> $adversarial
     */
    private function knowledgeOutputsProposeOnly(array $operational, array $adversarial): bool
    {
        return (bool) ($operational['propose_only'] ?? false) === true
            && (string) ($operational['live_trading'] ?? '') === 'forbidden'
            && (bool) ($adversarial['propose_only'] ?? false) === true
            && (string) ($adversarial['live_trading'] ?? '') === 'forbidden';
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
