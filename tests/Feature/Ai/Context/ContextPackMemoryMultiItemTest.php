<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use Tests\TestCase;

/**
 * RAG-04 — the memory section delivers multiple recalled items within the sub-budget
 * by compact per-item caps (body truncated at the tail, title/summary preserved).
 */
final class ContextPackMemoryMultiItemTest extends TestCase
{
    private const TASK = 'como funciona o recall hibrido de memoria com demotion';

    private const LONG_BODY = 'Hybrid memory recall demotion ranking note with extended operational detail about retrieval paths, feedback loops, and provider-safe delivery semantics that must be compacted when the pack splits budget across multiple recalled entries.';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.aobg.delivered_pack_ledger.enabled', false);
        config()->set('atlas.aobg.include_runtime_compose', false);
        config()->set('atlas.aurg.enabled', false);
        config()->set('atlas.aobg.semantic_retrieval', false);
    }

    public function test_delivers_multiple_memories_within_budget_with_per_item_truncation(): void
    {
        $this->mockRecallCandidates(4);

        $pack = app(AtlasOpenBrainContextPackService::class)->packFor(
            self::TASK,
            ['memory_budget' => 2000, 'budget' => 4000, 'code_budget' => 0],
        );

        $recallCount = (int) data_get($pack, 'provenance.memory.recall_count', 0);
        $delivered = count($pack['memory']);

        $this->assertSame(4, $recallCount);
        $this->assertGreaterThanOrEqual(3, $delivered, 'pack delivers plural memories when recall_count >= 3');
        $this->assertLessThanOrEqual(2000, data_get($pack, 'budget.memory_budget_chars'));
        $this->assertLessThanOrEqual(2000, $this->memoryChars($pack['memory']));

        foreach ($pack['memory'] as $item) {
            $this->assertNotSame('', (string) ($item['title'] ?? ''));
            $this->assertNotSame('', (string) ($item['summary'] ?? ''));
            if (str_contains((string) ($item['body'] ?? ''), '… [truncated]')) {
                $this->assertStringEndsWith('… [truncated]', (string) $item['body']);
            }
        }
    }

    public function test_per_item_cap_respects_minimum_and_total_budget(): void
    {
        $this->mockRecallCandidates(3, str_repeat('x', 900));

        $pack = app(AtlasOpenBrainContextPackService::class)->packFor(
            self::TASK,
            ['memory_budget' => 1500, 'budget' => 3000, 'code_budget' => 0],
        );

        $this->assertGreaterThanOrEqual(2, count($pack['memory']));
        $this->assertLessThanOrEqual(1500, $this->memoryChars($pack['memory']));
        foreach ($pack['memory'] as $item) {
            $this->assertLessThanOrEqual(800, strlen((string) ($item['body'] ?? '')));
        }
    }

    private function mockRecallCandidates(int $count, ?string $body = null): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'source_ref_type' => 'atlas_memory_entry',
                'source_ref_id' => 'memory-rag04-'.$i,
                'score' => 1.0 - ($i * 0.01),
                'type' => 'decision',
                'scope' => 'global',
                'title' => 'Recall hibrido de memoria com demotion item '.$i,
                'summary' => 'Hybrid memory recall demotion summary '.$i,
                'body' => $body ?? (self::LONG_BODY.' extra tail '.$i),
                'privacy_class' => 'normal',
            ];
        }

        $this->mock(AtlasHybridMemoryRetrievalService::class, function ($mock) use ($rows, $count): void {
            $mock->shouldReceive('recall')
                ->twice()
                ->andReturn([
                    'summary' => [
                        'policy' => 'provider_safe_only',
                        'recall_count' => $count,
                        'redacted_ref_count' => 0,
                    ],
                    'recall' => $rows,
                ]);
        });
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     */
    private function memoryChars(array $items): int
    {
        $chars = 0;
        foreach ($items as $item) {
            $chars += strlen(
                (string) ($item['title'] ?? '')
                .(string) ($item['summary'] ?? '')
                .(string) ($item['body'] ?? ''),
            );
        }

        return $chars;
    }
}
