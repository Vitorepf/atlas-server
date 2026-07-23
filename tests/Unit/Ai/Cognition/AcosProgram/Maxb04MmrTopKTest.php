<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\AgenticEngineeringOs\Scoring\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\Memory\AtlasMemoryContextComposer;
use App\Services\Ai\Memory\MemoryMmrTopKSelector;
use App\Services\Ai\Memory\MemoryRecallInput;
use Tests\TestCase;

/**
 * MAXB-04 — MMR top-K: 3 near-duplicates → only 1 in top-3; OFF = score order.
 */
final class Maxb04MmrTopKTest extends TestCase
{
    public function test_three_near_duplicates_only_one_survives_when_limit_three_with_balanced_lambda(): void
    {
        $selector = new MemoryMmrTopKSelector;
        $dup = [1.0, 0.0, 0.0];
        $u = [0.0, 1.0, 0.0];
        $v = [0.0, 0.0, 1.0];

        $candidates = [
            $this->cand('a', 10.0, $dup),
            $this->cand('b', 9.5, $dup),
            $this->cand('c', 9.0, $dup),
            $this->cand('u', 9.8, $u),
            $this->cand('v', 9.7, $v),
        ];
        $vectors = ['a' => $dup, 'b' => $dup, 'c' => $dup, 'u' => $u, 'v' => $v];

        $selected = $selector->select(
            $candidates,
            3,
            0.7,
            static fn (string $x, string $y): ?float => MemoryMmrTopKSelector::cosine($vectors[$x], $vectors[$y]),
        );

        $ids = array_map(static fn (array $c): string => (string) $c['source_ref_id'], $selected);
        $this->assertCount(3, $ids);
        $fromDup = array_values(array_intersect($ids, ['a', 'b', 'c']));
        $this->assertCount(1, $fromDup, 'exactly one near-duplicate should survive in top-3');
        $this->assertContains('u', $ids);
        $this->assertContains('v', $ids);
    }

    public function test_composer_flag_off_keeps_score_order_byte_stable(): void
    {
        config(['atlas.semantic_memory.mmr_top_k_enabled' => false]);

        $composer = new AtlasMemoryContextComposer(new MemoryRecallInput, new AtlasMemoryRecallRelevanceScorer);
        $registry = [
            ['id' => 'a', 'title' => 'A', 'body' => 'alpha body text here', 'priority' => 90, 'importance' => 5, 'confidence' => 0.9, 'embedding_vector' => [1.0, 0.0]],
            ['id' => 'b', 'title' => 'B', 'body' => 'beta body text here', 'priority' => 80, 'importance' => 5, 'confidence' => 0.9, 'embedding_vector' => [1.0, 0.0]],
            ['id' => 'c', 'title' => 'C', 'body' => 'gamma body text here', 'priority' => 70, 'importance' => 5, 'confidence' => 0.9, 'embedding_vector' => [0.0, 1.0]],
        ];

        $items = $composer->compose($registry, [], [], [
            'memory_recall_limit' => 3,
            'memory_recall_budget_chars' => 5000,
            'memory_recall_item_chars' => 1000,
        ]);

        $this->assertSame(['A', 'B', 'C'], array_column($items, 'title'));
    }

    public function test_composer_flag_on_diversifies_near_duplicates(): void
    {
        config([
            'atlas.semantic_memory.mmr_top_k_enabled' => true,
            'atlas.semantic_memory.mmr_lambda' => 0.7,
        ]);

        $composer = new AtlasMemoryContextComposer(new MemoryRecallInput, new AtlasMemoryRecallRelevanceScorer);
        $registry = [
            ['id' => 'a', 'title' => 'A', 'body' => 'alpha body text here', 'priority' => 90, 'importance' => 5, 'confidence' => 0.9, 'embedding_vector' => [1.0, 0.0, 0.0]],
            ['id' => 'b', 'title' => 'B', 'body' => 'beta body text here', 'priority' => 80, 'importance' => 5, 'confidence' => 0.9, 'embedding_vector' => [1.0, 0.0, 0.0]],
            ['id' => 'c', 'title' => 'C', 'body' => 'gamma body text here', 'priority' => 70, 'importance' => 5, 'confidence' => 0.9, 'embedding_vector' => [1.0, 0.0, 0.0]],
            ['id' => 'u', 'title' => 'U', 'body' => 'unique orthogonal body', 'priority' => 85, 'importance' => 5, 'confidence' => 0.9, 'embedding_vector' => [0.0, 1.0, 0.0]],
            ['id' => 'v', 'title' => 'V', 'body' => 'another orthogonal body', 'priority' => 82, 'importance' => 5, 'confidence' => 0.9, 'embedding_vector' => [0.0, 0.0, 1.0]],
        ];

        $items = $composer->compose($registry, [], [], [
            'memory_recall_limit' => 3,
            'memory_recall_budget_chars' => 5000,
            'memory_recall_item_chars' => 1000,
        ]);

        $titles = array_column($items, 'title');
        $this->assertContains('U', $titles);
        $this->assertContains('V', $titles);
        $dupTitles = array_values(array_intersect($titles, ['A', 'B', 'C']));
        $this->assertCount(1, $dupTitles);
    }

    /**
     * @param  list<float>  $vector
     * @return array<string,mixed>
     */
    private function cand(string $id, float $score, array $vector): array
    {
        return [
            'source' => 'registry',
            'source_ref_type' => 'atlas_memory_entry',
            'source_ref_id' => $id,
            'score' => $score,
            'embedding_vector' => $vector,
            'title' => $id,
            'explain' => [],
        ];
    }
}
