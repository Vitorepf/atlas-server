<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCandidateSignature;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyConfirmationQueue;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyFeatureSetProfile;
use PHPUnit\Framework\TestCase;

final class StrategyConfirmationQueueTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/atlas-confirmation-queue-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_enqueues_confirmation_campaign_without_parallel_execution(): void
    {
        $queue = new StrategyConfirmationQueue($this->path);
        $item = $queue->enqueue($this->request());

        $this->assertSame('pending', $item['status']);
        $this->assertSame('sequential_only_never_parallel', $item['parallelism_policy']);
        $this->assertSame('forbidden', $item['live_trading']);
        $this->assertSame(StrategyFeatureSetProfile::PRICE_ONLY, $item['feature_set']['feature_set_id']);
        $this->assertSame(StrategyFeatureSetProfile::PRICE_ONLY, $item['confirmation_campaign']['feature_set']['feature_set_id']);
        $this->assertStringContainsString('atlas:finance:strategy-search', $item['confirmation_campaign']['command']);
        $this->assertStringContainsString('--campaign-id=', $item['confirmation_campaign']['command']);
        $this->assertStringContainsString('--feature-set=price_only_v1', $item['confirmation_campaign']['command']);
        $this->assertCount(1, $queue->pending());
    }

    public function test_deduplicates_same_source_campaign_and_signature(): void
    {
        $queue = new StrategyConfirmationQueue($this->path);
        $first = $queue->enqueue($this->request());
        $second = $queue->enqueue($this->request());

        $this->assertSame($first['request_id'], $second['request_id']);
        $this->assertTrue($second['duplicate']);
        $this->assertCount(1, $queue->pending());
    }

    public function test_claim_next_marks_single_item_claimed(): void
    {
        $queue = new StrategyConfirmationQueue($this->path);
        $queue->enqueue($this->request());

        $claimed = $queue->claimNext();

        $this->assertSame('claimed', $claimed['status']);
        $this->assertSame([], $queue->pending());
    }

    /** @return array<string,mixed> */
    private function request(): array
    {
        $params = [
            'regime_period' => 100,
            'entry_lookback' => 30,
            'exit_lookback' => 15,
            'atr_period' => 14,
            'atr_mult' => 3.0,
            'risk_pct' => 0.15,
            'min_hold_bars' => 4,
        ];

        return [
            'source_campaign_id' => 'campaign-a',
            'source_round' => 12,
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'feature_set' => (new StrategyFeatureSetProfile)->describe(StrategyFeatureSetProfile::PRICE_ONLY),
            'signature' => (new StrategyCandidateSignature)->make('BTCUSDT', '1d', 'trend-breakout-v1', $params),
            'candidate_params' => $params,
            'required_independent_campaigns' => 1,
            'candidates_per_round' => 600,
            'max_rounds' => 1000,
            'seed' => 456,
        ];
    }
}
