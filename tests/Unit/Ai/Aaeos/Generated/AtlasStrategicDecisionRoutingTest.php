<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasStrategicDecisionRoutingService;
use Tests\TestCase;

/**
 * Pins the documented Strategic Decision routing rules.
 *
 * @see docs/engineering-knowledge-base/domains/strategic_decision.md
 */
class AtlasStrategicDecisionRoutingTest extends TestCase
{
    private function service(): AtlasStrategicDecisionRoutingService
    {
        return new AtlasStrategicDecisionRoutingService();
    }

    /**
     * A request whose upstream "Fluxo" stages all hold (Human Intent ->
     * Product Truth -> Runtime Gate), with evidence and executable scope.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function readyRequest(array $overrides = []): array
    {
        return array_merge([
            'title' => 'sample',
            'scope' => 'dev',
            'risk' => 'medium',
            'human_intent_model' => true,
            'product_truth_contract' => true,
            'runtime_gate' => true,
            'has_evidence' => true,
            'is_executable' => true,
            'has_work_packet' => true,
            'operator_approval' => true,
        ], $overrides);
    }

    /**
     * Doc example: "Bug curto em login -> Atlas Dev com runtime gate."
     * A dev-sized request routes to programming.dev and still owes the gate,
     * and the Dev-vs-Forge choice is explicit.
     */
    public function test_short_dev_sized_request_routes_to_dev(): void
    {
        $r = $this->service()->route($this->readyRequest(['scope' => 'dev']));

        $this->assertSame(AtlasStrategicDecisionRoutingService::ROUTE_DEV, $r['route']);
        $this->assertTrue($r['explicit_dev_vs_forge']);
        $this->assertTrue($r['runtime_gate_required']);
        $this->assertFalse($r['requires_work_packet']);
    }

    /**
     * Doc example: "Ecommerce completo -> Forge com workcell, proof e aprovação."
     * A forge-sized request with work packet + approval routes to Forge and
     * carries both prerequisites as required.
     */
    public function test_forge_sized_request_with_prereqs_routes_to_forge(): void
    {
        $r = $this->service()->route($this->readyRequest([
            'scope' => 'forge',
            'has_work_packet' => true,
            'operator_approval' => true,
        ]));

        $this->assertSame(AtlasStrategicDecisionRoutingService::ROUTE_FORGE, $r['route']);
        $this->assertTrue($r['requires_work_packet']);
        $this->assertTrue($r['requires_human_approval']);
    }

    /**
     * Doc risk: "Forge executando sem work packet, gate ou aprovação."
     * Forge scope without a work packet is blocked, never routed to execution,
     * and names the missing prerequisite.
     */
    public function test_forge_without_work_packet_is_blocked(): void
    {
        $r = $this->service()->route($this->readyRequest([
            'scope' => 'forge',
            'has_work_packet' => false,
            'operator_approval' => true,
        ]));

        $this->assertSame(AtlasStrategicDecisionRoutingService::ROUTE_BLOCKED, $r['route']);
        $this->assertContains('work_packet', $r['missing_prerequisites']);
        $this->assertNotContains('operator_approval', $r['missing_prerequisites']);
        $this->assertTrue($r['explicit_dev_vs_forge']);
    }

    /**
     * Doc "Regras para IA": "Não executa decisão crítica sem evidência."
     * High/critical risk with no evidence is blocked and reports the missing
     * evidence rather than routing to Dev or Forge.
     */
    public function test_high_risk_without_evidence_is_blocked(): void
    {
        $r = $this->service()->route($this->readyRequest([
            'scope' => 'dev',
            'risk' => 'high',
            'has_evidence' => false,
        ]));

        $this->assertSame(AtlasStrategicDecisionRoutingService::ROUTE_BLOCKED, $r['route']);
        $this->assertContains('decision_evidence', $r['missing_evidence']);
        $this->assertContains('no_evidence_for_high_risk', $r['reasons']);
    }

    /**
     * Doc "Fluxo": the route is the LAST stage. If an upstream stage (here the
     * runtime gate) is not satisfied, nothing routes to execution.
     * Forbidden change: "Executar provider antes de runtime gate."
     */
    public function test_incomplete_runtime_gate_blocks_routing(): void
    {
        $r = $this->service()->route($this->readyRequest([
            'scope' => 'dev',
            'runtime_gate' => false,
        ]));

        $this->assertSame(AtlasStrategicDecisionRoutingService::ROUTE_BLOCKED, $r['route']);
        $this->assertContains('runtime_gate', $r['missing_prerequisites']);
        $this->assertContains('flow_prerequisite_missing', $r['reasons']);
    }

    /**
     * Doc capability set is about routing/risk, not only execution. A
     * non-executable decision (pure tradeoff) routes to the plan-only review
     * flow, with no side effects.
     */
    public function test_non_executable_decision_routes_to_review(): void
    {
        $r = $this->service()->route($this->readyRequest([
            'scope' => 'review',
            'is_executable' => false,
        ]));

        $this->assertSame(AtlasStrategicDecisionRoutingService::ROUTE_REVIEW, $r['route']);
        $this->assertTrue($r['no_external_side_effects']);
    }
}
