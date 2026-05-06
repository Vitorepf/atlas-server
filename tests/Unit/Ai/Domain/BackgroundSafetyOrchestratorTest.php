<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\BackgroundSafetyOrchestrator;
use Tests\TestCase;

class BackgroundSafetyOrchestratorTest extends TestCase
{
    public function test_background_orchestrator_is_implemented_and_review_only(): void
    {
        $orchestrator = app(BackgroundSafetyOrchestrator::class);

        $this->assertSame('BackgroundSafetyOrchestrator', $orchestrator->orchestratorId());
        $this->assertSame(['background'], $orchestrator->supportedDomains());
        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertSame(['background.safe', 'background.readiness_review', 'background.schedule_review', 'background.permission_review'], $orchestrator->supportedFlows());

        $plan = $orchestrator->plan('background.permission_review', [
            'job' => 'Atlas curator nightly proposal generation',
            'scope' => 'read evidence and write proposal inbox',
            'permissions' => ['read ledger', 'write proposals'],
            'stop_conditions' => ['proposal risk high'],
        ]);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('permission_review', $plan['mode']);
        $this->assertTrue(data_get($plan, 'packet.background_contract.background_review_only'));
        $this->assertTrue(data_get($plan, 'packet.rules.does_not_escalate_permissions'));
    }

    public function test_background_execute_emits_dry_run_receipt_and_evidence(): void
    {
        $orchestrator = app(BackgroundSafetyOrchestrator::class);
        $plan = $orchestrator->plan('background.readiness_review', [
            'job' => 'Atlas memory maintenance heartbeat',
            'scope' => 'detect stale projections and prepare review-only recommendation',
            'trigger' => 'heartbeat',
            'schedule' => 'every 6 hours',
            'permissions' => ['read code index', 'read memory status'],
            'evidence_refs' => ['atlas:memory:maintain'],
            'stop_conditions' => ['operator pauses heartbeat', 'memory status critical'],
        ]);

        $result = $orchestrator->execute($plan, [
            'tenant_id' => 'test',
            'operator_id' => 'vitor',
            'surface_id' => 'phpunit',
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('background.readiness_review', $result['flow']);
        $this->assertTrue($result['review_only_until_operator_acceptance']);
        $this->assertTrue(data_get($result, 'result.receipt.dry_run'));
        $this->assertSame('atlas.background.packet.v1', data_get($result, 'result.receipt.signed_by'));
        $this->assertContains('DECISION_ISSUED', data_get($result, 'result.ledger.events'));
    }
}
