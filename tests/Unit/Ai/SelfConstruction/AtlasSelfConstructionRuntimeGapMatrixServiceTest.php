<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimeGapMatrixService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimeGapMatrixServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function matrix(): array
    {
        return (new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class)))->matrix();
    }

    public function test_next_promotion_order_includes_every_non_runtime_gap(): void
    {
        $matrix = $this->matrix();

        $this->assertArrayHasKey('next_promotion_order', $matrix);
        $this->assertCount($matrix['runtime_gap_count'], $matrix['next_promotion_order']);

        $orderedGapIds = array_column($matrix['next_promotion_order'], 'gap_id');
        $this->assertSame($matrix['blocked_gap_ids'], array_values($orderedGapIds), 'next_promotion_order should cover exactly the blocked gap ids');
    }

    public function test_next_promotion_order_rows_carry_required_fields(): void
    {
        $matrix = $this->matrix();

        $this->assertIsArray($matrix['next_promotion_order']);
        foreach ($matrix['next_promotion_order'] as $entry) {
            $this->assertArrayHasKey('gap_id', $entry);
            $this->assertArrayHasKey('priority_reason', $entry);
            $this->assertArrayHasKey('required_promotion', $entry);
            $this->assertArrayHasKey('runtime_y_candidate', $entry);
            $this->assertArrayHasKey('blockers', $entry);
            $this->assertContains($entry['priority_reason'], [
                'current_pointer_target',
                'runtime_y_candidate_ready_for_promotion',
                'blocked_colder_gap',
            ]);
        }
    }

    public function test_next_promotion_order_ranks_current_pointer_and_candidates_before_colder_gaps(): void
    {
        $matrix = $this->matrix();
        $order = $matrix['next_promotion_order'];

        $rank = static fn (array $entry): int => $entry['is_current_pointer'] ? 0 : ($entry['runtime_y_candidate'] ? 1 : 2);

        $ranks = array_map($rank, $order);
        $sortedRanks = $ranks;
        sort($sortedRanks);

        $this->assertSame($sortedRanks, $ranks, 'next_promotion_order must be sorted by pointer/candidate priority');
    }
}
