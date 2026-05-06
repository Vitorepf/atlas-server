<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\AtlasOperationsOrchestrator;
use Tests\TestCase;

class AtlasOperationsOrchestratorTest extends TestCase
{
    public function test_operations_orchestrator_is_implemented_and_diagnostic_only(): void
    {
        $orchestrator = app(AtlasOperationsOrchestrator::class);

        $this->assertSame('AtlasOperationsOrchestrator', $orchestrator->orchestratorId());
        $this->assertSame(['operations'], $orchestrator->supportedDomains());
        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertSame(['operations.diagnostic', 'operations.runbook', 'operations.incident_review', 'operations.readiness_review'], $orchestrator->supportedFlows());

        $plan = $orchestrator->plan('operations.runbook', [
            'system' => 'Atlas Evidence Ledger projection',
            'scope' => 'safe backfill planning',
            'signals' => ['projection lag', 'ledger current'],
        ]);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('runbook_plan', $plan['mode']);
        $this->assertTrue(data_get($plan, 'packet.operations_contract.diagnostic_only'));
        $this->assertTrue(data_get($plan, 'packet.rules.does_not_restart_services'));
    }

    public function test_operations_execute_emits_dry_run_receipt_and_evidence(): void
    {
        $orchestrator = app(AtlasOperationsOrchestrator::class);
        $plan = $orchestrator->plan('operations.diagnostic', [
            'system' => 'Atlas memory maintenance',
            'scope' => 'provider projection stale state',
            'symptoms' => ['needs_memory status'],
            'evidence_refs' => ['atlas:memory:maintain'],
        ]);

        $result = $orchestrator->execute($plan, [
            'tenant_id' => 'test',
            'operator_id' => 'vitor',
            'surface_id' => 'phpunit',
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('operations.diagnostic', $result['flow']);
        $this->assertTrue($result['diagnostic_only_until_operator_acceptance']);
        $this->assertTrue(data_get($result, 'result.receipt.dry_run'));
        $this->assertSame('atlas.operations.packet.v1', data_get($result, 'result.receipt.signed_by'));
        $this->assertContains('DECISION_ISSUED', data_get($result, 'result.ledger.events'));
    }
}
