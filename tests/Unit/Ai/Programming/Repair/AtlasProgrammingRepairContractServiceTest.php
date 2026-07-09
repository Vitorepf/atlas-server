<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Repair;

use App\Services\Ai\Aaeos\Quarantine\AtlasProgrammingRepairContractService as QuarantineShim;
use App\Services\Ai\Programming\Repair\AtlasProgrammingRepairContractService;
use Tests\TestCase;

final class AtlasProgrammingRepairContractServiceTest extends TestCase
{
    public function test_ready_to_plan_when_identity_domain_and_evidence_present(): void
    {
        $service = new AtlasProgrammingRepairContractService;
        $verdict = $service->evaluate([
            'envelope_id' => 'env-1',
            'receipt_id' => 'rcp-1',
            'failure_domain' => 'gate.failed',
            'evidence_refs' => [['kind' => 'test_output', 'ref' => 'phpunit:MissionFoundation']],
            'strategy' => 'rerun_harness',
        ]);

        $this->assertSame(AtlasProgrammingRepairContractService::RECEIPT_SCHEMA, $verdict['schema']);
        $this->assertTrue($verdict['ready_to_plan']);
        $this->assertSame(AtlasProgrammingRepairContractService::VERDICT_READY, $verdict['verdict']);
    }

    public function test_rejects_heavy_repair_without_evidence(): void
    {
        $service = new AtlasProgrammingRepairContractService;
        $verdict = $service->evaluate([
            'envelope_id' => 'env-1',
            'receipt_id' => 'rcp-1',
            'failure_domain' => 'gate.failed',
            'evidence_refs' => [],
            'strategy' => 'rerun_harness',
        ]);

        $this->assertFalse($verdict['ready_to_plan']);
        $this->assertSame(AtlasProgrammingRepairContractService::VERDICT_REJECTED, $verdict['verdict']);
        $this->assertContains('evidence_refs_empty', $verdict['violations']);
    }

    public function test_quarantine_shim_extends_promoted_service(): void
    {
        $this->assertTrue(is_subclass_of(QuarantineShim::class, AtlasProgrammingRepairContractService::class));
        $this->assertSame(
            AtlasProgrammingRepairContractService::RECEIPT_SCHEMA,
            QuarantineShim::RECEIPT_SCHEMA,
        );
    }
}
