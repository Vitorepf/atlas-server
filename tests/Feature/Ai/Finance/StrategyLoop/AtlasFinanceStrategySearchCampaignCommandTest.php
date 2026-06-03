<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Finance\StrategyLoop;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasFinanceStrategySearchCampaignCommandTest extends TestCase
{
    private string $dryRunRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dryRunRoot = storage_path('framework/atlas/finance/dry-run-campaigns');
        if (! is_file(storage_path('atlas/finance/market-data/BTCUSDT-1d.csv'))) {
            $this->markTestSkipped('BTCUSDT-1d market data fixture is not available.');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dryRunRoot.'/phpunit-*') ?: [] as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
        }
        @unlink(storage_path('framework/atlas/finance/research-evidence-ledger.jsonl'));
        @unlink(storage_path('framework/atlas/finance/scenario-registry.json'));
        @unlink(storage_path('framework/atlas/finance/candidate-rediscovery-ledger.jsonl'));
        parent::tearDown();
    }

    public function test_no_ledger_smoke_does_not_write_the_requested_ledger(): void
    {
        $ledger = storage_path('framework/testing/phpunit-no-ledger-'.bin2hex(random_bytes(4)).'.jsonl');

        $this->artisan('atlas:finance:strategy-search', [
            '--rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--seed' => 123,
            '--ledger' => $ledger,
            '--no-ledger' => true,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($ledger);
    }

    public function test_campaigns_write_to_isolated_dry_run_ledgers(): void
    {
        $first = 'phpunit-campaign-a-'.bin2hex(random_bytes(4));
        $second = 'phpunit-campaign-b-'.bin2hex(random_bytes(4));

        $this->runDryCampaign($first, 111);
        $this->runDryCampaign($second, 222);

        $firstDir = $this->dryRunRoot.'/'.$first;
        $secondDir = $this->dryRunRoot.'/'.$second;

        $this->assertFileExists($firstDir.'/ledger.jsonl');
        $this->assertFileExists($secondDir.'/ledger.jsonl');
        $this->assertNotSame(realpath($firstDir.'/ledger.jsonl'), realpath($secondDir.'/ledger.jsonl'));

        $firstCampaign = json_decode((string) file_get_contents($firstDir.'/campaign.json'), true);
        $secondCampaign = json_decode((string) file_get_contents($secondDir.'/campaign.json'), true);

        $this->assertSame($first, $firstCampaign['campaign_id']);
        $this->assertSame($second, $secondCampaign['campaign_id']);
        $this->assertSame(1, $firstCampaign['pre_registered_budget']['max_rounds']);
        $this->assertSame(10, $firstCampaign['pre_registered_budget']['candidates_per_round']);
        $this->assertSame(10, $firstCampaign['pre_registered_budget']['max_candidates']);
        $this->assertSame('forbidden', $firstCampaign['live_trading']);
        $this->assertTrue($firstCampaign['promotion_criteria']['second_engine_required']);
        $this->assertSame(['conservative', 'aggressive', 'robustness'], $firstCampaign['search_design']['islands']);
        $this->assertSame(['ann_sharpe', 'max_dd', 'stability_score', 'robustness_score'], $firstCampaign['search_design']['pareto_objectives']);
        $this->assertSame('independent-replay', $firstCampaign['second_engine']['mode']);
        $this->assertSame(1, $firstCampaign['cross_campaign_rediscovery']['required_independent_campaigns']);
        $this->assertTrue($firstCampaign['promotion_criteria']['cross_campaign_rediscovery_required']);
        $this->assertSame('validation', $firstCampaign['holdout']['role']);
        $this->assertSame('confirmation', $firstCampaign['confirmation_holdout']['role']);
        $this->assertNotSame($firstCampaign['holdout']['holdout_id'], $firstCampaign['confirmation_holdout']['holdout_id']);
        $this->assertFileExists($firstDir.'/null-report.json');
        $this->assertFileExists(storage_path('framework/atlas/finance/research-evidence-ledger.jsonl'));
        $this->assertStringContainsString('atlas.finance.strategy_research_evidence.v1', (string) file_get_contents(storage_path('framework/atlas/finance/research-evidence-ledger.jsonl')));
        $scenarioRegistry = json_decode((string) file_get_contents(storage_path('framework/atlas/finance/scenario-registry.json')), true);
        $this->assertTrue($scenarioRegistry['do_not_start_in_parallel']);
        $this->assertArrayHasKey('BTCUSDT-1d-trend-breakout-v1', $scenarioRegistry['scenarios']);
    }

    public function test_existing_campaign_resumes_round_numbers_instead_of_restarting(): void
    {
        $campaign = 'phpunit-resume-'.bin2hex(random_bytes(4));

        $this->artisan('atlas:finance:strategy-search', [
            '--rounds' => 1,
            '--max-rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--seed' => 123,
            '--campaign-id' => $campaign,
            '--dry-run-ledger' => true,
        ])->assertExitCode(0);

        $this->artisan('atlas:finance:strategy-search', [
            '--rounds' => 2,
            '--max-rounds' => 2,
            '--candidates' => 10,
            '--sleep' => 0,
            '--seed' => 123,
            '--campaign-id' => $campaign,
            '--dry-run-ledger' => true,
        ])->assertExitCode(0);

        $rows = array_values(array_filter(array_map(
            static fn (string $line): ?array => json_decode($line, true),
            file($this->dryRunRoot.'/'.$campaign.'/ledger.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        )));

        $this->assertSame([1, 2], array_column($rows, 'round'));
    }

    private function runDryCampaign(string $campaignId, int $seed): void
    {
        $this->artisan('atlas:finance:strategy-search', [
            '--rounds' => 1,
            '--max-rounds' => 1,
            '--candidates' => 10,
            '--sleep' => 0,
            '--seed' => $seed,
            '--campaign-id' => $campaignId,
            '--dry-run-ledger' => true,
        ])->assertExitCode(0);
    }
}
