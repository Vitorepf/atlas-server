<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Generated;

use App\Services\Ai\AutonomousEvolution\Generated\AtlasLoopParkLedger;
use PHPUnit\Framework\TestCase;

final class AtlasLoopParkLedgerTest extends TestCase
{
    public function test_describes_the_park_ledger_contract(): void
    {
        $payload = (new AtlasLoopParkLedger)->describe();

        $this->assertSame(AtlasLoopParkLedger::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('available', $payload['status']);
        $this->assertSame('refiller_candidate_scoring', $payload['reentry_channel']);
        $this->assertSame('high_ev_parking_faster_than_delivered', $payload['health_metric']);
        $this->assertSame(5, $payload['required_field_count']);
        $this->assertContains('retry_with_stronger_model', $payload['escalation_policy']);
        $this->assertContains('retry_with_decomposition', $payload['escalation_policy']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['workspace_mutation_allowed']);
    }

    public function test_park_escalates_high_ev_items_and_marks_the_next_action(): void
    {
        $payload = (new AtlasLoopParkLedger)->park(
            objective: 'Deliver a high leverage loop improvement',
            reason: 'Projection stalled after critique rounds',
            expectedValue: 92,
            deliveryCount: 1,
            parkedCount: 3,
        );

        $this->assertSame('parked', $payload['status']);
        $this->assertSame('stronger_model_or_decomposition', $payload['escalation_level']);
        $this->assertSame('reenter_refiller_candidate_scoring', $payload['next_action']);
        $this->assertTrue($payload['health']['flagged']);
        $this->assertSame(92, $payload['health']['highest_expected_value']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['workspace_mutation_allowed']);
    }

    public function test_evaluate_health_does_not_flag_when_delivery_keeps_up_with_parks(): void
    {
        $health = (new AtlasLoopParkLedger)->evaluateHealth(
            deliveryCount: 4,
            parkedCount: 2,
            highestExpectedValue: 90,
        );

        $this->assertSame('high_ev_parking_faster_than_delivered', $health['metric']);
        $this->assertFalse($health['flagged']);
        $this->assertSame(4, $health['delivery_count']);
        $this->assertSame(2, $health['parked_count']);
    }
}
