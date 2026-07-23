<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos\Cores;

use App\Services\Ai\AgenticEngineeringOs\Scoring\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\Memory\AtlasMemoryContextComposer;
use App\Services\Ai\Memory\MemoryRecallInput;
use PHPUnit\Framework\TestCase;

final class MemoryFeedbackDecayScorerWiringWiredTest extends TestCase
{
    private array $options = [
        'memory_recall_limit' => 10,
        'memory_recall_budget_chars' => 5000,
        'memory_recall_item_chars' => 1000,
    ];

    private function composer(): AtlasMemoryContextComposer
    {
        return new AtlasMemoryContextComposer(new MemoryRecallInput(), new AtlasMemoryRecallRelevanceScorer());
    }

    public function test_registry_item_with_repeated_negative_feedback_and_low_health_is_dropped(): void
    {
        $registry = [[
            'id' => 'r1', 'title' => 'BadMemory', 'body' => 'A memory with heavy negative feedback',
            'type' => 'decision', 'negative_count' => 5,
        ]];

        $items = $this->composer()->compose($registry, [], [], $this->options);

        self::assertSame([], $items, 'a memory inactivated by negative feedback must not reach the candidate pool');
    }

    public function test_registry_item_with_stale_feedback_is_archived_and_dropped(): void
    {
        $registry = [[
            'id' => 'r1', 'title' => 'StaleMemory', 'body' => 'A memory flagged stale twice',
            'type' => 'decision', 'stale_count' => 2,
        ]];

        $items = $this->composer()->compose($registry, [], [], $this->options);

        self::assertSame([], $items);
    }

    public function test_healthy_registry_item_with_no_negative_signals_is_kept(): void
    {
        $registry = [[
            'id' => 'r1', 'title' => 'GoodMemory', 'body' => 'A perfectly healthy memory',
            'type' => 'decision', 'positive_count' => 3,
        ]];

        $items = $this->composer()->compose($registry, [], [], $this->options);

        self::assertCount(1, $items);
        self::assertSame('GoodMemory', $items[0]['title']);
    }
}
