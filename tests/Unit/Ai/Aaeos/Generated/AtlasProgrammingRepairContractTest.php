<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingRepairContractService;
use Tests\TestCase;

/**
 * Pins the executable "Target Use" contract from the doc: a repair request may
 * reach AtlasRepairOrchestrator::plan() only when the failure is classified,
 * envelope_id + receipt_id are preserved, and evidence_refs hold an accepted
 * kind; policy/security/compliance/unknown and terminal domains force human
 * review; heavy repair without evidence is blocked. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/domains/programming-repair-contract.md
 */
class AtlasProgrammingRepairContractTest extends TestCase
{
    private function service(): AtlasProgrammingRepairContractService
    {
        return new AtlasProgrammingRepairContractService;
    }

    /**
     * @return array<string,mixed>
     */
    private function validRequest(array $overrides = []): array
    {
        return array_merge([
            'envelope_id' => 'env-1',
            'receipt_id' => 'rcpt-1',
            'failure_domain' => 'gate.failed',
            'strategy' => 'rerun_harness',
            'evidence_refs' => [['kind' => 'test_output', 'ref' => 'phpunit#42']],
        ], $overrides);
    }

    public function test_fully_compliant_request_is_ready_to_plan(): void
    {
        // Doc "Target Use": classify failure, preserve ids, fill evidence_refs,
        // THEN call plan(). A request that satisfies all three is admissible.
        $v = $this->service()->evaluate($this->validRequest());

        $this->assertSame(AtlasProgrammingRepairContractService::VERDICT_READY, $v['verdict']);
        $this->assertTrue($v['ready_to_plan']);
        $this->assertTrue($v['may_call_orchestrator']);
        $this->assertSame([], $v['violations']);
        $this->assertContains('contract_satisfied_ready_to_plan', $v['reasons']);
        $this->assertTrue($this->service()->readyToPlan($this->validRequest()));
    }

    public function test_missing_envelope_and_receipt_ids_are_rejected(): void
    {
        // Doc: "preserve envelope_id and receipt_id". Drop both -> rejected,
        // and both names are reported in missing_identity.
        $v = $this->service()->evaluate($this->validRequest([
            'envelope_id' => '',
            'receipt_id' => null,
        ]));

        $this->assertSame(AtlasProgrammingRepairContractService::VERDICT_REJECTED, $v['verdict']);
        $this->assertFalse($v['ready_to_plan']);
        $this->assertFalse($v['identity_preserved']);
        $this->assertSame(['envelope_id', 'receipt_id'], $v['missing_identity']);
        $this->assertContains('missing_identity:envelope_id', $v['violations']);
        $this->assertContains('missing_identity:receipt_id', $v['violations']);
    }

    public function test_empty_evidence_blocks_heavy_repair(): void
    {
        // Doc: "block heavy repair without evidence". rerun_harness is heavy;
        // with no accepted evidence the request is rejected and names the rule.
        $v = $this->service()->evaluate($this->validRequest([
            'evidence_refs' => [],
        ]));

        $this->assertSame(AtlasProgrammingRepairContractService::VERDICT_REJECTED, $v['verdict']);
        $this->assertFalse($v['has_evidence']);
        $this->assertTrue($v['heavy_strategy']);
        $this->assertContains('evidence_refs_empty', $v['violations']);
        $this->assertContains('heavy_repair_without_evidence', $v['violations']);
    }

    public function test_evidence_ref_of_unrecognized_kind_is_not_accepted(): void
    {
        // Doc enumerates accepted kinds (ledger events, harness runs, diffs,
        // logs, screenshots, test output). A foreign kind does not satisfy the
        // evidence obligation even though a ref is present.
        $v = $this->service()->evaluate($this->validRequest([
            'evidence_refs' => [['kind' => 'rumor', 'ref' => 'x']],
        ]));

        $this->assertSame(1, $v['evidence_count']);
        $this->assertSame(0, $v['accepted_evidence_count']);
        $this->assertFalse($v['has_evidence']);
        $this->assertContains('evidence_refs_empty', $v['violations']);
    }

    public function test_security_finding_requires_human_review_even_when_well_formed(): void
    {
        // Doc: "require human review for policy, privacy, security, compliance,
        // unknown". A well-formed request on security.finding must NOT auto-plan.
        $v = $this->service()->evaluate($this->validRequest([
            'failure_domain' => 'security.finding',
        ]));

        $this->assertSame(AtlasProgrammingRepairContractService::VERDICT_NEEDS_HUMAN_REVIEW, $v['verdict']);
        $this->assertFalse($v['ready_to_plan']);
        $this->assertTrue($v['requires_human_review']);
        $this->assertContains('domain_requires_human_review:security.finding', $v['reasons']);
    }

    public function test_terminal_repair_exhausted_state_routes_to_human_review(): void
    {
        // Doc: "... and terminal states". repair.exhausted is terminal: no auto
        // plan, routed to a human with the terminal reason.
        $v = $this->service()->evaluate($this->validRequest([
            'failure_domain' => 'repair.exhausted',
        ]));

        $this->assertSame(AtlasProgrammingRepairContractService::VERDICT_NEEDS_HUMAN_REVIEW, $v['verdict']);
        $this->assertTrue($v['terminal_state']);
        $this->assertContains('terminal_state_requires_human_review:repair.exhausted', $v['reasons']);
    }

    public function test_unclassified_failure_is_rejected(): void
    {
        // Doc obligation 1: classify the failure. No failure_domain -> rejected.
        $v = $this->service()->evaluate($this->validRequest([
            'failure_domain' => null,
        ]));

        $this->assertSame(AtlasProgrammingRepairContractService::VERDICT_REJECTED, $v['verdict']);
        $this->assertFalse($v['failure_classified']);
        $this->assertContains('failure_not_classified', $v['violations']);
    }
}
