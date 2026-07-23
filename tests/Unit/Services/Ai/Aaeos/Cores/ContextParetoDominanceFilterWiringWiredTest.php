<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos\Cores;

use App\Services\Ai\Aaeos\Cores\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\Aaeos\Cores\ContextParetoDominanceFilter;
use App\Services\Ai\Memory\AtlasMemoryContextComposer;
use App\Services\Ai\Memory\MemoryRecallInput;
use PHPUnit\Framework\TestCase;

/**
 * Proves ContextParetoDominanceFilter is wired into AtlasMemoryContextComposer::compose():
 * a candidate that is both lower-relevance and strictly older than another candidate is
 * Pareto-dominated and must be dropped before the final ranked budget-fill.
 */
final class ContextParetoDominanceFilterWiringWiredTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $options = [
        'memory_recall_limit' => 10,
        'memory_recall_budget_chars' => 5000,
        'memory_recall_item_chars' => 1000,
    ];

    public function test_dominated_candidate_is_dropped_from_composed_context(): void
    {
        $composer = new AtlasMemoryContextComposer(
            new MemoryRecallInput,
            new AtlasMemoryRecallRelevanceScorer,
        );

        $registry = [
            [
                'id' => 'strong-fresh',
                'type' => 'decision',
                'scope' => 'global',
                'title' => 'Strong fresh memory',
                'summary' => 'high relevance, recorded today',
                'priority' => 'high',
                'importance' => 'high',
                'confidence' => 'high',
                'recorded_at' => now()->toIso8601String(),
            ],
            [
                'id' => 'weak-stale',
                'type' => 'decision',
                'scope' => 'global',
                'title' => 'Weak stale memory',
                'summary' => 'low relevance, recorded a year ago',
                'priority' => 'low',
                'importance' => 'low',
                'confidence' => 'low',
                'recorded_at' => now()->subDays(400)->toIso8601String(),
            ],
        ];

        $items = $composer->compose($registry, [], [], $this->options);
        $titles = array_column($items, 'title');

        $this->assertContains('Strong fresh memory', $titles);
        $this->assertNotContains('Weak stale memory', $titles, 'Pareto-dominated candidate (lower score AND older) must be dropped');
    }

    public function test_candidates_without_known_age_are_never_dropped(): void
    {
        $composer = new AtlasMemoryContextComposer(
            new MemoryRecallInput,
            new AtlasMemoryRecallRelevanceScorer,
        );

        $registry = [
            ['id' => 'a', 'type' => 'decision', 'title' => 'No freshness A', 'summary' => 'no recorded_at', 'confidence' => 'low'],
            ['id' => 'b', 'type' => 'decision', 'title' => 'No freshness B', 'summary' => 'no recorded_at either', 'confidence' => 'high'],
        ];

        $items = $composer->compose($registry, [], [], $this->options);
        $titles = array_column($items, 'title');

        $this->assertContains('No freshness A', $titles);
        $this->assertContains('No freshness B', $titles);
    }

    public function test_pareto_filter_is_used_directly_on_dominance_semantics(): void
    {
        $filter = new ContextParetoDominanceFilter;

        $result = $filter->filter(
            [
                ['id' => 'strong', 'score' => 0.9, 'age_days' => 1.0],
                ['id' => 'weak', 'score' => 0.2, 'age_days' => 400.0],
            ],
            ['score' => 'maximize', 'age_days' => 'minimize'],
        );

        $this->assertSame(['strong'], $result['frontier']);
    }
}
