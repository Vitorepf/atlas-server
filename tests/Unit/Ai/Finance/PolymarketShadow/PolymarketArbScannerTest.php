<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\PolymarketShadow;

use App\Services\Ai\Finance\PolymarketShadow\PolymarketArbScanner;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PolymarketArbScannerTest extends TestCase
{
    public function test_short_signal_carries_event_activity_for_dead_book_guard(): void
    {
        Http::fake([
            'https://1.1.1.1/*' => Http::response([
                'Answer' => [['data' => '104.18.34.205']],
            ], 200),
            'https://gamma-api.polymarket.com/events*' => Http::response([
                [
                    'negRisk' => true,
                    'slug' => 'thin-short-event',
                    'title' => 'Thin Short Event',
                    'volume24hr' => 12.34,
                    'liquidity' => 456.78,
                    'markets' => [
                        $this->market('A', 'Will A win?', 0.40),
                        $this->market('B', 'Will B win?', 0.40),
                        $this->market('C', 'Will C win?', 0.40),
                    ],
                ],
            ], 200),
            'https://clob.polymarket.com/book*' => Http::response([
                'asks' => [['price' => '0.45', 'size' => '100']],
                'bids' => [['price' => '0.40', 'size' => '100']],
            ], 200),
        ]);

        $progressStages = [];

        $result = (new PolymarketArbScanner)->scanOnce(
            pages: 1,
            perPage: 50,
            preFilterMargin: 0.02,
            minProfitPerSet: 0.005,
            feePerSet: 0.0,
            maxClobVerifications: 12,
            onProgress: function (string $stage) use (&$progressStages): void {
                $progressStages[] = $stage;
            },
        );

        $this->assertCount(1, $result['signals']);
        $signal = $result['signals'][0];
        $this->assertSame('short_sum_over', $signal['kind']);
        $this->assertEqualsWithDelta(12.34, $signal['volume_24hr'], 1e-9);
        $this->assertEqualsWithDelta(456.78, $signal['liquidity'], 1e-9);
        $this->assertContains('page', $progressStages);
        $this->assertContains('shortlist', $progressStages);
        $this->assertContains('verify', $progressStages);
    }

    public function test_scan_skips_candidates_with_too_many_legs_before_clob_reads(): void
    {
        Http::fake([
            'https://1.1.1.1/*' => Http::response([
                'Answer' => [['data' => '104.18.34.205']],
            ], 200),
            'https://gamma-api.polymarket.com/events*' => Http::response([
                [
                    'negRisk' => true,
                    'slug' => 'too-wide-event',
                    'title' => 'Too Wide Event',
                    'volume24hr' => 123.45,
                    'liquidity' => 999.99,
                    'markets' => [
                        $this->market('A', 'Will A win?', 0.26),
                        $this->market('B', 'Will B win?', 0.26),
                        $this->market('C', 'Will C win?', 0.26),
                        $this->market('D', 'Will D win?', 0.26),
                    ],
                ],
            ], 200),
        ]);

        $result = (new PolymarketArbScanner)->scanOnce(
            pages: 1,
            perPage: 50,
            preFilterMargin: 0.10,
            minProfitPerSet: 0.002,
            feePerSet: 0.0,
            maxClobVerifications: 12,
            maxLegsPerCandidate: 3,
        );

        $this->assertSame(1, $result['scanned_events']);
        $this->assertSame(1, $result['skipped_too_many_legs']);
        $this->assertSame(0, $result['shortlisted']);
        $this->assertFalse($result['budget_exhausted']);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'https://clob.polymarket.com/book'));
    }

    public function test_scan_time_budget_fails_closed_before_public_reads(): void
    {
        Http::fake([
            'https://1.1.1.1/*' => Http::response([
                'Answer' => [['data' => '104.18.34.205']],
            ], 200),
            'https://gamma-api.polymarket.com/events*' => Http::response([], 200),
        ]);

        $result = (new PolymarketArbScanner)->scanOnce(
            pages: 1,
            perPage: 50,
            scanTimeBudgetSeconds: 0.0,
        );

        $this->assertTrue($result['budget_exhausted']);
        $this->assertSame(0, $result['scanned_events']);

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function market(string $token, string $question, float $bid): array
    {
        return [
            'closed' => false,
            'active' => true,
            'clobTokenIds' => [$token, $token.'-no'],
            'outcomes' => ['Yes', 'No'],
            'bestAsk' => 0.45,
            'bestBid' => $bid,
            'question' => $question,
        ];
    }
}
