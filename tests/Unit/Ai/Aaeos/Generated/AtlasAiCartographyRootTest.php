<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiCartographyRootService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI cartography-root parent-resolution rules.
 *
 * @see docs/engineering-knowledge-base/atlas-ai.md
 */
class AtlasAiCartographyRootTest extends TestCase
{
    private function service(): AtlasAiCartographyRootService
    {
        return new AtlasAiCartographyRootService();
    }

    /**
     * Doc "Exemplos": mission-mode and the autonomous-intelligence-os parented
     * to atlas-ai under the world root resolve cleanly => zero orphans, gate
     * passes (cartography-orphan-count-zero).
     */
    public function test_healthy_subuniverse_has_zero_orphans_and_gate_passes(): void
    {
        $audit = $this->service()->audit([
            ['id' => 'atlas', 'parent' => null, 'status' => 'active'],
            ['id' => 'atlas-ai', 'parent' => 'atlas', 'status' => 'active'],
            ['id' => 'atlas-mission-mode', 'parent' => 'atlas-ai', 'status' => 'active'],
            ['id' => 'atlas-autonomous-intelligence-operating-system', 'parent' => 'atlas-ai', 'status' => 'active'],
        ]);

        $this->assertSame(0, $audit['orphan_count']);
        $this->assertTrue($audit['gate_pass']);
        $this->assertTrue($audit['root_present']);
        $this->assertSame('cartography-orphan-count-zero', $audit['gate']);
    }

    /**
     * Doc "Regra": "Se este node sumir, o grafo volta a ficar orfao." Remove
     * the atlas-ai root and every child parented to it orphans; gate fails.
     */
    public function test_missing_root_orphans_its_children_and_fails_gate(): void
    {
        $audit = $this->service()->audit([
            ['id' => 'atlas', 'parent' => null, 'status' => 'active'],
            // atlas-ai root deliberately absent.
            ['id' => 'atlas-mission-mode', 'parent' => 'atlas-ai', 'status' => 'active'],
            ['id' => 'atlas-autonomous-intelligence-operating-system', 'parent' => 'atlas-ai', 'status' => 'active'],
        ]);

        $this->assertFalse($audit['root_present']);
        $this->assertSame(2, $audit['orphan_count']);
        $this->assertFalse($audit['gate_pass']);

        $reasons = array_column($audit['orphans'], 'reason');
        $this->assertSame(['atlas_ai_root_missing', 'atlas_ai_root_missing'], $reasons);
    }

    /**
     * Doc "Contratos" R1: an active child whose graph_parent does not resolve to
     * any known node is an orphan (parent_unresolved).
     */
    public function test_unresolved_parent_is_orphan(): void
    {
        $audit = $this->service()->audit([
            ['id' => 'atlas', 'parent' => null, 'status' => 'active'],
            ['id' => 'atlas-ai', 'parent' => 'atlas', 'status' => 'active'],
            ['id' => 'some-ai-surface', 'parent' => 'ghost-parent', 'status' => 'active'],
        ]);

        $this->assertSame(1, $audit['orphan_count']);
        $this->assertFalse($audit['gate_pass']);
        $this->assertSame('some-ai-surface', $audit['orphans'][0]['id']);
        $this->assertSame('parent_unresolved', $audit['orphans'][0]['reason']);
    }

    /**
     * Doc forbidden_changes / "Contratos": "Atlas AI nao representa o Atlas
     * inteiro." A node claiming the world root id while parented to atlas-ai is
     * a root-identity violation and fails the gate even with zero orphans.
     */
    public function test_world_root_claiming_atlas_ai_parent_is_identity_violation(): void
    {
        $audit = $this->service()->audit([
            ['id' => 'atlas-ai', 'parent' => 'atlas', 'status' => 'active'],
            // 'atlas' (world root) wrongly declares atlas-ai as its parent.
            ['id' => 'atlas', 'parent' => 'atlas-ai', 'status' => 'active'],
        ]);

        $this->assertFalse($audit['root_identity_ok']);
        $this->assertFalse($audit['gate_pass']);
        $this->assertSame('atlas', $audit['root_identity_violations'][0]['id']);
        $this->assertSame(
            'world_root_claims_atlas_ai_as_parent',
            $audit['root_identity_violations'][0]['reason'],
        );
    }

    /**
     * Doc scopes the contract to "documento ativo": an inactive (status !=
     * active) child with a dangling parent is NOT counted as an orphan.
     */
    public function test_inactive_child_with_dangling_parent_is_not_orphan(): void
    {
        $audit = $this->service()->audit([
            ['id' => 'atlas-ai', 'parent' => 'atlas', 'status' => 'active'],
            ['id' => 'retired-surface', 'parent' => 'ghost-parent', 'status' => 'archived'],
        ]);

        $this->assertSame(0, $audit['orphan_count']);
        $this->assertTrue($audit['gate_pass']);
    }

    /** R4: a node cannot be its own parent — self_parent orphan. */
    public function test_self_parent_is_orphan(): void
    {
        $audit = $this->service()->audit([
            ['id' => 'atlas-ai', 'parent' => 'atlas', 'status' => 'active'],
            ['id' => 'loopy-node', 'parent' => 'loopy-node', 'status' => 'active'],
        ]);

        $this->assertSame(1, $audit['orphan_count']);
        $this->assertSame('self_parent', $audit['orphans'][0]['reason']);
        $this->assertFalse($audit['gate_pass']);
    }

    /** Every audit is auditable and carries the stable receipt schema. */
    public function test_audit_is_auditable_with_stable_schema(): void
    {
        $audit = $this->service()->audit([
            ['id' => 'atlas-ai', 'parent' => 'atlas', 'status' => 'active'],
        ]);

        $this->assertSame('atlas.aaeos.cartography_root.atlas_ai.v1', $audit['schema']);
        $this->assertTrue($audit['auditable']);
    }
}
