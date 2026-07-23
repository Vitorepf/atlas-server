<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\Aaeos\Cores\AtlasMemoryRecallRelevanceScorer;
use App\Services\Ai\Memory\AtlasMemoryContextComposer;
use App\Services\Ai\Memory\MemoryRecallInput;
use PHPUnit\Framework\TestCase;

/**
 * Drift guard: the composer's recall score MUST stay byte-for-byte equal to the single
 * source of truth, App\Services\Ai\Aaeos\Cores\AtlasMemoryRecallRelevanceScorer. Before
 * consolidation the registry/verbatim/semantic formula was inlined here AND mirrored in the
 * scorer; this test pins them to one implementation so they can never silently diverge.
 */
final class AtlasMemoryContextComposerTest extends TestCase
{
    private AtlasMemoryRecallRelevanceScorer $scorer;

    private AtlasMemoryContextComposer $composer;

    /** @var array<string,mixed> */
    private array $options = [
        'memory_recall_limit' => 10,
        'memory_recall_budget_chars' => 5000,
        'memory_recall_item_chars' => 1000,
    ];

    protected function setUp(): void
    {
        $this->scorer = new AtlasMemoryRecallRelevanceScorer();
        $this->composer = new AtlasMemoryContextComposer(new MemoryRecallInput(), $this->scorer);
    }

    public function testComposerScoresMatchTheCanonicalFormulaPerChannel(): void
    {
        $registry = [[
            'id' => 'r1', 'title' => 'RegDecision', 'body' => 'Registry decision body text',
            'type' => 'decision', 'scope_type' => 'task',
            'priority' => 80, 'importance' => 4, 'confidence' => 0.9, 'hybrid_score' => 0.5,
        ]];
        $verbatim = [[
            'id' => 'v1', 'title' => 'VerbEvidence', 'snippet' => 'Verbatim approved snippet text',
            'type' => 'evidence', 'scope_type' => 'project', 'hybrid_score' => 0.5,
        ]];
        $semantic = [[
            'id' => 's1', 'title' => 'SemNote', 'excerpt' => 'Semantic note excerpt text',
            'type' => 'semantic_note', 'score' => 0.9,
        ]];

        $items = $this->composer->compose($registry, $verbatim, $semantic, $this->options);

        $this->assertCount(3, $items);

        // Highest-first ordering across channels is preserved (registry > verbatim > semantic here).
        // Registry: 80 + 4*10 + 0.9*10 + 0.5*30 + scope(task)=22 + type(decision)=16 = 182.0
        $this->assertSame('RegDecision', $items[0]['title']);
        $this->assertSame(182.0, $items[0]['score']);
        // Verbatim: 82 + 0.5*24 + scope(project)=16 + type(evidence)=11 = 121.0
        $this->assertSame('VerbEvidence', $items[1]['title']);
        $this->assertSame(121.0, $items[1]['score']);
        // Semantic: 0.9*100 + type(semantic_note)=5 = 95.0
        $this->assertSame('SemNote', $items[2]['title']);
        $this->assertSame(95.0, $items[2]['score']);
    }

    public function testEachComposedScoreEqualsTheStandaloneScorer(): void
    {
        $registry = [[
            'id' => 'r1', 'title' => 'Reg', 'body' => 'b',
            'type' => 'decision', 'scope_type' => 'task',
            'priority' => 80, 'importance' => 4, 'confidence' => 0.9, 'hybrid_score' => 0.5,
        ]];
        $verbatim = [[
            'id' => 'v1', 'title' => 'Verb', 'snippet' => 's',
            'type' => 'evidence', 'scope_type' => 'project', 'hybrid_score' => 0.5,
        ]];
        $semantic = [[
            'id' => 's1', 'title' => 'Sem', 'excerpt' => 'e',
            'type' => 'semantic_note', 'score' => 0.9,
        ]];

        $items = $this->composer->compose($registry, $verbatim, $semantic, $this->options);
        $byTitle = [];
        foreach ($items as $item) {
            $byTitle[$item['title']] = $item['score'];
        }

        $this->assertSame(
            round($this->scorer->score([
                'source' => 'registry', 'type' => 'decision', 'scope_type' => 'task',
                'priority' => 80, 'importance' => 4, 'confidence' => 0.9, 'hybrid_score' => 0.5,
            ]), 3),
            $byTitle['Reg'],
        );
        $this->assertSame(
            round($this->scorer->score([
                'source' => 'verbatim', 'type' => 'evidence', 'scope_type' => 'project', 'hybrid_score' => 0.5,
            ]), 3),
            $byTitle['Verb'],
        );
        $this->assertSame(
            round($this->scorer->score([
                'source' => 'semantic', 'type' => 'semantic_note', 'score' => 0.9,
            ]), 3),
            $byTitle['Sem'],
        );
    }

    public function testRegistryDefaultsMatchTheCanonicalFloor(): void
    {
        // A bare registry row (no priority/importance/confidence/hybrid/scope/type) must resolve to
        // the same default floor as the scorer: 50 + 3*10 + 0.7*10 + 0 + scope(global)=4 + type(memory)=5 = 96.0
        $items = $this->composer->compose(
            [['id' => 'r', 'title' => 'Bare', 'body' => 'text']],
            [],
            [],
            $this->options,
        );

        $this->assertCount(1, $items);
        $this->assertSame(96.0, $items[0]['score']);
        $this->assertSame(
            round($this->scorer->score(['source' => 'registry']), 3),
            $items[0]['score'],
        );
    }
}
