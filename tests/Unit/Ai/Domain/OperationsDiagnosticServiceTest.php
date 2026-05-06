<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\OperationsDiagnosticService;
use Tests\TestCase;

class OperationsDiagnosticServiceTest extends TestCase
{
    public function test_operations_packet_is_diagnostic_only(): void
    {
        $packet = app(OperationsDiagnosticService::class)->packet('operations.readiness_review', [
            'system' => 'Atlas domain catalog',
            'scope' => 'ready domain promotion and mobile surface routing',
            'symptoms' => ['new domain added', 'surface catalog must stay consistent'],
            'signals' => ['architecture-validate ok', 'domain onboarding ready'],
            'evidence_refs' => ['atlas:ai:domains', 'docs-health'],
            'risk_class' => 'medium',
        ]);

        $this->assertSame('atlas.operations.packet.v1', $packet['schema_version']);
        $this->assertSame('operations', $packet['domain']);
        $this->assertSame('operations.readiness_review', $packet['flow']);
        $this->assertSame('readiness_review', $packet['mode']);
        $this->assertSame([], data_get($packet, 'brief.missing_inputs'));
        $this->assertTrue(data_get($packet, 'operations_contract.diagnostic_only'));
        $this->assertTrue(data_get($packet, 'rules.does_not_deploy'));
        $this->assertTrue(data_get($packet, 'rules.does_not_modify_infrastructure'));
        $this->assertContains('modify_infrastructure', $packet['forbidden_actions']);
    }
}
