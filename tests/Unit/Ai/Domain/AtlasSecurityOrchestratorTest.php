<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\AtlasSecurityOrchestrator;
use Tests\TestCase;

class AtlasSecurityOrchestratorTest extends TestCase
{
    public function test_security_orchestrator_is_implemented_and_defensive_only(): void
    {
        $orchestrator = app(AtlasSecurityOrchestrator::class);

        $this->assertSame('AtlasSecurityOrchestrator', $orchestrator->orchestratorId());
        $this->assertSame(['security'], $orchestrator->supportedDomains());
        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertSame(['security.threat_review', 'security.privacy_review', 'security.compliance_review', 'security.incident_review'], $orchestrator->supportedFlows());

        $plan = $orchestrator->plan('security.privacy_review', [
            'asset' => 'AtlasVault sync',
            'scope' => 'provider-safe note import and redaction',
            'controls' => ['privacy classes', 'redaction review'],
        ]);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('privacy_review', $plan['mode']);
        $this->assertTrue(data_get($plan, 'packet.security_contract.defensive_only'));
        $this->assertTrue(data_get($plan, 'packet.rules.does_not_access_credentials'));
    }

    public function test_security_execute_emits_dry_run_receipt_and_evidence(): void
    {
        $orchestrator = app(AtlasSecurityOrchestrator::class);
        $plan = $orchestrator->plan('security.threat_review', [
            'asset' => 'Atlas provider projection',
            'scope' => 'secrets and provider-safe memory boundaries',
            'threat_model' => ['secret leakage', 'unreviewed sensitive memory promotion'],
            'evidence_refs' => ['memory-core-security-privacy.md'],
        ]);

        $result = $orchestrator->execute($plan, [
            'tenant_id' => 'test',
            'operator_id' => 'vitor',
            'surface_id' => 'phpunit',
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('security.threat_review', $result['flow']);
        $this->assertTrue($result['defensive_review_only']);
        $this->assertTrue(data_get($result, 'result.receipt.dry_run'));
        $this->assertSame('atlas.security.packet.v1', data_get($result, 'result.receipt.signed_by'));
        $this->assertContains('DECISION_ISSUED', data_get($result, 'result.ledger.events'));
    }
}
