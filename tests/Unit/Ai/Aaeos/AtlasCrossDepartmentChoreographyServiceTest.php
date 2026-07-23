<?php

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCrossDepartmentChoreographyService;
use Tests\TestCase;

class AtlasCrossDepartmentChoreographyServiceTest extends TestCase
{
    public function test_security_veto_pauses_downstream_with_sla(): void
    {
        $result = $this->service()->evaluateVeto('security');

        $this->assertTrue($result['recognized']);
        $this->assertSame('pause_downstream', $result['action']);
        $this->assertSame(['dev', 'forge', 'delivery'], $result['paused_departments']);
        $this->assertFalse($result['final_override']);
        $this->assertSame(10, $result['pause_sla_seconds']);
    }

    public function test_operator_veto_is_final_override_requiring_receipt(): void
    {
        $result = $this->service()->evaluateVeto('operator');

        $this->assertTrue($result['final_override']);
        $this->assertSame('override', $result['action']);
        $this->assertTrue($result['requires_operator_receipt']);
    }

    public function test_architect_and_review_vetos_return_upstream(): void
    {
        $architect = $this->service()->evaluateVeto('architect');
        $this->assertSame('return_upstream', $architect['action']);
        $this->assertSame('product', $architect['return_to']);

        $review = $this->service()->evaluateVeto('REVIEW');
        $this->assertSame('return_upstream', $review['action']);
        $this->assertSame('dev_or_forge', $review['return_to']);
    }

    public function test_unknown_vetoer_is_not_recognized(): void
    {
        $result = $this->service()->evaluateVeto('marketing');

        $this->assertFalse($result['recognized']);
        $this->assertSame('noop', $result['action']);
    }

    public function test_repair_loop_repairs_up_to_max_then_escalates(): void
    {
        $service = $this->service();

        $third = $service->evaluateRepairLoop(3);
        $this->assertSame('repair', $third['decision']);
        $this->assertFalse($third['escalate']);
        $this->assertSame(0, $third['remaining_repairs']);

        $fourth = $service->evaluateRepairLoop(4);
        $this->assertSame('escalate', $fourth['decision']);
        $this->assertTrue($fourth['escalate']);
        $this->assertSame(['architect', 'operator'], $fourth['escalate_to']);
    }

    public function test_handoff_envelope_flags_invalid_kind(): void
    {
        $service = $this->service();

        $valid = $service->handoffEnvelope('dev', 'review', 'review_request');
        $this->assertTrue($valid['valid_kind']);
        $this->assertSame('review_request', $valid['kind']);

        $invalid = $service->handoffEnvelope('dev', 'review', 'gossip');
        $this->assertFalse($invalid['valid_kind']);
        $this->assertSame('delegation', $invalid['kind']);
    }

    private function service(): AtlasCrossDepartmentChoreographyService
    {
        return new AtlasCrossDepartmentChoreographyService;
    }
}
