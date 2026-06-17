<?php

declare(strict_types=1);

namespace Tests\Feature\Memory;

use App\Models\AiCompoundingMemory;
use App\Services\Ai\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\AtlasMemoryContextComposer;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ACDE #3 — compounding-recall arm. PROMOTED compounding learnings (AiCompoundingMemory) are surfaced into
 * the SAME hybrid recall the live provider injection consumes, so every session reads what the loop already
 * learned. Flag-gated default-OFF => the 4th source is absent and recall is byte-identical. Provider-safe:
 * only status=active + confidence-floored claims, never the raw payload.
 */
final class AtlasCompoundingRecallArmTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('ai_compounding_memories')) {
            (require base_path('database/migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();
        }
    }

    private function seedLearning(string $claim, int $confidence, string $status = 'active'): AiCompoundingMemory
    {
        return AiCompoundingMemory::query()->create([
            'schema_version' => 'atlas.compounding.v1',
            'memory_type' => 'loop_merge_memory',
            'scope' => 'global',
            'flow_id' => 'atlas_loop',
            'status' => $status,
            'claim' => $claim,
            'confidence' => $confidence,
            'evidence_refs' => [],
            'revalidation_policy' => 'none',
            'payload' => ['secret_internal' => 'must-not-leak'],
            'memory_hash' => hash('sha256', $claim),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function compoundingItems(string $query, int $limit, bool $enabled): array
    {
        $svc = app(AtlasHybridMemoryRetrievalService::class);
        $m = new ReflectionMethod($svc, 'compoundingItems');
        $m->setAccessible(true);

        return (array) $m->invoke($svc, $query, $limit, $enabled);
    }

    public function test_off_is_byte_identical_no_compounding_source(): void
    {
        config()->set('atlas.semantic_memory.compounding_recall_enabled', false);
        $this->seedLearning('Loop auto-merge endureceu o gate de refund rounding', 75);

        $this->assertSame([], $this->compoundingItems('refund rounding', 6, true), 'OFF => the arm returns nothing (no 4th source)');
    }

    public function test_on_surfaces_an_active_promoted_learning_provider_safe(): void
    {
        config()->set('atlas.semantic_memory.compounding_recall_enabled', true);
        $this->seedLearning('Loop auto-merge endureceu o gate de refund rounding drift', 75);

        $items = $this->compoundingItems('refund rounding', 6, true);
        $this->assertCount(1, $items);
        $item = $items[0];
        $this->assertSame('ai_compounding_memory', $item['source_type']);
        $this->assertStringContainsString('refund rounding', (string) $item['claim']);
        // PROVIDER-SAFE: only the claim is emitted — the raw payload (secret_internal) never appears.
        $this->assertArrayNotHasKey('payload', $item);
        $this->assertStringNotContainsString('must-not-leak', json_encode($item));
    }

    public function test_confidence_floor_filters_low_confidence(): void
    {
        config()->set('atlas.semantic_memory.compounding_recall_enabled', true);
        config()->set('atlas.semantic_memory.compounding_recall_min_confidence', 70);
        $this->seedLearning('high confidence learning about caching', 80);
        $this->seedLearning('low confidence learning about caching', 40);

        $items = $this->compoundingItems('caching', 6, true);
        $this->assertCount(1, $items, 'only the >= floor learning survives');
        $this->assertStringContainsString('high confidence', (string) $items[0]['claim']);
    }

    public function test_inactive_promoted_learnings_are_excluded(): void
    {
        config()->set('atlas.semantic_memory.compounding_recall_enabled', true);
        $this->seedLearning('retired learning', 90, 'retired');

        $this->assertSame([], $this->compoundingItems('learning', 6, true), 'only status=active (promoted) is surfaced');
    }

    public function test_composer_ranks_the_compounding_source_into_recall(): void
    {
        // Pure (no DB): the 4th source flows through compose into a ranked, source-attributed recall item.
        $composer = app(AtlasMemoryContextComposer::class);
        $compounding = [[
            'id' => 'c1', 'type' => 'loop_merge_memory', 'scope' => 'global', 'scope_type' => 'global',
            'title' => 'a promoted learning', 'summary' => '', 'claim' => 'iterate-to-green beats one-shot on complex tasks',
            'confidence' => 80, 'content_hash' => 'h', 'reason' => 'test',
        ]];

        $out = $composer->compose([], [], [], ['memory_recall_limit' => 5], $compounding);
        $this->assertNotSame([], $out, 'the compounding source produces a recall item');
        $this->assertSame('compounding', $out[0]['source']);
        $this->assertSame('ai_compounding_memory', $out[0]['source_ref_type']);
        $this->assertStringContainsString('iterate-to-green', (string) $out[0]['excerpt']);

        // byte-identical: the legacy 4-arg compose (no compounding) yields nothing here.
        $this->assertSame([], $composer->compose([], [], [], ['memory_recall_limit' => 5]), 'no 4th source => unchanged');
    }
}
