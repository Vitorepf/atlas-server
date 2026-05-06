<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\QaReviewService;
use Tests\TestCase;

class QaReviewServiceTest extends TestCase
{
    public function test_qa_packet_is_cross_domain_review_only(): void
    {
        $packet = app(QaReviewService::class)->packet('qa.release_readiness', [
            'subject' => 'Atlas AI QA domain promotion',
            'scope' => 'domain profile, orchestrator, tests, docs, sync',
            'change_summary' => 'Promote QA from scaffold to governed review-only domain.',
            'acceptance_criteria' => ['ready onboarding', 'dry-run receipt', 'no test execution side effects'],
            'evidence_refs' => ['phpunit', 'architecture-validate', 'docs-health'],
            'risk_class' => 'medium',
        ]);

        $this->assertSame('atlas.qa.packet.v1', $packet['schema_version']);
        $this->assertSame('qa', $packet['domain']);
        $this->assertSame('qa.release_readiness', $packet['flow']);
        $this->assertSame('release_readiness_review', $packet['mode']);
        $this->assertSame([], data_get($packet, 'brief.missing_inputs'));
        $this->assertTrue(data_get($packet, 'qa_contract.review_only'));
        $this->assertTrue(data_get($packet, 'rules.does_not_execute_tests'));
        $this->assertTrue(data_get($packet, 'rules.does_not_override_domain_gates'));
        $this->assertContains('override_domain_gate', $packet['forbidden_actions']);
    }
}
