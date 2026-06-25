<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Cortex;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexSourceInventory;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionCortexSourceInventory: complete declaration of the 5 required source
 * kinds ⇒ inventory listed with defaults applied; missing required kind ⇒ missing_required_source:<kind>
 * blocker; duplicate source_id ⇒ duplicate_source_id:<id>; output sorted by (kind, source_id).
 */
final class AtlasSelfConstructionCortexSourceInventoryTest extends TestCase
{
    private function completeSources(): array
    {
        return [
            ['source_id' => 'docs.canonical', 'kind' => 'docs'],
            ['source_id' => 'pg.code_index', 'kind' => 'code_index'],
            ['source_id' => 'atlas.memory', 'kind' => 'memory'],
            ['source_id' => 'evidence.ledger', 'kind' => 'evidence_ledger'],
            ['source_id' => 'atlas.task.queue', 'kind' => 'task_queue'],
        ];
    }

    public function test_complete_inventory_yields_zero_blockers_with_defaults_applied(): void
    {
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $this->completeSources()]);
        $this->assertSame([], $r['blockers']);
        $this->assertCount(5, $r['inventory']);
        foreach ($r['inventory'] as $row) {
            $this->assertSame(AtlasSelfConstructionCortexSourceInventory::DEFAULT_AUTHORITY, $row['authority']);
            $this->assertSame(AtlasSelfConstructionCortexSourceInventory::DEFAULT_FRESHNESS, $row['freshness_requirement']);
            $this->assertSame(AtlasSelfConstructionCortexSourceInventory::DEFAULT_WORKSPACE_BOUNDARY, $row['workspace_boundary']);
            $this->assertTrue($row['read_only_available']);
        }
    }

    public function test_missing_required_kind_yields_named_blocker(): void
    {
        $sources = $this->completeSources();
        // drop the memory source
        array_splice($sources, 2, 1);
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $sources]);
        $this->assertContains('missing_required_source:memory', $r['blockers']);
    }

    public function test_duplicate_source_id_yields_named_blocker_first_wins(): void
    {
        $sources = $this->completeSources();
        $sources[] = ['source_id' => 'docs.canonical', 'kind' => 'docs']; // duplicate id
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $sources]);
        $this->assertContains('duplicate_source_id:docs.canonical', $r['blockers']);
        // first occurrence preserved (only 5 inventory rows total, dup dropped)
        $this->assertCount(5, $r['inventory']);
    }

    public function test_inventory_is_sorted_by_kind_then_source_id(): void
    {
        $sources = [
            ['source_id' => 'zebra.code', 'kind' => 'code_index'],
            ['source_id' => 'alpha.code', 'kind' => 'code_index'],
            ['source_id' => 'docs.x', 'kind' => 'docs'],
            ['source_id' => 'mem.x', 'kind' => 'memory'],
            ['source_id' => 'ev.x', 'kind' => 'evidence_ledger'],
            ['source_id' => 'tq.x', 'kind' => 'task_queue'],
        ];
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $sources]);
        // First two should be code_index entries in id order.
        $this->assertSame('code_index', $r['inventory'][0]['kind']);
        $this->assertSame('alpha.code', $r['inventory'][0]['source_id']);
        $this->assertSame('zebra.code', $r['inventory'][1]['source_id']);
    }

    public function test_explicit_authority_freshness_workspace_overrides_defaults(): void
    {
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory([
            'sources' => [
                ['source_id' => 'docs.canonical', 'kind' => 'docs', 'authority' => 'external_review', 'freshness_requirement' => 'on_change', 'workspace_boundary' => 'umbrella_repo', 'read_only_available' => false],
                ['source_id' => 'pg.code_index', 'kind' => 'code_index'],
                ['source_id' => 'atlas.memory', 'kind' => 'memory'],
                ['source_id' => 'evidence.ledger', 'kind' => 'evidence_ledger'],
                ['source_id' => 'atlas.task.queue', 'kind' => 'task_queue'],
            ],
        ]);
        $docs = $r['inventory'][1]; // (kind, source_id) sort puts code_index first; docs is 2nd kind
        $byId = [];
        foreach ($r['inventory'] as $row) {
            $byId[$row['source_id']] = $row;
        }
        $this->assertSame('external_review', $byId['docs.canonical']['authority']);
        $this->assertSame('on_change', $byId['docs.canonical']['freshness_requirement']);
        $this->assertSame('umbrella_repo', $byId['docs.canonical']['workspace_boundary']);
        $this->assertFalse($byId['docs.canonical']['read_only_available']);
    }
}
