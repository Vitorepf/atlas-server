<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiFlowVisualMapService;
use Tests\TestCase;

/**
 * Pins the canonical rules of the Atlas AI Flow Visual Map spec.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md
 */
class AtlasAiFlowVisualMapTest extends TestCase
{
    private function service(): AtlasAiFlowVisualMapService
    {
        return new AtlasAiFlowVisualMapService();
    }

    /**
     * Doc "Fluxo Canonico V3": the exact 18-stage order is valid, complete and
     * carries the stable receipt schema.
     */
    public function test_canonical_v3_order_is_valid(): void
    {
        $result = $this->service()->validateFlow(AtlasAiFlowVisualMapService::CANONICAL_FLOW);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['order_ok']);
        $this->assertSame(18, $result['stage_count']);
        $this->assertSame(18, $result['canonical_count']);
        $this->assertSame([], $result['violations']);
        $this->assertSame('atlas.aaeos.ai_flow_visual_map.v3', $result['schema']);
    }

    /**
     * Checklist #1 / Principio #7: putting Atlas Decide BEFORE Context Builder
     * and Policy (and therefore Runtime before its Decision Receipt is well
     * ordered) is a precedence violation, even though all stages are present.
     */
    public function test_decide_before_context_builder_and_policy_is_a_precedence_violation(): void
    {
        $order = AtlasAiFlowVisualMapService::CANONICAL_FLOW;
        // Move atlas-decide (index 10) to the very front.
        $order = array_values(array_filter($order, static fn ($s) => $s !== 'atlas-decide'));
        array_unshift($order, 'atlas-decide');

        $result = $this->service()->validateFlow($order);

        $this->assertFalse($result['valid']);
        $principles = array_column(
            array_filter($result['violations'], static fn ($v) => $v['rule'] === 'precedence_violation'),
            'principle',
        );
        $this->assertContains('decide_after_context_builder', $principles);
        $this->assertContains('decide_after_policy', $principles);
    }

    /**
     * Principio #7: "Runtime nao executa sem Decision Receipt." Runtime drawn
     * before Decision Receipt must fail with the runtime_after_decision_receipt
     * principle.
     */
    public function test_runtime_before_decision_receipt_fails(): void
    {
        // Swap decision-receipt (11) and runtime-executor (12).
        $order = AtlasAiFlowVisualMapService::CANONICAL_FLOW;
        [$order[11], $order[12]] = [$order[12], $order[11]];

        $result = $this->service()->validateFlow($order);

        $this->assertFalse($result['valid']);
        $principles = array_column(
            array_filter($result['violations'], static fn ($v) => $v['rule'] === 'precedence_violation'),
            'principle',
        );
        $this->assertContains('runtime_after_decision_receipt', $principles);
    }

    /**
     * Doc "Fluxo": a stage not in the canonical flow is flagged unknown, and a
     * dropped canonical stage is flagged missing.
     */
    public function test_unknown_and_missing_stages_are_flagged(): void
    {
        $order = AtlasAiFlowVisualMapService::CANONICAL_FLOW;
        // Replace output-renderer (last) with a non-canonical node.
        array_pop($order);
        $order[] = 'jenkins-deploy';

        $result = $this->service()->validateFlow($order);

        $this->assertFalse($result['valid']);
        $this->assertContains('output-renderer', $result['missing']);

        $unknown = array_column(
            array_filter($result['violations'], static fn ($v) => $v['rule'] === 'unknown_stage'),
            'stage',
        );
        $this->assertContains('jenkins-deploy', $unknown);
    }

    /**
     * Doc "Domain Plane" + Principio #5/#6: Blackink (business context), Python
     * (runtime) and Scenario Simulation (harness) must NOT be drawn as domains;
     * Programming and Finance may.
     */
    public function test_non_domain_nodes_are_rejected_from_domain_plane(): void
    {
        $service = $this->service();

        $this->assertTrue($service->classifyNode('Programming')['may_be_domain']);
        $this->assertSame('ready', $service->classifyNode('Finance')['maturity']);
        $this->assertSame('scaffold', $service->classifyNode('Marketing')['maturity']);

        $this->assertFalse($service->classifyNode('Blackink')['may_be_domain']);
        $this->assertSame('business-context', $service->classifyNode('Blackink')['kind']);
        $this->assertSame('runtime', $service->classifyNode('Python')['kind']);
        $this->assertSame('harness', $service->classifyNode('Scenario Simulation')['kind']);
    }

    /**
     * Doc "auditDomainPlane": a proposed Domain Plane mixing real domains with
     * Blackink and AtlasVault reports exactly those two as misplaced and keeps
     * the real domains in the right maturity buckets.
     */
    public function test_audit_domain_plane_reports_misplaced_nodes(): void
    {
        $audit = $this->service()->auditDomainPlane([
            'Programming',
            'Blackink',
            'Finance',
            'AtlasVault',
            'Marketing',
        ]);

        $this->assertFalse($audit['valid']);
        $this->assertSame(['programming', 'finance'], $audit['ready']);
        $this->assertSame(['marketing'], $audit['scaffold']);

        $misplaced = array_column($audit['misplaced'], 'node');
        $this->assertSame(['blackink', 'atlasvault'], $misplaced);
    }
}
