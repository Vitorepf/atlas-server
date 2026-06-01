<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasBuildGraphService;
use Tests\TestCase;

/**
 * Pins the five load-bearing rules from the Build Graph doc: the L5 Blocking
 * Rule, the Core Dependencies order, the Build Order Law fan-out, the 7-key
 * Build Graph Packet, and the Drift Signal. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/build-graph.md
 */
class AtlasBuildGraphTest extends TestCase
{
    private function service(): AtlasBuildGraphService
    {
        return new AtlasBuildGraphService;
    }

    /**
     * Blocking Rule: "Self-programming cannot exceed L5 while SDD runtime,
     * Evidence Ledger, rollback and drift detection are below L5." A request for
     * L7 with all prerequisites at L0 must be capped to exactly L5.
     */
    public function test_blocking_rule_caps_self_programming_at_l5_when_prerequisites_below_floor(): void
    {
        $result = $this->service()->applyBlockingRule(7, [
            'sdd_runtime' => 4,
            'evidence_ledger' => 5,
            'rollback' => 2,
            'drift_detection' => 0,
        ]);

        $this->assertTrue($result['capped']);
        $this->assertSame(5, $result['effective_level']);
        $this->assertFalse($result['prerequisites_met']);
        // Only the three below L5 are reported; evidence_ledger (L5) is not.
        $this->assertSame(['sdd_runtime', 'rollback', 'drift_detection'], $result['below_floor']);
    }

    /**
     * Once ALL four prerequisites reach L5, self-programming may exceed L5 — no cap.
     */
    public function test_blocking_rule_releases_when_all_four_prerequisites_reach_l5(): void
    {
        $result = $this->service()->applyBlockingRule(7, [
            'sdd_runtime' => 5,
            'evidence_ledger' => 5,
            'rollback' => 6,
            'drift_detection' => 5,
        ]);

        $this->assertFalse($result['capped']);
        $this->assertSame(7, $result['effective_level']);
        $this->assertTrue($result['prerequisites_met']);
        $this->assertSame([], $result['below_floor']);
    }

    /**
     * Core Dependencies order: a downstream stage cannot be promoted while an
     * upstream stage in the 11-link chain is below the required maturity.
     */
    public function test_core_chain_blocks_downstream_stage_when_upstream_is_immature(): void
    {
        // Promote "spec_operating_system" (index 6) while "evidence_ledger"
        // (index 2) sits at L1 — must be blocked by that upstream stage.
        $result = $this->service()->canPromoteStage('Spec Operating System', [
            'documentation_os' => 5,
            'knowledge_governance' => 5,
            'evidence_ledger' => 1,
            'code_intelligence' => 5,
            'cognitive_runtime' => 5,
            'research_self_improvement_runtime' => 5,
        ]);

        $this->assertTrue($result['recognized_stage']);
        $this->assertSame(6, $result['stage_index']);
        $this->assertFalse($result['promotable']);
        $this->assertContains('evidence_ledger', $result['blocked_by']);
        // Stages not yet at floor that are upstream are all named.
        $this->assertContains('tool_runtime_quality_gates', $this->service()::CORE_CHAIN);
    }

    /**
     * Build Order Law: when work competes, the block improving the most downstream
     * capabilities wins. Memory (fan-out 4) beats decorative UI (fan-out 0).
     */
    public function test_build_order_law_prefers_highest_downstream_fanout(): void
    {
        $result = $this->service()->rankByBuildOrderLaw([
            'decorative_ui',
            'memory',
            'self_programming',
        ]);

        $this->assertSame('memory', $result['winner']);
        $this->assertSame(4, $result['winner_fanout']);
        // decorative_ui must rank last (fan-out 0).
        $this->assertSame('decorative_ui', $result['ranked'][2]['capability']);
    }

    /**
     * Build Graph Packet: every spec must declare all 7 keys; an empty packet is
     * invalid and reports all 7 as missing.
     */
    public function test_packet_requires_all_seven_keys(): void
    {
        $empty = $this->service()->validatePacket([]);
        $this->assertFalse($empty['valid']);
        $this->assertCount(7, $empty['missing_keys']);

        $full = $this->service()->validatePacket([
            'target_capability' => 'self_programming',
            'prerequisites' => ['sdd_runtime'],
            'downstream_capabilities' => ['none'],
            'blocked_by' => ['rollback'],
            'unlocks' => ['autonomy'],
            'maturity_before' => 4,
            'maturity_after' => 5,
        ]);
        $this->assertTrue($full['valid']);
        $this->assertSame([], $full['missing_keys']);
    }

    /**
     * Drift Signal: an off-graph capability emits the exact documented line and
     * offers only the two accepted corrections; a chain-anchored one does not.
     */
    public function test_drift_signal_fires_for_off_graph_capability_only(): void
    {
        $drifting = $this->service()->detectDrift('decorative_dashboard_widget', []);
        $this->assertTrue($drifting['drift']);
        $this->assertSame('Capability exists without canonical dependency placement.', $drifting['drift_signal']);
        $this->assertSame(['attach', 'remove_or_defer'], $drifting['accepted_corrections']);

        // A capability that cites a chain-anchored prerequisite is placed.
        $placed = $this->service()->detectDrift('new_surface', ['Cognitive Runtime']);
        $this->assertFalse($placed['drift']);
        $this->assertSame('', $placed['drift_signal']);
    }
}
