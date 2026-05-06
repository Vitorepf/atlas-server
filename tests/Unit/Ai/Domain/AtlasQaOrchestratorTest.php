<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\AtlasQaOrchestrator;
use Tests\TestCase;

class AtlasQaOrchestratorTest extends TestCase
{
    public function test_qa_orchestrator_is_implemented_and_review_only(): void
    {
        $orchestrator = app(AtlasQaOrchestrator::class);

        $this->assertSame('AtlasQaOrchestrator', $orchestrator->orchestratorId());
        $this->assertSame(['qa'], $orchestrator->supportedDomains());
        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertSame(['qa.regression_review', 'qa.acceptance_review', 'qa.evidence_audit', 'qa.release_readiness'], $orchestrator->supportedFlows());

        $plan = $orchestrator->plan('qa.evidence_audit', [
            'subject' => 'Atlas release candidate',
            'scope' => 'evidence traceability',
            'acceptance_criteria' => ['all claims link to evidence'],
            'evidence_refs' => ['ledger:abc', 'test:phpunit'],
        ]);

        $this->assertSame('planned', $plan['status']);
        $this->assertSame('evidence_audit', $plan['mode']);
        $this->assertTrue(data_get($plan, 'packet.qa_contract.review_only'));
        $this->assertTrue(data_get($plan, 'packet.rules.does_not_execute_tests'));
    }

    public function test_qa_execute_emits_dry_run_receipt_and_evidence(): void
    {
        $orchestrator = app(AtlasQaOrchestrator::class);
        $plan = $orchestrator->plan('qa.regression_review', [
            'subject' => 'Atlas domain registry',
            'scope' => 'regression risk after adding a ready domain',
            'acceptance_criteria' => ['catalog compliant', 'orchestrator supports every flow'],
            'evidence_refs' => ['DomainProfileComplianceTest'],
        ]);

        $result = $orchestrator->execute($plan, [
            'tenant_id' => 'test',
            'operator_id' => 'vitor',
            'surface_id' => 'phpunit',
        ]);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('qa.regression_review', $result['flow']);
        $this->assertTrue($result['review_only_until_operator_acceptance']);
        $this->assertTrue(data_get($result, 'result.receipt.dry_run'));
        $this->assertSame('atlas.qa.packet.v1', data_get($result, 'result.receipt.signed_by'));
        $this->assertContains('DECISION_ISSUED', data_get($result, 'result.ledger.events'));
    }
}
