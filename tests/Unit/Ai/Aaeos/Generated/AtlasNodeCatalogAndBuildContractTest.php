<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasNodeCatalogAndBuildContractService;
use Tests\TestCase;

/**
 * Pins the documented Node Catalog And Build Contract rules: the named catalog
 * (expected parent/status per node), the Self-Programming `future` law, the
 * build-order gating, the required-frontmatter + next-action obligation, and the
 * Core Dependencies edge set. Pure; no database.
 *
 * @see docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md
 */
class AtlasNodeCatalogAndBuildContractTest extends TestCase
{
    private function service(): AtlasNodeCatalogAndBuildContractService
    {
        return new AtlasNodeCatalogAndBuildContractService();
    }

    /**
     * R1: a catalog node declaring the wrong parent or wrong status drifts; the
     * same node declaring exactly the catalog's parent+status is conformant. The
     * doc's "Evolution Nodes" table fixes Atlas Self-Construction OS to parent
     * "Atlas Evolution System", status "building".
     */
    public function test_catalog_node_parent_and_status_must_match_the_doc(): void
    {
        $svc = $this->service();

        // Wrong status (doc says building, not active) AND wrong parent.
        $drifted = $svc->classifyAgainstCatalog([
            'name' => 'Atlas Self-Construction OS',
            'parent' => 'Atlas',
            'status' => 'active',
        ]);
        $this->assertFalse($drifted['conformant']);
        $this->assertSame('Atlas Evolution System', $drifted['expected_parent']);
        $this->assertSame('building', $drifted['expected_status']);
        $this->assertCount(2, $drifted['drift']); // parent drift + status drift

        // Exactly the catalog values -> conformant.
        $ok = $svc->classifyAgainstCatalog([
            'name' => 'Atlas Self-Construction OS',
            'parent' => 'Atlas Evolution System',
            'status' => 'building',
        ]);
        $this->assertTrue($ok['conformant']);
        $this->assertSame(AtlasNodeCatalogAndBuildContractService::STATUS_PASS, $ok['verdict']);
    }

    /**
     * "AI Instructions": "use `future` for Self-Programming nodes not started."
     * A Self-Programming node claimed as anything other than `future` drifts,
     * even though it otherwise matches its catalog parent.
     */
    public function test_self_programming_node_must_be_future(): void
    {
        $svc = $this->service();

        $building = $svc->classifyAgainstCatalog([
            'name' => 'Architecture Mutation Engine',
            'parent' => 'Atlas Self-Programming OS',
            'status' => 'building', // doc forbids: must be future
        ]);
        $this->assertFalse($building['conformant']);
        $this->assertNotEmpty(array_filter(
            $building['drift'],
            static fn (string $d): bool => str_contains($d, 'must be status "future"'),
        ));

        $future = $svc->classifyAgainstCatalog([
            'name' => 'Architecture Mutation Engine',
            'parent' => 'Atlas Self-Programming OS',
            'status' => 'future',
        ]);
        $this->assertTrue($future['conformant']);

        // All 5 named Self-Programming nodes are catalogued as `future`.
        $this->assertCount(6, AtlasNodeCatalogAndBuildContractService::SELF_PROGRAMMING_NODES);
        $this->assertContains('Autonomous Repair Loop', AtlasNodeCatalogAndBuildContractService::SELF_PROGRAMMING_NODES);
    }

    /**
     * "use `planned` when uncertain": an unknown/empty status coerces to planned,
     * is flagged coerced, is non-terminal, and so still owes a next action. A
     * real status passes through untouched.
     */
    public function test_uncertain_status_coerces_to_planned(): void
    {
        $svc = $this->service();

        $unknown = $svc->resolveStatus('totally-made-up');
        $this->assertSame('planned', $unknown['resolved_status']);
        $this->assertTrue($unknown['coerced']);
        $this->assertFalse($unknown['terminal']);
        $this->assertTrue($unknown['requires_next_action']);

        $empty = $svc->resolveStatus('');
        $this->assertSame('planned', $empty['resolved_status']);
        $this->assertTrue($empty['coerced']);

        $real = $svc->resolveStatus('Building');
        $this->assertSame('building', $real['resolved_status']);
        $this->assertFalse($real['coerced']);
    }

    /**
     * "Build Order" + decision "Top-level systems and active evolution modules
     * must be created before deep leaf nodes." A leaf cannot be built while the
     * top-level-systems step is still incomplete; once all prerequisite steps are
     * done, it is allowed. The blocking step is named.
     */
    public function test_build_order_gates_leaves_behind_systems(): void
    {
        $svc = $this->service();

        // Only the folder + atlas top node done -> a leaf is blocked, and the
        // first missing prerequisite is create_top_level_systems.
        $blocked = $svc->checkBuildOrder('leaf', [
            'create_graph_folder',
            'create_top_node_atlas',
        ]);
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('create_top_level_systems', $blocked['blocking_step']);

        // Top node itself only needs the folder + its own step.
        $topNode = $svc->checkBuildOrder('top_node', [
            'create_graph_folder',
            'create_top_node_atlas',
        ]);
        $this->assertTrue($topNode['allowed']);
        $this->assertNull($topNode['blocking_step']);

        // Once every prerequisite step is complete, the leaf is allowed.
        $allowed = $svc->checkBuildOrder('leaf', [
            'create_graph_folder',
            'create_top_node_atlas',
            'create_top_level_systems',
            'create_active_evolution',
            'create_kernel_pipeline',
            'create_obras_levels',
            'create_support_systems',
        ]);
        $this->assertTrue($allowed['allowed']);
    }

    /**
     * "Required Frontmatter" + Validation Checklist: a node missing a mandatory
     * key (graph_id/type/status/parent/canonical_doc) fails and names it; and a
     * non-terminal node with empty next_actions fails, while a terminal
     * (implemented/archive) node with empty next_actions passes.
     */
    public function test_frontmatter_and_next_action_obligation(): void
    {
        $svc = $this->service();

        $base = [
            'frontmatter' => [
                'graph_id' => 'atlas-decide',
                'type' => 'module',
                'status' => 'active',
                'parent' => 'Atlas AI Kernel System',
                'canonical_doc' => 'docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md',
            ],
            'next_actions' => ['Manter sincronizado.'],
        ];

        // Drop a mandatory key -> invalid, key named.
        $missing = $base;
        unset($missing['frontmatter']['canonical_doc']);
        $r = $svc->validateNodeFrontmatter($missing);
        $this->assertFalse($r['valid']);
        $this->assertSame(['canonical_doc'], $r['missing_frontmatter']);

        // Non-terminal (active) with empty next_actions -> invalid.
        $noAction = $base;
        $noAction['next_actions'] = [];
        $r2 = $svc->validateNodeFrontmatter($noAction);
        $this->assertFalse($r2['valid']);
        $this->assertTrue($r2['needs_next_action']);

        // Terminal (implemented) with empty next_actions -> valid.
        $terminal = $base;
        $terminal['frontmatter']['status'] = 'implemented';
        $terminal['next_actions'] = [];
        $r3 = $svc->validateNodeFrontmatter($terminal);
        $this->assertTrue($r3['valid']);
        $this->assertTrue($r3['terminal']);
    }

    /**
     * "Core Dependencies": an edge is accepted only if it matches the documented
     * {from, relation, to}. A correct edge passes; the same pair with the wrong
     * relation is a relation conflict; an unknown pair is rejected.
     */
    public function test_core_dependencies_edge_set_is_enforced(): void
    {
        $svc = $this->service();

        $good = $svc->validateEdge([
            'from' => 'Atlas Agent Control Plane',
            'relation' => 'depends_on',
            'to' => 'AI Implementation Packet',
        ]);
        $this->assertTrue($good['recognized']);

        // Documented as depends_on, not "unlocks" -> relation conflict.
        $conflict = $svc->validateEdge([
            'from' => 'Atlas Agent Control Plane',
            'relation' => 'unlocks',
            'to' => 'AI Implementation Packet',
        ]);
        $this->assertFalse($conflict['recognized']);
        $this->assertSame('depends_on', $conflict['relation_conflict']);

        // Pair not in the table -> rejected.
        $unknown = $svc->validateEdge([
            'from' => 'Atlas',
            'relation' => 'depends_on',
            'to' => 'Scope Validator',
        ]);
        $this->assertFalse($unknown['recognized']);
        $this->assertNull($unknown['relation_conflict']);
    }

    /**
     * End-to-end: a fully conformant build packet passes; flipping one node's
     * status to violate the Self-Programming rule flips the whole audit to fail
     * and increments the drift count.
     */
    public function test_audit_build_packet_aggregates_pass_and_fail(): void
    {
        $svc = $this->service();

        $node = static function (string $name, string $parent, string $status): array {
            return [
                'name' => $name,
                'parent' => $parent,
                'status' => $status,
                'frontmatter' => [
                    'graph_id' => str_replace(' ', '-', strtolower($name)),
                    'type' => 'system',
                    'status' => $status,
                    'parent' => $parent,
                    'canonical_doc' => 'docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md',
                ],
                'next_actions' => ['Manter sincronizado.'],
            ];
        };

        $clean = $svc->auditBuildPacket([
            'nodes' => [
                $node('Atlas', 'none', 'active'),
                $node('Atlas Self-Programming OS', 'Atlas Evolution System', 'future'),
            ],
            'edges' => [
                ['from' => 'Atlas Self-Programming OS', 'relation' => 'depends_on', 'to' => 'Atlas Self-Construction OS'],
            ],
        ]);
        $this->assertSame(AtlasNodeCatalogAndBuildContractService::STATUS_PASS, $clean['status']);
        $this->assertSame(0, $clean['counts']['catalog_drift']);

        $dirty = $svc->auditBuildPacket([
            'nodes' => [
                $node('Atlas', 'none', 'active'),
                // Self-Programming node claimed active -> drift.
                $node('Atlas Self-Programming OS', 'Atlas Evolution System', 'active'),
            ],
        ]);
        $this->assertSame(AtlasNodeCatalogAndBuildContractService::STATUS_FAIL, $dirty['status']);
        $this->assertGreaterThanOrEqual(1, $dirty['counts']['catalog_drift']);
    }
}
