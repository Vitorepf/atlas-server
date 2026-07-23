<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\Aaeos\Cores\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\Memory\AtlasMemoryContextComposer;
use App\Services\Ai\Memory\MemoryRecallInput;
use PHPUnit\Framework\TestCase;

final class AtlasMemoryContextComposerHealthTest extends TestCase
{
    public function test_effective_priority_orders_and_truncates_with_health_as_tie_breaker(): void
    {
        $composer = new AtlasMemoryContextComposer(
            new MemoryRecallInput,
            new AtlasMemoryRecallRelevanceScorer,
        );

        $items = $composer->compose([
            [
                'id' => 'negative-pressure',
                'title' => 'Raw priority loses to effective priority',
                'body' => 'Candidate with raw priority 100 but two explicit negatives.',
                'type' => 'decision',
                'scope_type' => 'global',
                'priority' => 100,
                'importance' => 3,
                'confidence' => 0.7,
                'hybrid_score' => 0.5,
                'negative_count' => 2,
            ],
            [
                'id' => 'stale-tie',
                'title' => 'Stale tie loses to health',
                'body' => 'Candidate with raw priority 92 and one stale vote.',
                'type' => 'decision',
                'scope_type' => 'global',
                'priority' => 92,
                'importance' => 3,
                'confidence' => 0.7,
                'hybrid_score' => 0.5,
                'stale_count' => 1,
            ],
            [
                'id' => 'healthy-tie',
                'title' => 'Healthy tie survives first',
                'body' => 'Candidate with raw priority 80 and no feedback pressure.',
                'type' => 'decision',
                'scope_type' => 'global',
                'priority' => 80,
                'importance' => 3,
                'confidence' => 0.7,
                'hybrid_score' => 0.5,
            ],
        ], [], [], [
            'memory_recall_limit' => 2,
            'memory_recall_budget_chars' => 5000,
            'memory_recall_item_chars' => 1000,
        ]);

        $this->assertSame([
            'Healthy tie survives first',
            'Stale tie loses to health',
        ], array_column($items, 'title'));
        $this->assertSame(80, data_get($items[0], 'explain.feedback_decay.effective_priority'));
        $this->assertSame(100, data_get($items[0], 'explain.feedback_decay.health_score'));
        $this->assertSame(80, data_get($items[1], 'explain.feedback_decay.effective_priority'));
        $this->assertSame(88, data_get($items[1], 'explain.feedback_decay.health_score'));
    }
}
