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

    public function test_fresh_source_has_freshness_status_fresh_when_recently_updated(): void
    {
        $sources = $this->completeSources();
        $sources[0]['last_updated_at'] = '2026-06-30T09:59:00+00:00'; // 60s before now_at
        $sources[0]['max_age_s'] = 3600;
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory([
            'sources' => $sources,
            'now_at'  => '2026-06-30T10:00:00+00:00',
        ]);
        $byId = array_column($r['inventory'], null, 'source_id');
        $this->assertSame(AtlasSelfConstructionCortexSourceInventory::FRESHNESS_FRESH, $byId['docs.canonical']['freshness_status']);
    }

    public function test_stale_source_has_freshness_status_stale_when_too_old(): void
    {
        $sources = $this->completeSources();
        $sources[0]['last_updated_at'] = '2026-06-30T07:00:00+00:00'; // 10800s before now_at, max_age=3600
        $sources[0]['max_age_s'] = 3600;
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory([
            'sources' => $sources,
            'now_at'  => '2026-06-30T10:00:00+00:00',
        ]);
        $byId = array_column($r['inventory'], null, 'source_id');
        $this->assertSame(AtlasSelfConstructionCortexSourceInventory::FRESHNESS_STALE, $byId['docs.canonical']['freshness_status']);
    }

    public function test_required_source_summary_shows_present_and_missing_kinds(): void
    {
        $sources = [
            ['source_id' => 'docs.x', 'kind' => 'docs'],
            ['source_id' => 'mem.x', 'kind' => 'memory'],
            ['source_id' => 'tq.x', 'kind' => 'task_queue'],
        ];
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $sources]);
        $this->assertEqualsCanonicalizing(['docs', 'memory', 'task_queue'], $r['required_summary']['present']);
        $this->assertEqualsCanonicalizing(['code_index', 'evidence_ledger'], $r['required_summary']['missing']);
    }

    public function test_bounded_provider_safe_output_has_exactly_known_top_level_keys(): void
    {
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $this->completeSources()]);
        $keys = array_keys($r);
        sort($keys);
        $this->assertSame(['blockers', 'inventory', 'required_summary', 'schema'], $keys);
    }

    public function test_optional_kinds_provider_projection_worker_outcome_project_lane_are_recognized(): void
    {
        $sources = array_merge($this->completeSources(), [
            ['source_id' => 'claude.projection', 'kind' => 'provider_projection'],
            ['source_id' => 'worker.outcomes', 'kind' => 'worker_outcome'],
            ['source_id' => 'lane.marketing', 'kind' => 'project_lane'],
        ]);

        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $sources]);

        $this->assertSame([], $r['blockers']);
        $this->assertCount(8, $r['inventory']);
    }

    public function test_unrecognized_kind_yields_named_blocker(): void
    {
        $sources = array_merge($this->completeSources(), [
            ['source_id' => 'mystery.source', 'kind' => 'totally_unknown_kind'],
        ]);

        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $sources]);

        $this->assertContains('unrecognized_source_kind:totally_unknown_kind', $r['blockers']);
    }

    public function test_optional_kinds_are_not_in_required_summary_missing_when_absent(): void
    {
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $this->completeSources()]);

        $this->assertSame([], $r['blockers']);
        $this->assertNotContains('provider_projection', $r['required_summary']['missing']);
        $this->assertNotContains('worker_outcome', $r['required_summary']['missing']);
        $this->assertNotContains('project_lane', $r['required_summary']['missing']);
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
