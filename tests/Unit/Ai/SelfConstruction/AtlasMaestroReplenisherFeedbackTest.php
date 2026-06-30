<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroReplenisherFeedback;
use Tests\TestCase;

/**
 * Proves the closed-loop replenisher feedback: flag-gated emptiness, FACT-only rendering (no imperatives) with
 * support counts >= the miner floor, and suppression of insufficiently-supported buckets.
 */
final class AtlasMaestroReplenisherFeedbackTest extends TestCase
{
    /** @param array<string,array<string,array<string,mixed>>> $facts */
    private function feedback(array $facts): AtlasMaestroReplenisherFeedback
    {
        $miner = new class($facts)
        {
            /** @param array<string,mixed> $facts */
            public function __construct(private array $facts) {}

            public function mine(): array
            {
                return $this->facts;
            }
        };

        return new AtlasMaestroReplenisherFeedback($miner);
    }

    /** @return array<string,array<string,array<string,mixed>>> */
    private function minedFacts(): array
    {
        return [
            'origin_kind' => [
                'orphan' => ['dimension' => 'origin_kind', 'bucket' => 'orphan', 'delivered' => 14, 'total' => 22, 'insufficient_support' => false, 'delivery_rate' => 14 / 22],
                'docgap' => ['dimension' => 'origin_kind', 'bucket' => 'docgap', 'delivered' => 2, 'total' => 3, 'insufficient_support' => true, 'delivery_rate' => null], // < MIN_SUPPORT
            ],
        ];
    }

    public function test_flag_off_returns_empty(): void
    {
        config(['atlas.maestro.closed_loop.feedback_enabled' => false]);

        $this->assertSame('', $this->feedback($this->minedFacts())->renderFactsBlock());
    }

    public function test_renders_fact_only_block_for_supported_buckets(): void
    {
        config(['atlas.maestro.closed_loop.feedback_enabled' => true]);

        $block = $this->feedback($this->minedFacts())->renderFactsBlock();

        $this->assertNotSame('', $block);
        $this->assertStringContainsString('origin_kind=orphan: 14 delivered / 22 total (rate 0.64, give_back 0.00, blocked 0.00, yield 0.64, support>=8)', $block);
        $this->assertStringNotContainsString('docgap', $block, 'insufficiently-supported bucket is suppressed');

        // FACT-only: no imperative tokens.
        foreach (['prefer', 'should', 'must', 'avoid', 'do not', 'recommend'] as $imperative) {
            $this->assertStringNotContainsStringIgnoringCase($imperative, $block, "no imperative '{$imperative}'");
        }
        // every emitted line carries a support count >= 8.
        foreach (explode("\n", $block) as $line) {
            $this->assertMatchesRegularExpression('/\/ (\d+) total/', $line);
            preg_match('/\/ (\d+) total/', $line, $m);
            $this->assertGreaterThanOrEqual(8, (int) $m[1]);
        }
    }

    public function test_no_supported_bucket_returns_empty(): void
    {
        config(['atlas.maestro.closed_loop.feedback_enabled' => true]);

        $facts = ['origin_kind' => ['docgap' => ['dimension' => 'origin_kind', 'bucket' => 'docgap', 'delivered' => 1, 'total' => 4, 'insufficient_support' => true, 'delivery_rate' => null]]];

        $this->assertSame('', $this->feedback($facts)->renderFactsBlock());
    }

    public function test_lanes_ranked_by_yield_descending_so_high_give_back_lane_appears_last(): void
    {
        config(['atlas.maestro.closed_loop.feedback_enabled' => true]);

        // lane_a: 15/20 delivered, 0 give_back → yield = 0.75
        // lane_b: 15/20 delivered, 8 give_back → yield = 0.75 - 0.40 = 0.35
        $facts = [
            'lane' => [
                'lane_a' => ['dimension' => 'lane', 'bucket' => 'lane_a', 'delivered' => 15, 'total' => 20, 'insufficient_support' => false, 'delivery_rate' => 0.75, 'give_back_count' => 0, 'blocked_count' => 0],
                'lane_b' => ['dimension' => 'lane', 'bucket' => 'lane_b', 'delivered' => 15, 'total' => 20, 'insufficient_support' => false, 'delivery_rate' => 0.75, 'give_back_count' => 8, 'blocked_count' => 0],
            ],
        ];

        $block = $this->feedback($facts)->renderFactsBlock();
        $lines = explode("\n", $block);

        $this->assertCount(2, $lines, 'both supported lanes must be emitted');
        $this->assertStringContainsString('lane_a', $lines[0], 'high-yield lane_a must be first');
        $this->assertStringContainsString('lane_b', $lines[1], 'penalized lane_b must be last');
    }

    public function test_give_back_and_blocked_rates_reduce_yield_score_in_output(): void
    {
        config(['atlas.maestro.closed_loop.feedback_enabled' => true]);

        // 10/20 delivered, 4 give_back, 2 blocked → yield = 0.50 - 0.20 - 0.10 = 0.20
        $facts = [
            'origin_kind' => [
                'mixed' => ['dimension' => 'origin_kind', 'bucket' => 'mixed', 'delivered' => 10, 'total' => 20, 'insufficient_support' => false, 'delivery_rate' => 0.5, 'give_back_count' => 4, 'blocked_count' => 2],
            ],
        ];

        $block = $this->feedback($facts)->renderFactsBlock();

        $this->assertStringContainsString('give_back 0.20', $block);
        $this->assertStringContainsString('blocked 0.10', $block);
        $this->assertStringContainsString('yield 0.20', $block);
    }
}
