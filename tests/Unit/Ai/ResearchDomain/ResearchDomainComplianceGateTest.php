<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\ResearchDomain;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\ResearchDomain\ResearchDomainCanon;
use App\Services\Ai\ResearchDomain\ResearchDomainComplianceGate;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ResearchDomainComplianceGate.
 *
 * Plain PHPUnit\Framework\TestCase — no Laravel bootstrap needed.
 * Storage paths are overridden so each test writes to a temp file.
 */
class ResearchDomainComplianceGateTest extends TestCase
{
    private string $tempDir;

    private string $kernelLog;

    private string $admissionLog;

    private string $gatesLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/atlas-research-gate-'.uniqid('', true);
        @mkdir($this->tempDir, 0777, true);
        $this->kernelLog = $this->tempDir.'/kernel-violations.jsonl';
        $this->admissionLog = $this->tempDir.'/admission-tickets.jsonl';
        $this->gatesLog = $this->tempDir.'/gates.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function gate(): ResearchDomainComplianceGate
    {
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);

        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);

        $gate = new ResearchDomainComplianceGate($kernel, $admission);
        $gate->setGatesLogPathForTesting($this->gatesLog);

        return $gate;
    }

    /**
     * @return array<string,mixed>
     */
    private function validPlan(): array
    {
        return [
            'domain' => ResearchDomainCanon::DOMAIN_ID,
            'output_mode' => ResearchDomainComplianceGate::OUTPUT_MODE,
            'execution_intent_allowed' => false,
            'compliance_gate_required' => true,
            'source_grounded' => true,
            'accepted_sources_count' => ResearchDomainCanon::MIN_ACCEPTED_SOURCES,
            'source_diversity' => ResearchDomainCanon::MIN_SOURCE_DIVERSITY,
            'claims_with_source_refs' => ResearchDomainCanon::MIN_CLAIMS,
            'actor' => 'research_domain_test',
            'privacy_class' => 'normal',
        ];
    }

    public function test_envelope_shape_canonical_on_allow(): void
    {
        $envelope = $this->gate()->evaluate($this->validPlan());

        $this->assertSame(ResearchDomainComplianceGate::SCHEMA_VERSION, $envelope['schema_version']);
        $this->assertSame(ResearchDomainCanon::DOMAIN_ID, $envelope['domain']);
        $this->assertSame(ResearchDomainComplianceGate::DECISION_ALLOW, $envelope['decision']);
        $this->assertSame(ResearchDomainComplianceGate::OUTPUT_MODE, $envelope['output_mode']);
        $this->assertSame([], $envelope['reasons']);
        $this->assertTrue($envelope['review_only']);
        $this->assertFalse($envelope['execution_intent_allowed']);
        $this->assertArrayHasKey('thresholds', $envelope);
        $this->assertArrayHasKey('measured', $envelope);
        $this->assertStringStartsWith('sha256:', $envelope['envelope_hash']);
    }

    public function test_source_grounded_false_requires_evidence(): void
    {
        $plan = $this->validPlan();
        $plan['source_grounded'] = false;

        $envelope = $this->gate()->evaluate($plan);

        $this->assertSame(ResearchDomainComplianceGate::DECISION_REQUIRES_EVIDENCE, $envelope['decision']);
        $this->assertContains('source_grounded_must_be_true', $envelope['reasons']);
    }

    public function test_accepted_sources_below_threshold_requires_evidence(): void
    {
        $plan = $this->validPlan();
        $plan['accepted_sources_count'] = ResearchDomainCanon::MIN_ACCEPTED_SOURCES - 1;

        $envelope = $this->gate()->evaluate($plan);

        $this->assertSame(ResearchDomainComplianceGate::DECISION_REQUIRES_EVIDENCE, $envelope['decision']);
        $matched = array_filter($envelope['reasons'], fn ($r) => str_starts_with($r, 'accepted_sources_below_threshold:'));
        $this->assertNotEmpty($matched);
    }

    public function test_source_diversity_below_threshold_requires_evidence(): void
    {
        $plan = $this->validPlan();
        $plan['source_diversity'] = 0;

        $envelope = $this->gate()->evaluate($plan);

        $this->assertSame(ResearchDomainComplianceGate::DECISION_REQUIRES_EVIDENCE, $envelope['decision']);
        $matched = array_filter($envelope['reasons'], fn ($r) => str_starts_with($r, 'source_diversity_below_threshold:'));
        $this->assertNotEmpty($matched);
    }

    public function test_execution_intent_flag_is_blocked(): void
    {
        $plan = $this->validPlan();
        $plan['execution_intent_allowed'] = true;

        $envelope = $this->gate()->evaluate($plan);

        $this->assertSame(ResearchDomainComplianceGate::DECISION_REQUIRES_EVIDENCE, $envelope['decision']);
        $this->assertContains('execution_intent_must_be_disabled', $envelope['reasons']);
    }

    public function test_requested_execution_actions_hard_block(): void
    {
        $envelope = $this->gate()->evaluate($this->validPlan(), ['publish_to_external_feed']);

        $this->assertSame(ResearchDomainComplianceGate::DECISION_BLOCK, $envelope['decision']);
        $this->assertContains('research_execution_request_blocked', $envelope['reasons']);
    }

    public function test_kernel_block_propagates_as_hard_block(): void
    {
        $plan = $this->validPlan();
        // sensitive outbound class trips sovereignty_local_first → kernel block.
        $plan['outbound_data_classes'] = ['sensitive'];

        $envelope = $this->gate()->evaluate($plan);

        $this->assertSame(AtlasConstitutionalKernelService::DECISION_BLOCK, $envelope['kernel_decision']);
        $this->assertSame(ResearchDomainComplianceGate::DECISION_BLOCK, $envelope['decision']);
    }

    public function test_receipt_is_append_only(): void
    {
        $gate = $this->gate();
        $gate->evaluate($this->validPlan());
        $gate->evaluate($this->validPlan());

        $receipts = $gate->listReceipts();
        $this->assertCount(2, $receipts);
        $this->assertSame(ResearchDomainComplianceGate::SCHEMA_VERSION, $receipts[0]['schema_version']);
        // file_get_contents proves the second line wasn't replaced.
        $raw = file_get_contents($this->gatesLog);
        $this->assertSame(2, substr_count($raw, PHP_EOL));
    }

    public function test_claim_policy_locks_external_claims(): void
    {
        $cp = $this->gate()->evaluate($this->validPlan())['claim_policy'];

        $this->assertFalse($cp['benchmark_claim_allowed']);
        $this->assertFalse($cp['rivals_claim_allowed']);
        $this->assertFalse($cp['superiority_claim_allowed']);
        $this->assertFalse($cp['external_rivals_certification_touched']);
        $this->assertFalse($cp['concurrent_claim_allowed']);
        $this->assertTrue($cp['cognitive_immune_law_enforced']);
        $this->assertTrue($cp['provider_safe_only_enforced']);
        $this->assertTrue($cp['review_only_enforced']);
    }

    public function test_invalid_domain_requires_evidence(): void
    {
        $plan = $this->validPlan();
        $plan['domain'] = 'marketing';

        $envelope = $this->gate()->evaluate($plan);

        $this->assertSame(ResearchDomainComplianceGate::DECISION_REQUIRES_EVIDENCE, $envelope['decision']);
        $this->assertContains('invalid_research_domain', $envelope['reasons']);
    }

    public function test_envelope_hash_is_deterministic_for_same_input(): void
    {
        $plan = $this->validPlan();
        $a = $this->gate()->evaluate($plan);

        // Fresh gate so admission/kernel logs start clean — hashes cover
        // the deterministic canonical fields, not the recorded_at timestamp.
        $b = $this->gate()->evaluate($plan);

        $this->assertSame($a['envelope_hash'], $b['envelope_hash']);
    }
}
