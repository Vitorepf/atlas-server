<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionPortfolioAllocator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionPortfolioAllocatorTest extends TestCase
{
    private function allocator(): AtlasExternalBrainCompressionPortfolioAllocator
    {
        return new AtlasExternalBrainCompressionPortfolioAllocator;
    }

    public function test_balanced_batch_case_already_diverse_no_rebalance_needed(): void
    {
        $r = $this->allocator()->allocate([
            'batch_size' => 3,
            'min_kind_diversity' => 2,
            'candidates' => [
                ['id' => 'd1', 'kind' => 'delete', 'roi_score' => 0.9],
                ['id' => 'p1', 'kind' => 'proof', 'roi_score' => 0.8],
                ['id' => 'm1', 'kind' => 'merge', 'roi_score' => 0.7],
            ],
        ]);

        $this->assertFalse($r['rebalanced']);
        $ids = array_column($r['batch'], 'id');
        $this->assertSame(['d1', 'p1', 'm1'], $ids);
        $this->assertGreaterThanOrEqual(2, count($r['kind_counts']));
    }

    public function test_all_one_kind_rebalanced_case(): void
    {
        $candidates = [];
        for ($i = 1; $i <= 5; $i++) {
            $candidates[] = ['id' => "delete_{$i}", 'kind' => 'delete', 'roi_score' => 1.0 - ($i * 0.01)];
        }
        $candidates[] = ['id' => 'proof_1', 'kind' => 'proof', 'roi_score' => 0.1];

        $r = $this->allocator()->allocate([
            'batch_size' => 3,
            'min_kind_diversity' => 2,
            'candidates' => $candidates,
        ]);

        $this->assertTrue($r['rebalanced']);
        $kinds = array_column($r['batch'], 'kind');
        $this->assertContains('proof', $kinds);
        $this->assertContains('delete', $kinds);
        $this->assertCount(3, $r['batch']);
    }

    public function test_never_drops_a_represented_kind_below_one_slot_when_batch_is_small(): void
    {
        $r = $this->allocator()->allocate([
            'batch_size' => 1,
            'min_kind_diversity' => 2,
            'candidates' => [
                ['id' => 'd1', 'kind' => 'delete', 'roi_score' => 0.9],
                ['id' => 'p1', 'kind' => 'proof', 'roi_score' => 0.1],
            ],
        ]);

        // batch_size=1 cannot hold 2 distinct kinds without a slot to displace — no rebalance possible.
        $this->assertCount(1, $r['batch']);
        $this->assertFalse($r['rebalanced']);
        $this->assertSame('d1', $r['batch'][0]['id']);
    }

    public function test_diversity_target_capped_at_distinct_kinds_present(): void
    {
        $r = $this->allocator()->allocate([
            'batch_size' => 5,
            'min_kind_diversity' => 10,
            'candidates' => [
                ['id' => 'd1', 'kind' => 'delete', 'roi_score' => 0.9],
                ['id' => 'd2', 'kind' => 'delete', 'roi_score' => 0.8],
            ],
        ]);

        $this->assertSame(1, $r['diversity_target']);
    }

    public function test_empty_candidates_produces_empty_batch(): void
    {
        $r = $this->allocator()->allocate([]);

        $this->assertSame([], $r['batch']);
        $this->assertSame([], $r['kind_counts']);
        $this->assertFalse($r['rebalanced']);
    }

    public function test_schema_present(): void
    {
        $r = $this->allocator()->allocate([]);

        $this->assertSame(AtlasExternalBrainCompressionPortfolioAllocator::SCHEMA, $r['schema']);
    }
}
