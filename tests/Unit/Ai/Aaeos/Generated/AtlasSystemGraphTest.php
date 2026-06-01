<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphService;
use Tests\TestCase;

/**
 * Pins the documented Atlas System Graph (L0) taxonomy and Definition-of-Done
 * rules. Pure; no database.
 *
 * @see docs/engineering-knowledge-base/atlas-system-graph.md
 */
class AtlasSystemGraphTest extends TestCase
{
    private function service(): AtlasSystemGraphService
    {
        return new AtlasSystemGraphService();
    }

    /**
     * R1/R2/R3 — the closed vocabularies match the doc tables exactly: 9 node
     * types, 10 relations, 8 statuses, and 12 canonical top-level systems.
     */
    public function test_closed_vocabularies_match_the_doc(): void
    {
        $this->assertCount(9, AtlasSystemGraphService::NODE_TYPES);
        $this->assertCount(10, AtlasSystemGraphService::RELATION_TYPES);
        $this->assertCount(8, AtlasSystemGraphService::STATUS_SEMANTICS);
        $this->assertCount(12, AtlasSystemGraphService::CANONICAL_TOP_SYSTEMS);

        // Spot-check membership of types/relations the doc explicitly lists.
        $this->assertContains('submodule', AtlasSystemGraphService::NODE_TYPES);
        $this->assertContains('spin_off', AtlasSystemGraphService::RELATION_TYPES);

        // A token outside the closed set is rejected, not coerced.
        $this->assertFalse($this->service()->classifyNodeType('component')['valid']);
        $this->assertFalse($this->service()->classifyRelation('related_to')['valid']);
        $this->assertTrue($this->service()->classifyNodeType('Decision')['valid']);
    }

    /**
     * R3 — "Status And Color Semantics": each status maps to the doc's tag and
     * color bucket. blocked is red, future is purple, active/implemented green.
     * An unknown status yields no color (cannot be invented).
     */
    public function test_status_semantics_map_to_documented_tag_and_color(): void
    {
        $blocked = $this->service()->statusSemantics('blocked');
        $this->assertTrue($blocked['valid']);
        $this->assertSame('#status/blocked', $blocked['tag']);
        $this->assertSame('red', $blocked['color']);

        $this->assertSame('purple', $this->service()->statusSemantics('future')['color']);
        $this->assertSame('green', $this->service()->statusSemantics('implemented')['color']);
        $this->assertSame('yellow', $this->service()->statusSemantics('planned')['color']);

        $unknown = $this->service()->statusSemantics('done');
        $this->assertFalse($unknown['valid']);
        $this->assertNull($unknown['color']);
    }

    /**
     * R4 — "Maturity Levels": the L0..L4 ladder may not skip rungs. Claiming L2
     * while L1 is unmet is inadmissible and names the missing prerequisite;
     * claiming L2 with L0+L1 met is admissible.
     */
    public function test_maturity_ladder_may_not_skip_rungs(): void
    {
        $skip = $this->service()->evaluateMaturityClaim('L2', ['L0']);
        $this->assertFalse($skip['admissible']);
        $this->assertSame(['L1'], $skip['missing_prerequisites']);

        $ok = $this->service()->evaluateMaturityClaim('L2', ['L0', 'L1']);
        $this->assertTrue($ok['admissible']);
        $this->assertSame([], $ok['missing_prerequisites']);

        // L0 has no prerequisites and is always admissible.
        $this->assertTrue($this->service()->evaluateMaturityClaim('L0', [])['admissible']);
    }

    /**
     * R5 — "Authority": repo docs are canonical. A Vault node may not override a
     * repo doc; chat may not override the Vault; but repo may override the
     * lower tiers.
     */
    public function test_authority_ordering_protects_repo_canon(): void
    {
        $this->assertFalse($this->service()->canOverride('vault', 'repo')['override_allowed']);
        $this->assertFalse($this->service()->canOverride('chat', 'vault')['override_allowed']);
        $this->assertTrue($this->service()->canOverride('repo', 'vault')['override_allowed']);
        // Equal tiers do not override each other.
        $this->assertFalse($this->service()->canOverride('repo', 'repo')['override_allowed']);
    }

    /**
     * R7 — a speculative node may not claim a done-like status, and a Vault node
     * must link back to a canonical repo doc; a node violating more than one
     * gives one blocking reason per violation.
     */
    public function test_node_validation_enforces_speculative_and_vault_backlink_rules(): void
    {
        $bad = $this->service()->validateNode([
            'name' => 'Atlas Foundry',
            'type' => 'program',
            'status' => 'implemented', // done-like ...
            'parents' => ['Atlas Obras System'],
            'speculative' => true,      // ... but speculative -> violation
            'is_vault_node' => true,
            'canonical_doc' => '',      // missing repo backlink -> violation
        ]);
        $this->assertFalse($bad['valid']);
        $this->assertContains(
            'speculative node may not claim done-like status "implemented"; use future or planned',
            $bad['blocking_reasons'],
        );
        $this->assertContains(
            'Vault node has no canonical repo doc backlink; Vault must link back to repo docs',
            $bad['blocking_reasons'],
        );

        $good = $this->service()->validateNode([
            'name' => 'Atlas Foundry',
            'type' => 'program',
            'status' => 'future',
            'parents' => ['Atlas Obras System'],
            'speculative' => true,
            'is_vault_node' => true,
            'canonical_doc' => 'docs/engineering-knowledge-base/atlas-system-graph.md',
        ]);
        $this->assertTrue($good['valid']);
        $this->assertSame(AtlasSystemGraphService::STATUS_PASS, $good['verdict']);
    }

    /**
     * R2 — `parent` is single-valued: a node with two parents is rejected.
     */
    public function test_parent_relation_is_single_valued(): void
    {
        $r = $this->service()->validateNode([
            'name' => 'Work Splitter',
            'type' => 'module',
            'status' => 'planned',
            'parents' => ['Atlas Self-Construction OS', 'Atlas Programming / Forge System'],
        ]);
        $this->assertFalse($r['valid']);
        $this->assertContains(
            'node declares more than one parent; `parent` is single-valued',
            $r['blocking_reasons'],
        );
    }

    /**
     * R6 — "Definition Of Done": a graph missing any of the 12 top-level systems
     * is not Done and reports exactly which are missing; the full reference set
     * with the documented Vault paths passes.
     */
    public function test_definition_of_done_requires_all_top_level_systems(): void
    {
        // Build the full conformant graph, then drop one system to prove the gate.
        $node = static fn (string $name): array => [
            'name' => $name,
            'type' => 'system',
            'status' => 'active',
            'parents' => ['Atlas'],
            'speculative' => false,
            'is_vault_node' => true,
            'canonical_doc' => 'docs/engineering-knowledge-base/atlas-system-graph.md',
        ];

        $allSystems = AtlasSystemGraphService::CANONICAL_TOP_SYSTEMS;
        $full = array_map($node, $allSystems);

        $pass = $this->service()->checkDefinitionOfDone([
            'nodes' => $full,
            'vault_root_note' => AtlasSystemGraphService::VAULT_ROOT_NOTE,
            'vault_module_template' => AtlasSystemGraphService::VAULT_MODULE_TEMPLATE,
        ]);
        $this->assertSame(AtlasSystemGraphService::STATUS_PASS, $pass['status']);
        $this->assertSame([], $pass['missing_top_systems']);
        $this->assertTrue($pass['vault_paths_ok']);

        // Drop the Evidence System node.
        $partial = array_values(array_filter(
            $full,
            static fn (array $n): bool => $n['name'] !== 'Atlas Evidence System',
        ));
        $fail = $this->service()->checkDefinitionOfDone([
            'nodes' => $partial,
            'vault_root_note' => AtlasSystemGraphService::VAULT_ROOT_NOTE,
            'vault_module_template' => AtlasSystemGraphService::VAULT_MODULE_TEMPLATE,
        ]);
        $this->assertSame(AtlasSystemGraphService::STATUS_FAIL, $fail['status']);
        $this->assertSame(['Atlas Evidence System'], $fail['missing_top_systems']);
    }
}
