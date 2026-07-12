<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookCrossExecutorRetrieval;
use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookMemoryBridge;
use App\Services\Ai\Kernel\Procedural\ProceduralPlaybook;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * ASI-14 — procedural memory as first-class type + cross-executor retrieval.
 */
final class Asi14ProceduralMemoryBridgeTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-12T10:00:00+00:00');
        $this->createAtlasMemoryEntryTable();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    public function test_procedural_is_a_first_class_memory_type(): void
    {
        $this->assertContains('procedural', AtlasMemoryEntry::TYPES, 'ASI-14 requires procedural as a canonical type.');
    }

    public function test_bridge_persists_playbook_as_procedural_memory_entry_and_rehydrates_it(): void
    {
        $playbook = new ProceduralPlaybook(
            taskCategory: 'php.migration',
            objective: 'Add nullable column safely on pgsql + sqlite.',
            steps: ['read migration', 'guard hasColumn', 'add nullable', 'reindex if needed'],
            postconditions: ['migrate:fresh green', 'sqlite tests green'],
            forbiddenActions: ['drop existing column', 'change existing default'],
            priorCorrections: ['do not forget hasColumn guard for pgsql'],
        );

        $bridge = new AtlasProceduralPlaybookMemoryBridge;
        $result = $bridge->persist($playbook);
        $this->assertTrue($result['persisted']);
        $this->assertSame('procedural', $result['memory_type']);
        $this->assertSame('php.migration', $result['task_category_key']);

        /** @var AtlasMemoryEntry $entry */
        $entry = AtlasMemoryEntry::query()->find($result['memory_entry_id']);
        $this->assertNotNull($entry);
        $this->assertSame('procedural', $entry->memory_type);
        $this->assertSame('active', $entry->status);

        $back = $bridge->fromMemoryEntry($entry);
        $this->assertNotNull($back);
        $this->assertSame('Add nullable column safely on pgsql + sqlite.', $back->objective);
        $this->assertSame(['migrate:fresh green', 'sqlite tests green'], $back->postconditions);
    }

    public function test_retrieval_returns_playbook_by_task_category_capped_and_context_only(): void
    {
        $bridge = new AtlasProceduralPlaybookMemoryBridge;

        $bridge->persist(new ProceduralPlaybook('php.migration', 'goal-a', ['s1'], ['p1'], []));
        $bridge->persist(new ProceduralPlaybook('php.migration', 'goal-b', ['s1'], ['p1'], []));
        $bridge->persist(new ProceduralPlaybook('php.migration', 'goal-c', ['s1'], ['p1'], []));
        $bridge->persist(new ProceduralPlaybook('go.build', 'goal-d', ['s1'], ['p1'], []));

        $retrieval = new AtlasProceduralPlaybookCrossExecutorRetrieval($bridge);
        $envelope = $retrieval->retrieveByTaskCategory('php.migration', ['cap' => 2, 'executor' => 'forge']);

        $this->assertSame('forge', $envelope['executor']);
        $this->assertSame('atlas.procedural_playbook.retrieval.v1', $envelope['schema']);
        $this->assertStringContainsString('CONTEXT', $envelope['authority_floor']);
        $this->assertCount(2, $envelope['items'], 'ASI-14 cap of 1–2 per packet');
        foreach ($envelope['items'] as $item) {
            $this->assertSame('inject_as_context', $item['execution_hint']);
        }
    }

    public function test_retrieval_returns_no_items_when_task_category_never_seen(): void
    {
        $retrieval = new AtlasProceduralPlaybookCrossExecutorRetrieval;
        $envelope = $retrieval->retrieveByTaskCategory('never.seen');

        $this->assertSame([], $envelope['items']);
    }
}
