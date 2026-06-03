<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignStore;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyFeatureSetProfile;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyLoopOperationalAudit;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyLoopPlanCompletionAudit;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyLoopScientificReadinessAudit;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyParetoSelector;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyResearchEvidenceLedger;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyScenarioRegistry;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyTimeframeProfile;
use Tests\TestCase;

final class StrategyLoopOperationalAuditTest extends TestCase
{
    private string $campaignRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campaignRoot = storage_path('framework/atlas/finance/dry-run-campaigns');
        $this->removeAuditCampaigns();
        @mkdir($this->campaignRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeAuditCampaigns();
        @unlink(storage_path('framework/atlas/finance/scenario-registry.json'));
        @unlink(storage_path('framework/atlas/finance/dry-run-holdouts/registry.json'));
        @unlink(storage_path('framework/atlas/finance/research-evidence-ledger.jsonl'));

        parent::tearDown();
    }

    public function test_default_audit_prefers_running_campaign_with_live_ledger_over_newer_terminal_metadata(): void
    {
        $this->writeScenarioRegistry();
        $this->writeHoldoutRegistry();

        $runningCampaign = $this->writeCampaign('phpunit-audit-running', 'running', 'h-running', 'c-running');
        $runningLedger = dirname($runningCampaign).'/ledger.jsonl';
        file_put_contents($runningLedger, json_encode([
            'campaign_id' => 'phpunit-audit-running',
            'strategy_family' => 'trend-breakout-v1',
            'certified' => false,
            'merged_to_main' => false,
            'quarantine' => null,
        ], JSON_UNESCAPED_SLASHES)."\n");

        $completedCampaign = $this->writeCampaign('phpunit-audit-completed', 'completed', 'h-completed', 'c-completed');

        touch($runningCampaign, time() + 1);
        touch($runningLedger, time() + 2);
        touch($completedCampaign, time() + 20);

        $payload = (new StrategyLoopOperationalAudit)->audit(dryRun: true);

        $this->assertSame('pass', $payload['status'], json_encode($payload));
        $this->assertSame('phpunit-audit-running', $payload['campaign_id']);
        $this->assertSame('BTCUSDT', $payload['symbol']);
        $this->assertSame('trend-breakout-v1', $payload['strategy_family']);
    }

    public function test_terminal_reports_must_be_synchronized_to_research_evidence_and_scenario_registry(): void
    {
        $this->writeScenarioRegistry();
        $this->writeHoldoutRegistry();

        $campaignPath = $this->writeCampaign('phpunit-audit-terminal', 'completed', 'h-completed', 'c-completed');
        file_put_contents(dirname($campaignPath).'/ledger.jsonl', json_encode([
            'campaign_id' => 'phpunit-audit-terminal',
            'strategy_family' => 'trend-breakout-v1',
            'certified' => false,
            'merged_to_main' => false,
            'quarantine' => null,
        ], JSON_UNESCAPED_SLASHES)."\n");
        $report = $this->writeNullReport('phpunit-audit-terminal');

        $payload = (new StrategyLoopOperationalAudit)->audit(dryRun: true, campaignId: 'phpunit-audit-terminal');
        $failedChecks = array_column(array_filter(
            $payload['checks'],
            static fn (array $check): bool => ! (bool) $check['passed'],
        ), 'name');

        $this->assertSame('fail', $payload['status']);
        $this->assertContains('research_evidence_ledger_records_terminal_reports', $failedChecks);
        $this->assertContains('scenario_registry_records_terminal_reports', $failedChecks);

        StrategyResearchEvidenceLedger::default(dryRun: true)->recordReport($report);
        StrategyScenarioRegistry::default(dryRun: true)->recordReport($report);

        $payload = (new StrategyLoopOperationalAudit)->audit(dryRun: true, campaignId: 'phpunit-audit-terminal');

        $this->assertSame('pass', $payload['status']);
        $this->assertSame($payload['score']['total'], $payload['score']['passed']);
    }

    public function test_scientific_readiness_audit_proves_platform_contracts(): void
    {
        $this->writeScenarioRegistry();
        $this->writeHoldoutRegistry();

        $campaignPath = $this->writeCampaign('phpunit-audit-ready', 'running', 'h-running', 'c-running');
        $this->writeCampaignArtifacts(dirname($campaignPath), 'phpunit-audit-ready');
        $campaign = json_decode((string) file_get_contents($campaignPath), true);
        StrategyScenarioRegistry::default(dryRun: true)->registerCampaign(is_array($campaign) ? $campaign : []);

        $payload = (new StrategyLoopScientificReadinessAudit)->audit(dryRun: true, campaignId: 'phpunit-audit-ready');
        $checkNames = array_column((array) $payload['checks'], 'name');

        $this->assertSame('pass', $payload['status']);
        $this->assertSame($payload['score']['total'], $payload['score']['passed']);
        $this->assertContains('campaign_artifact_shape_ready', $checkNames);
        $this->assertContains('scenario_matrix_research_only', $checkNames);
        $this->assertSame('scientific_campaigns_not_stronger_bruteforce', $payload['platform_policy']);
    }

    public function test_plan_completion_audit_maps_final_plan_to_evidence(): void
    {
        $this->writeHoldoutRegistry();
        $this->writeLegacyNullCampaign();

        $campaignPath = $this->writeCampaign('phpunit-plan-ready', 'running', 'h-running', 'c-running');
        $this->writeCampaignArtifacts(dirname($campaignPath), 'phpunit-plan-ready');
        $campaign = json_decode((string) file_get_contents($campaignPath), true);
        StrategyScenarioRegistry::default(dryRun: true)->registerCampaign(is_array($campaign) ? $campaign : []);

        $report = $this->writeNullReport('phpunit-plan-null');
        StrategyResearchEvidenceLedger::default(dryRun: true)->recordReport($report);
        StrategyScenarioRegistry::default(dryRun: true)->recordReport($report);

        $payload = (new StrategyLoopPlanCompletionAudit)->audit(dryRun: true, campaignId: 'phpunit-plan-ready');
        $checkNames = array_column((array) $payload['checks'], 'name');

        $this->assertSame('pass', $payload['status'], json_encode($payload));
        $this->assertSame($payload['score']['total'], $payload['score']['passed']);
        $this->assertContains('legacy_execution_closed_as_null_campaign', $checkNames);
        $this->assertContains('crypto_roadmap_is_sequential_and_scenario_specific', $checkNames);
        $this->assertContains('focused_campaign_continuation_uses_fresh_holdout_generations', $checkNames);
        $this->assertContains('timeframe_specific_cost_stress_is_applied', $checkNames);
        $this->assertContains('propose_only_no_money_no_broker_no_execution', $checkNames);
        $this->assertSame('plan_items_are_proven_by_current_artifacts_not_by_claim', $payload['completion_policy']);
    }

    private function writeCampaign(string $id, string $status, string $holdoutId, string $confirmationHoldoutId): string
    {
        $dir = $this->campaignRoot.'/'.$id;
        @mkdir($dir, 0o755, true);
        $path = $dir.'/campaign.json';
        $timeframeProfile = (new StrategyTimeframeProfile)->describe('1d');
        $timeframePolicy = (new StrategyTimeframeProfile)->campaignPolicy('1d');
        $featureSet = (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY);
        file_put_contents($path, json_encode([
            'schema_version' => 'atlas.finance.strategy_campaign.v1',
            'campaign_id' => $id,
            'status' => $status,
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'seed' => 12345,
            'timeframe_profile' => $timeframeProfile,
            'timeframe_policy' => [
                ...$timeframePolicy,
                'effective_min_trades' => $timeframePolicy['default_min_trades'],
                'effective_holdout_min_trades' => $timeframePolicy['default_holdout_min_trades'],
                'effective_holdout_max_reuse' => $timeframePolicy['default_holdout_max_reuse'],
            ],
            'feature_set' => $featureSet,
            'strategy_family' => 'trend-breakout-v1',
            'search_design' => [
                'islands' => ['conservative', 'aggressive', 'robustness'],
                'pareto_objectives' => StrategyParetoSelector::defaultObjectives(),
                'note' => 'Internal multi-objective search only; final judge is unchanged or stricter.',
            ],
            'pre_registered_budget' => [
                'max_rounds' => 100,
                'candidates_per_round' => 600,
                'holdout_generation' => 0,
                'max_holdout_generation' => 4,
            ],
            'second_engine' => [
                'mode' => 'python-replay',
            ],
            'cross_campaign_rediscovery' => [
                'scope' => 'same_symbol_interval_family_and_coarse_parameter_signature',
            ],
            'promotion_criteria' => [
                'round_dsr_min' => 0.95,
                'pbo_max' => 0.2,
                'holdout_min_sharpe' => 0.5,
                'scoring_min_trades' => 20,
                'holdout_min_trades' => 10,
                'cost_stress_multiplier' => 2.0,
                'campaign_penalty_required' => true,
                'fresh_holdout_required' => true,
                'cost_stress_required' => true,
                'cost_stress_2x_required' => true,
                'neighborhood_robustness_required' => true,
                'second_engine_required' => true,
                'cross_campaign_rediscovery_required' => true,
            ],
            'data_manifest' => [
                'sha256' => str_repeat('a', 64),
                'holdout_generation' => 0,
                'max_holdout_generation' => 4,
            ],
            'cost_profile' => [
                'fee_bps' => 10.0,
                'slippage_bps' => 5.0,
            ],
            'holdout' => [
                'holdout_id' => $holdoutId,
                'role' => 'validation',
                'generation' => 0,
                'max_generation' => 4,
                'reuse_count' => $status === 'running' ? 1 : 100,
                'max_reuse' => 100,
                'status' => $status === 'running' ? StrategyCampaignStore::HOLDOUT_ACTIVE : StrategyCampaignStore::HOLDOUT_EXHAUSTED,
            ],
            'confirmation_holdout' => [
                'holdout_id' => $confirmationHoldoutId,
                'role' => 'confirmation',
                'reuse_count' => 0,
                'max_reuse' => 1,
                'status' => StrategyCampaignStore::HOLDOUT_RESERVED,
            ],
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function writeCampaignArtifacts(string $dir, string $campaignId): void
    {
        foreach (['workers', 'proposals', 'artifacts'] as $subdir) {
            @mkdir($dir.'/'.$subdir, 0o755, true);
        }
        file_put_contents($dir.'/ledger.jsonl', json_encode([
            'campaign_id' => $campaignId,
            'strategy_family' => 'trend-breakout-v1',
            'certified' => false,
            'merged_to_main' => false,
            'quarantine' => null,
        ], JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents($dir.'/holdout-ledger.jsonl', json_encode([
            'campaign_id' => $campaignId,
            'holdout_id' => 'h-running',
            'status' => StrategyCampaignStore::HOLDOUT_ACTIVE,
        ], JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents($dir.'/data-manifest.json', json_encode([
            'sha256' => str_repeat('a', 64),
            'feature_inputs' => [
                ['feature_set_id' => StrategyFeatureSetProfile::PRICE_ONLY],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function writeScenarioRegistry(): void
    {
        $path = storage_path('framework/atlas/finance/scenario-registry.json');
        @mkdir(dirname($path), 0o755, true);
        $profiler = new StrategyTimeframeProfile;
        $featureProfiler = new StrategyFeatureSetProfile;
        $priceOnly = $featureProfiler->describe(StrategyFeatureSetProfile::PRICE_ONLY);
        file_put_contents($path, json_encode([
            'do_not_start_in_parallel' => true,
            'feature_sets' => [
                StrategyFeatureSetProfile::PRICE_ONLY => $priceOnly,
            ],
            'feature_set_activation_roadmap' => $featureProfiler->activationRoadmap(),
            'deferred_feature_set_backlog' => $featureProfiler->deferredBacklog(),
            'timeframe_profiles' => [
                '5m' => $profiler->describe('5m'),
                '15m' => $profiler->describe('15m'),
                '1d' => $profiler->describe('1d'),
                '1mo' => $profiler->describe('1mo'),
            ],
            'deferred_timeframe_backlog' => [
                [
                    'symbol' => 'BTCUSDT',
                    'interval' => '5m',
                    'strategy_family' => 'trend-breakout-v1',
                    'reason' => 'registered_as_deferred_research_hypothesis_not_active_roadmap',
                ],
                [
                    'symbol' => 'BTCUSDT',
                    'interval' => '15m',
                    'strategy_family' => 'trend-breakout-v1',
                    'reason' => 'registered_as_deferred_research_hypothesis_not_active_roadmap',
                ],
                [
                    'symbol' => 'BTCUSDT',
                    'interval' => '1mo',
                    'strategy_family' => 'trend-breakout-v1',
                    'reason' => 'registered_as_deferred_research_hypothesis_not_active_roadmap',
                ],
            ],
            'sequential_roadmap' => [
                $this->roadmapEntry('trend-breakout-v1', $profiler, $priceOnly),
                $this->roadmapEntry('mean-reversion-v1', $profiler, $priceOnly),
                $this->roadmapEntry('momentum-v1', $profiler, $priceOnly),
                $this->roadmapEntry('trend-breakout-v1', $profiler, $priceOnly, 'ETHUSDT', '4h'),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $priceOnly */
    private function roadmapEntry(string $family, StrategyTimeframeProfile $profiler, array $priceOnly, string $symbol = 'BTCUSDT', string $interval = '1d'): array
    {
        $profile = $profiler->describe($interval);

        return [
            'symbol' => $symbol,
            'interval' => $interval,
            'timeframe_profile' => $profile,
            'strategy_family' => $family,
            'feature_set' => $priceOnly,
            'research_rationale' => [
                'schema_version' => 'atlas.finance.strategy_research_rationale.v1',
                'hypothesis' => 'scenario_specific_edge_after_costs',
                'scenario_scope' => 'exact_symbol_interval_family_feature_set',
                'symbol' => $symbol,
                'interval' => $interval,
                'strategy_family' => $family,
                'timeframe_bucket' => $profile['horizon_bucket'],
                'trade_style' => $profile['trade_style'],
                'feature_set_id' => StrategyFeatureSetProfile::PRICE_ONLY,
                'selection_reason' => 'daily_price_only_campaigns_are_the_lowest_noise_baseline_for_honest_crypto_discovery',
                'transfer_policy' => 'do_not_transfer_results_between_assets_timeframes_families_or_feature_sets_without_new_campaign',
                'certification_policy' => 'same_honesty_gate_or_stricter_no_shortcut_for_easier_timeframe',
            ],
        ];
    }

    private function writeHoldoutRegistry(): void
    {
        $path = storage_path('framework/atlas/finance/dry-run-holdouts/registry.json');
        @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, json_encode([
            'holdouts' => [
                'h-running' => ['status' => StrategyCampaignStore::HOLDOUT_ACTIVE],
                'c-running' => ['status' => StrategyCampaignStore::HOLDOUT_RESERVED],
                'h-completed' => ['status' => StrategyCampaignStore::HOLDOUT_EXHAUSTED],
                'c-completed' => ['status' => StrategyCampaignStore::HOLDOUT_RESERVED],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> */
    private function writeNullReport(string $campaignId): array
    {
        $timeframeProfile = (new StrategyTimeframeProfile)->describe('1d');
        $report = [
            'schema_version' => 'atlas.finance.strategy_null_report.v1',
            'campaign_id' => $campaignId,
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'timeframe_profile' => $timeframeProfile,
            'feature_set' => (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY),
            'strategy_family' => 'trend-breakout-v1',
            'verdict' => 'NULL_STRONG',
            'summary' => [
                'rounds' => 100,
                'total_candidates' => 60000,
                'best_dsr' => 0.81,
                'best_campaign_dsr' => 0.42,
                'best_holdout_sharpe' => 1.1,
                'data_sha' => str_repeat('a', 64),
                'cost_profile_hash' => str_repeat('b', 64),
            ],
            'data_manifest' => [
                'sha256' => str_repeat('a', 64),
            ],
            'cost_profile' => [
                'fee_bps' => 10.0,
                'slippage_bps' => 5.0,
                'cost_profile_hash' => str_repeat('b', 64),
            ],
            'holdout' => [
                'holdout_id' => 'h-completed',
                'status' => StrategyCampaignStore::HOLDOUT_EXHAUSTED,
                'reuse_count' => 100,
            ],
            'confirmation_holdout' => [
                'holdout_id' => 'c-completed',
                'used' => false,
            ],
            'failure_distribution' => [
                'deflated_sharpe_too_low' => 100,
            ],
            'scenario_profile' => [
                'scenario_key' => 'BTCUSDT-1d-trend-breakout-v1',
                'knowledge_note' => 'Research evidence only; not an executable signal.',
            ],
            'regime_summary' => [
                'scenario_note' => 'Regime evidence does not weaken the gate.',
            ],
            'negative_conclusion' => 'No robust edge survived this campaign.',
            'next_decision' => 'Move to the next pre-registered scenario.',
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];

        $dir = $this->campaignRoot.'/'.$campaignId;
        @mkdir($dir, 0o755, true);
        file_put_contents($dir.'/null-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report;
    }

    private function writeLegacyNullCampaign(): void
    {
        $dir = $this->campaignRoot.'/BTCUSDT-1d-trend-breakout-v1-legacy';
        @mkdir($dir, 0o755, true);
        $campaign = [
            'schema_version' => 'atlas.finance.strategy_campaign_legacy.v1',
            'campaign_id' => 'BTCUSDT-1d-trend-breakout-v1-legacy',
            'status' => 'legacy_null_snapshot',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'holdout' => [
                'status' => StrategyCampaignStore::HOLDOUT_EXHAUSTED,
                'reuse_count' => 7991,
                'max_reuse' => 1000,
            ],
            'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];
        $report = [
            'schema_version' => 'atlas.finance.strategy_legacy_null_report.v1',
            'campaign_id' => 'BTCUSDT-1d-trend-breakout-v1-legacy',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'verdict' => 'NULL_HOLDOUT_EXHAUSTED',
            'summary' => [
                'rounds' => 7991,
                'total_candidates' => 4794600,
                'holdout_status' => StrategyCampaignStore::HOLDOUT_EXHAUSTED,
            ],
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ];

        file_put_contents($dir.'/campaign.json', json_encode($campaign, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($dir.'/legacy-null-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function removeAuditCampaigns(): void
    {
        foreach (['phpunit-audit-*', 'phpunit-plan-*', 'BTCUSDT-1d-trend-breakout-v1-legacy'] as $pattern) {
            foreach (glob(storage_path('framework/atlas/finance/dry-run-campaigns/'.$pattern)) ?: [] as $dir) {
                $this->removeDirectory($dir);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
