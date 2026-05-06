<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\SecurityReviewService;
use Tests\TestCase;

class SecurityReviewServiceTest extends TestCase
{
    public function test_security_packet_is_defensive_review_only(): void
    {
        $packet = app(SecurityReviewService::class)->packet('security.compliance_review', [
            'asset' => 'Atlas Evidence Ledger',
            'scope' => 'append-only event storage and projection health',
            'controls' => ['append-only guard', 'projection drift report'],
            'evidence_refs' => ['architecture-validate', 'ledger projection health'],
            'risk_class' => 'high',
        ]);

        $this->assertSame('atlas.security.packet.v1', $packet['schema_version']);
        $this->assertSame('security', $packet['domain']);
        $this->assertSame('security.compliance_review', $packet['flow']);
        $this->assertSame('compliance_review', $packet['mode']);
        $this->assertSame([], data_get($packet, 'brief.missing_inputs'));
        $this->assertTrue(data_get($packet, 'security_contract.defensive_only'));
        $this->assertTrue(data_get($packet, 'rules.does_not_execute_exploits'));
        $this->assertTrue(data_get($packet, 'rules.does_not_access_credentials'));
        $this->assertContains('access_or_print_secrets', $packet['forbidden_actions']);
    }
}
