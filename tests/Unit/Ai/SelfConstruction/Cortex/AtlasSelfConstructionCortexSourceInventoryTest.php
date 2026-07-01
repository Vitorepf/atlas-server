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
            ['source_id' => 'atlas.queue.health', 'kind' => 'queue_health'],
            ['source_id' => 'atlas.malformed.sweep', 'kind' => 'malformed_sweep'],
            ['source_id' => 'atlas.queued.targets', 'kind' => 'queued_targets'],
            ['source_id' => 'atlas.outcome.learning', 'kind' => 'outcome_learning'],
        ];
    }

    public function test_complete_inventory_yields_zero_blockers_with_defaults_applied(): void
    {
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $this->completeSources()]);
        $this->assertSame([], $r['blockers']);
        $this->assertCount(9, $r['inventory']);
        $this->assertSame(AtlasSelfConstructionCortexSourceInventory::CONTEXT_READY, $r['context_status']);
        foreach ($r['inventory'] as $row) {
            $this->assertSame(AtlasSelfConstructionCortexSourceInventory::DEFAULT_AUTHORITY, $row['authority']);
            $this->assertSame(AtlasSelfConstructionCortexSourceInventory::DEFAULT_FRESHNESS, $row['freshness_requirement']);
            $this->assertSame(AtlasSelfConstructionCortexSourceInventory::DEFAULT_WORKSPACE_BOUNDARY, $row['workspace_boundary']);
            $this->assertTrue($row['read_only_available']);
        }
    }

    public function test_new_required_kinds_marked_required_for_origination(): void
    {
        foreach (['queue_health', 'malformed_sweep', 'queued_targets', 'outcome_learning'] as $kind) {
            $this->assertContains($kind, AtlasSelfConstructionCortexSourceInventory::REQUIRED_KINDS);
        }
    }

    public function test_missing_new_required_kind_yields_context_not_ready(): void
    {
        $sources = $this->completeSources();
        // drop malformed_sweep (index 6)
        array_splice($sources, 6, 1);
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $sources]);
        $this->assertContains('missing_required_source:malformed_sweep', $r['blockers']);
        $this->assertSame(AtlasSelfConstructionCortexSourceInventory::CONTEXT_NOT_READY, $r['context_status']);
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
        // first occurrence preserved (only 9 inventory rows total, dup dropped)
        $this->assertCount(9, $r['inventory']);
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
        $this->assertEqualsCanonicalizing(
            ['code_index', 'evidence_ledger', 'queue_health', 'malformed_sweep', 'queued_targets', 'outcome_learning'],
            $r['required_summary']['missing'],
        );
    }

    public function test_bounded_provider_safe_output_has_exactly_known_top_level_keys(): void
    {
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $this->completeSources()]);
        $keys = array_keys($r);
        sort($keys);
        $this->assertSame(['authority_map', 'blockers', 'context_status', 'freshness_debt', 'inventory', 'required_summary', 'schema'], $keys);
    }

    public function test_authority_map_groups_source_ids_by_authority_and_kind(): void
    {
        $sources = $this->completeSources();
        $sources[] = ['source_id' => 'ext-docs', 'kind' => 'docs', 'authority' => 'external_partner'];

        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $sources]);

        $this->assertArrayHasKey('atlas_native', $r['authority_map']);
        $this->assertArrayHasKey('external_partner', $r['authority_map']);
        $this->assertSame(['ext-docs'], $r['authority_map']['external_partner']['docs']);
        $this->assertArrayHasKey('docs', $r['authority_map']['atlas_native']);
    }

    public function test_missing_required_source_produces_freshness_debt_entry(): void
    {
        $sources = array_values(array_filter($this->completeSources(), static fn (array $s): bool => $s['kind'] !== 'memory'));

        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $sources]);

        $memoryDebt = array_values(array_filter($r['freshness_debt'], static fn (array $d): bool => $d['kind'] === 'memory'));
        $this->assertNotEmpty($memoryDebt);
        $this->assertSame('unknown', $memoryDebt[0]['freshness_status']);
        $this->assertNotEmpty($memoryDebt[0]['repair_hint']);
    }

    public function test_stale_required_source_produces_freshness_debt_entry_with_repair_hint(): void
    {
        $sources = $this->completeSources();
        foreach ($sources as $i => $s) {
            if ($s['kind'] === 'docs') {
                $sources[$i]['last_updated_at'] = '2020-01-01T00:00:00Z';
                $sources[$i]['max_age_s'] = 60;
            }
        }

        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory([
            'sources' => $sources,
            'now_at' => '2020-01-02T00:00:00Z',
        ]);

        $docsDebt = array_values(array_filter($r['freshness_debt'], static fn (array $d): bool => $d['kind'] === 'docs'));
        $this->assertNotEmpty($docsDebt);
        $this->assertSame('stale', $docsDebt[0]['freshness_status']);
        $this->assertNotEmpty($docsDebt[0]['repair_hint']);
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
        $this->assertCount(12, $r['inventory']);
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

    public function test_stale_required_source_forces_context_not_ready(): void
    {
        $sources = $this->completeSources();
        foreach ($sources as $i => $s) {
            if ($s['kind'] === 'docs') {
                $sources[$i]['last_updated_at'] = '2020-01-01T00:00:00Z';
                $sources[$i]['max_age_s'] = 60;
            }
        }

        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory([
            'sources' => $sources,
            'now_at' => '2020-01-02T00:00:00Z',
        ]);

        $this->assertSame(AtlasSelfConstructionCortexSourceInventory::CONTEXT_NOT_READY, $r['context_status']);
        $this->assertContains('required_source_not_fresh:docs:docs.canonical', $r['blockers']);
    }

    public function test_advisory_only_stale_required_source_does_not_block_context_ready(): void
    {
        $freshAt = '2020-01-02T00:00:00Z';
        $sources = $this->completeSources();
        foreach ($sources as $i => $s) {
            if ($s['kind'] === 'docs') {
                $sources[$i]['last_updated_at'] = '2020-01-01T00:00:00Z';
                $sources[$i]['max_age_s'] = 60;
                $sources[$i]['advisory_only'] = true;
            } else {
                // Keep every other required source genuinely fresh so only the advisory-flagged
                // docs source is stale — isolating the advisory_only exemption under test.
                $sources[$i]['last_updated_at'] = $freshAt;
                $sources[$i]['max_age_s'] = 3600;
            }
        }

        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory([
            'sources' => $sources,
            'now_at' => $freshAt,
        ]);

        $this->assertSame(AtlasSelfConstructionCortexSourceInventory::CONTEXT_READY, $r['context_status']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_unknown_freshness_required_source_blocks_when_now_at_evaluated(): void
    {
        $sources = $this->completeSources();
        // docs never gets last_updated_at ⇒ freshness_status stays 'unknown' once now_at is evaluated.
        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory([
            'sources' => $sources,
            'now_at' => '2020-01-02T00:00:00Z',
        ]);

        $this->assertSame(AtlasSelfConstructionCortexSourceInventory::CONTEXT_NOT_READY, $r['context_status']);
        $this->assertContains('required_source_not_fresh:docs:docs.canonical', $r['blockers']);
    }

    public function test_read_only_unavailable_source_produces_named_blocker_and_repair_hint(): void
    {
        $sources = $this->completeSources();
        foreach ($sources as $i => $s) {
            if ($s['kind'] === 'docs') {
                $sources[$i]['read_only_available'] = false;
            }
        }

        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $sources]);

        $this->assertContains('source_not_read_only:docs.canonical', $r['blockers']);
    }

    public function test_workspace_boundary_outside_atlas_repo_produces_named_blocker(): void
    {
        $sources = $this->completeSources();
        foreach ($sources as $i => $s) {
            if ($s['kind'] === 'docs') {
                $sources[$i]['workspace_boundary'] = 'umbrella_repo';
            }
        }

        $r = (new AtlasSelfConstructionCortexSourceInventory)->inventory(['sources' => $sources]);

        $this->assertContains('source_outside_workspace_boundary:docs.canonical', $r['blockers']);
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
