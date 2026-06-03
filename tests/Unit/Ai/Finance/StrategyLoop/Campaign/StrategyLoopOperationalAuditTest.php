<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignStore;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyLoopOperationalAudit;
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

        $this->assertSame('pass', $payload['status']);
        $this->assertSame('phpunit-audit-running', $payload['campaign_id']);
        $this->assertSame('BTCUSDT', $payload['symbol']);
        $this->assertSame('trend-breakout-v1', $payload['strategy_family']);
    }

    private function writeCampaign(string $id, string $status, string $holdoutId, string $confirmationHoldoutId): string
    {
        $dir = $this->campaignRoot.'/'.$id;
        @mkdir($dir, 0o755, true);
        $path = $dir.'/campaign.json';
        file_put_contents($path, json_encode([
            'schema_version' => 'atlas.finance.strategy_campaign.v1',
            'campaign_id' => $id,
            'status' => $status,
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'pre_registered_budget' => [
                'max_rounds' => 100,
                'candidates_per_round' => 600,
            ],
            'second_engine' => [
                'mode' => 'python-replay',
            ],
            'cross_campaign_rediscovery' => [
                'scope' => 'same_symbol_interval_family_and_coarse_parameter_signature',
            ],
            'data_manifest' => [
                'sha256' => str_repeat('a', 64),
            ],
            'cost_profile' => [
                'fee_bps' => 10.0,
                'slippage_bps' => 5.0,
            ],
            'holdout' => [
                'holdout_id' => $holdoutId,
            ],
            'confirmation_holdout' => [
                'holdout_id' => $confirmationHoldoutId,
            ],
            'propose_only' => true,
            'live_trading' => 'forbidden',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function writeScenarioRegistry(): void
    {
        $path = storage_path('framework/atlas/finance/scenario-registry.json');
        @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, json_encode([
            'do_not_start_in_parallel' => true,
            'sequential_roadmap' => [
                ['symbol' => 'BTCUSDT', 'interval' => '1d', 'strategy_family' => 'trend-breakout-v1'],
                ['symbol' => 'BTCUSDT', 'interval' => '1d', 'strategy_family' => 'mean-reversion-v1'],
                ['symbol' => 'BTCUSDT', 'interval' => '1d', 'strategy_family' => 'momentum-v1'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

    private function removeAuditCampaigns(): void
    {
        foreach (glob(storage_path('framework/atlas/finance/dry-run-campaigns/phpunit-audit-*')) ?: [] as $dir) {
            $this->removeDirectory($dir);
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
