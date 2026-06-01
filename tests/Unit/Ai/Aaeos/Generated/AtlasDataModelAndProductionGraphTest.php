<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDataModelAndProductionGraphService;
use Tests\TestCase;

/**
 * Pins the documented Obras data model: the closed entity set, the node/source/
 * decision state machines, the version ladder, the production graph chain and
 * the storage-ownership rule.
 *
 * @see docs/engineering-knowledge-base/obras/data-model-and-production-graph.md
 */
class AtlasDataModelAndProductionGraphTest extends TestCase
{
    private function service(): AtlasDataModelAndProductionGraphService
    {
        return new AtlasDataModelAndProductionGraphService();
    }

    /**
     * The doc enumerates exactly 18 canonical Obra tables. The full set passes;
     * a missing canonical table and an unknown table both fail with reasons.
     */
    public function test_entity_set_is_the_eighteen_documented_tables(): void
    {
        $svc = $this->service();
        $this->assertCount(18, AtlasDataModelAndProductionGraphService::CORE_ENTITIES);

        $full = $svc->validateEntities(AtlasDataModelAndProductionGraphService::CORE_ENTITIES);
        $this->assertSame('pass', $full['status']);
        $this->assertSame([], $full['missing']);
        $this->assertSame([], $full['unknown']);

        // Drop one canonical table and add a foreign one.
        $partial = array_values(array_filter(
            AtlasDataModelAndProductionGraphService::CORE_ENTITIES,
            static fn (string $t): bool => $t !== 'obra_evidence_events',
        ));
        $partial[] = 'kanban_columns';

        $report = $svc->validateEntities($partial);
        $this->assertSame('fail', $report['status']);
        $this->assertSame(['obra_evidence_events'], $report['missing']);
        $this->assertSame(['kanban_columns'], $report['unknown']);
    }

    /**
     * Node lifecycle: a single forward step is allowed; jumping straight to
     * "published" is rejected; an unknown status is rejected; and the documented
     * blocked branch (in construction -> needs source -> in construction) is allowed.
     */
    public function test_node_status_transitions_follow_the_documented_lifecycle(): void
    {
        $svc = $this->service();

        $advance = $svc->validateNodeStatus('draft', 'in construction');
        $this->assertTrue($advance['allowed']);
        $this->assertSame('advance', $advance['kind']);

        $skip = $svc->validateNodeStatus('draft', 'published');
        $this->assertFalse($skip['allowed']);
        $this->assertSame('invalid', $skip['kind']);
        $this->assertSame('fail', $skip['status']);

        $unknown = $svc->validateNodeStatus('draft', 'frozen');
        $this->assertFalse($unknown['allowed']);
        $this->assertSame('unknown_status', $unknown['kind']);

        $branch = $svc->validateNodeStatus('in construction', 'needs source');
        $this->assertTrue($branch['allowed']);
        $this->assertSame('branch_blocked', $branch['kind']);

        $resolve = $svc->validateNodeStatus('needs decision', 'in construction');
        $this->assertTrue($resolve['allowed']);
        $this->assertSame('resolve_blocked', $resolve['kind']);
    }

    /**
     * Source registry statuses are a closed set of exactly the six documented
     * values (including the Portuguese "fichada"); anything else fails.
     */
    public function test_source_status_closed_set(): void
    {
        $svc = $this->service();
        $this->assertCount(6, AtlasDataModelAndProductionGraphService::SOURCE_STATUSES);

        $this->assertSame('pass', $svc->validateSourceStatus('fichada')['status']);
        $this->assertSame('pass', $svc->validateSourceStatus('used')['status']);

        $bad = $svc->validateSourceStatus('archived');
        $this->assertSame('fail', $bad['status']);
        $this->assertFalse($bad['known']);
    }

    /**
     * Decision lifecycle: only an "active" decision may currently drive the Obra;
     * a revoked decision is a known status but can no longer be the active driver.
     */
    public function test_decision_status_only_active_drives_obra(): void
    {
        $svc = $this->service();

        $active = $svc->validateDecisionStatus('active');
        $this->assertSame('pass', $active['status']);
        $this->assertTrue($active['can_drive_obra']);

        $revoked = $svc->validateDecisionStatus('revoked');
        $this->assertTrue($revoked['known']);
        $this->assertFalse($revoked['can_drive_obra']);
        $this->assertContains(
            'decision_not_active: a revoked decision can no longer be the active driver of the Obra.',
            $revoked['blocking_reasons'],
        );

        $this->assertSame('fail', $svc->validateDecisionStatus('paused')['status']);
    }

    /**
     * Version ladder: forward progression is allowed; reaching delivery v1.0
     * requires at least one gate run; a backwards move is rejected.
     */
    public function test_version_ladder_forward_and_delivery_requires_gate_run(): void
    {
        $svc = $this->service();

        $forward = $svc->validateVersionProgression('v0.3', 'v0.4');
        $this->assertSame('pass', $forward['status']);
        $this->assertTrue($forward['advanced']);
        $this->assertSame('draft', $forward['from_milestone']);
        $this->assertSame('review', $forward['to_milestone']);

        // Delivery without a gate run is blocked.
        $deliveryNoGate = $svc->validateVersionProgression('v0.4', 'v1.0', false);
        $this->assertSame('fail', $deliveryNoGate['status']);
        $this->assertContains(
            'delivery_without_gate: v1.0 (delivery) requires at least one gate run.',
            $deliveryNoGate['blocking_reasons'],
        );

        // Delivery with a gate run passes.
        $deliveryGate = $svc->validateVersionProgression('v0.4', 'v1.0', true);
        $this->assertSame('pass', $deliveryGate['status']);

        // Backwards is rejected.
        $back = $svc->validateVersionProgression('v1.0', 'v0.4', true);
        $this->assertSame('fail', $back['status']);
    }

    /**
     * Production graph: source reaches output, and every consecutive hop of the
     * documented core chain source -> evidence -> claim -> section -> version ->
     * output is itself a valid documented edge. The directed graph also forbids
     * walking back from output to source.
     */
    public function test_production_graph_core_chain_is_reachable(): void
    {
        $svc = $this->service();

        // End-to-end reachability of the production chain.
        $endToEnd = $svc->traceGraphPath('source', 'output');
        $this->assertTrue($endToEnd['reachable']);
        $this->assertSame('pass', $endToEnd['status']);

        // Each documented hop of the canonical chain is a real one-step edge.
        $chain = ['source', 'evidence', 'claim', 'section', 'version', 'output'];
        for ($i = 0; $i < count($chain) - 1; $i++) {
            $hop = $svc->traceGraphPath($chain[$i], $chain[$i + 1]);
            $this->assertTrue(
                $hop['reachable'],
                "expected documented edge {$chain[$i]} -> {$chain[$i + 1]}",
            );
            $this->assertSame([$chain[$i], $chain[$i + 1]], $hop['path']);
        }

        // The graph is directed: "portfolio" is a documented terminal sink with
        // no outgoing edges, so nothing is reachable from it.
        $fromSink = $svc->traceGraphPath('portfolio', 'obra');
        $this->assertFalse($fromSink['reachable']);
        $this->assertSame('fail', $fromSink['status']);

        // An unknown node is rejected outright.
        $unknown = $svc->traceGraphPath('source', 'invoice');
        $this->assertFalse($unknown['reachable']);
        $this->assertContains(
            'graph_node_unknown: not a node in the documented production graph: invoice',
            $unknown['blocking_reasons'],
        );
    }

    /**
     * Storage ownership: Postgres must be the live state source and Markdown must
     * never be the sole runtime source of truth.
     */
    public function test_storage_ownership_postgres_live_markdown_never_sole(): void
    {
        $svc = $this->service();

        $ok = $svc->validateStorageOwnership([
            'live_state_source' => 'postgres',
            'markdown_is_sole_source' => false,
        ]);
        $this->assertSame('pass', $ok['status']);

        $markdownSole = $svc->validateStorageOwnership([
            'live_state_source' => 'markdown',
            'markdown_is_sole_source' => true,
        ]);
        $this->assertSame('fail', $markdownSole['status']);
        $this->assertContains(
            'storage_markdown_sole_source: Markdown is a projection/export and must never be the sole runtime source of truth.',
            $markdownSole['blocking_reasons'],
        );
    }

    /** The whole-model audit is green for the canonical reference model. */
    public function test_full_audit_is_green_for_canonical_model(): void
    {
        $report = $this->service()->audit([
            'entities' => AtlasDataModelAndProductionGraphService::CORE_ENTITIES,
            'storage' => [
                'live_state_source' => 'postgres',
                'markdown_is_sole_source' => false,
            ],
        ]);

        $this->assertSame('pass', $report['status']);
        $this->assertSame([], $report['blocking_reasons']);
    }
}
