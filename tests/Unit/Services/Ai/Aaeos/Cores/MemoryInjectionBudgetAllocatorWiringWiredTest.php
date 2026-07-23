<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos\Cores;

use App\Services\Ai\Aaeos\Cores\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\Aaeos\Cores\MemoryInjectionBudgetAllocator;
use App\Services\Ai\Memory\AtlasMemoryContextComposer;
use App\Services\Ai\Memory\MemoryRecallInput;
use PHPUnit\Framework\TestCase;

/**
 * Proves MemoryInjectionBudgetAllocator is wired into AtlasMemoryContextComposer::compose():
 * every admitted item's audit trail carries an independent knapsack-packing diagnostic
 * (budget_allocation) computed by the allocator over the same admitted set.
 */
final class MemoryInjectionBudgetAllocatorWiringWiredTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [
        'memory_recall_limit' => 10,
        'memory_recall_budget_chars' => 5000,
        'memory_recall_item_chars' => 1000,
    ];

    public function test_composed_items_carry_budget_allocation_diagnostics(): void
    {
        $composer = new AtlasMemoryContextComposer(
            new MemoryRecallInput,
            new AtlasMemoryRecallRelevanceScorer,
        );

        $registry = [
            [
                'id' => 'r1',
                'type' => 'decision',
                'title' => 'Reg one',
                'summary' => 'summary one',
                'body' => str_repeat('body text long enough to clear the allocator minimum excerpt floor. ', 3),
                'confidence' => 'high',
            ],
        ];

        $items = $composer->compose($registry, [], [], $this->options);

        $this->assertNotEmpty($items);
        $allocation = $items[0]['audit']['budget_allocation'];
        $this->assertNotNull($allocation);
        $this->assertArrayHasKey('allocated_chars', $allocation);
        $this->assertSame($items[0]['estimated_chars'], $allocation['requested_chars']);
    }

    public function test_allocator_is_used_directly_with_its_own_packing_semantics(): void
    {
        $allocator = new MemoryInjectionBudgetAllocator;

        $result = $allocator->allocate(
            [
                ['ref' => 'a', 'priority' => 10, 'estimated_chars' => 100],
                ['ref' => 'b', 'priority' => 5, 'estimated_chars' => 100],
            ],
            totalBudgetChars: 150,
            perItemCapChars: 100,
        );

        $this->assertSame(['a'], array_column($result['admitted'], 'ref'));
        $this->assertSame(['b'], array_column($result['dropped'], 'ref'));
    }
}
