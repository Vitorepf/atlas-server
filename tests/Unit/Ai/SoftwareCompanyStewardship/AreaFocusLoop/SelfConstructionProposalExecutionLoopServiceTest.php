<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\SelfConstructionProposalExecutionLoopService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SelfConstructionProposalExecutionLoopServiceTest extends TestCase
{
    private SelfConstructionProposalExecutionLoopService $service;

    protected function setUp(): void
    {
        $this->service = new SelfConstructionProposalExecutionLoopService();
    }

    /**
     * A proposal that was honestly approved: real divergence + evidence,
     * honest merge, green validation and an approval receipt.
     *
     * @return array<string, mixed>
     */
    private function approvedProposal(string $id, bool $implemented = true): array
    {
        return [
            'id' => $id,
            'divergence_real' => true,
            'evidence_refs' => ['ledger://'.$id, 'diff://'.$id],
            'merged_honestly' => true,
            'validation' => 'green',
            'approval_receipt' => 'receipt-'.$id,
            'implemented' => $implemented,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $proposals
     */
    private function summarize(array $proposals): array
    {
        return $this->service->summarize($proposals);
    }

    public function testSummarizeReturnsAllComputedCountsAndBlockers(): void
    {
        $proposals = [
            $this->approvedProposal('a'),
            $this->approvedProposal('b'),
            // approved but reverted (so implemented_count must NOT include it)
            array_merge($this->approvedProposal('c'), ['reverted' => true]),
            // rejected: no real divergence, no evidence
            [
                'id' => 'd',
                'divergence_real' => false,
                'evidence_refs' => [],
            ],
            // pending human review (not approved)
            [
                'id' => 'e',
                'divergence_real' => true,
                'evidence_refs' => ['ledger://e'],
                'pending_human_review' => true,
                'validation' => 'green',
                'merged_honestly' => false,
            ],
            // invariant breach on an otherwise-approved proposal
            array_merge($this->approvedProposal('f'), ['invariant_breach' => true]),
        ];

        $result = $this->summarize($proposals);

        $this->assertSame('atlas.loop.self_construction_proposal_execution.v1', $result['schema_version']);
        $this->assertSame(4, $result['approved_count']);
        $this->assertSame(3, $result['implemented_count']);
        $this->assertSame(1, $result['reverted_count']);
        $this->assertSame(1, $result['pending_human_review_count']);
        $this->assertSame(1, $result['invariant_breach_count']);
        $this->assertSame(1, $result['rejected_count']);
        $this->assertContains('insufficient_approved_self_construction_proposals', $result['blockers']);
        $this->assertContains('invariant_breach_present', $result['blockers']);
        $this->assertFalse($result['l7_self_construction_ready']);
    }

    public function testProposalWithoutRealDivergenceOrEvidenceIsRejected(): void
    {
        $noDivergence = $this->summarize([
            [
                'id' => 'x',
                'divergence_real' => false,
                'evidence_refs' => ['ledger://x'],
                'merged_honestly' => true,
                'validation' => 'green',
                'approval_receipt' => 'receipt-x',
            ],
        ]);

        $this->assertSame(0, $noDivergence['approved_count']);
        $this->assertSame(1, $noDivergence['rejected_count']);

        $noEvidence = $this->summarize([
            [
                'id' => 'y',
                'divergence_real' => true,
                'evidence_refs' => [],
                'merged_honestly' => true,
                'validation' => 'green',
                'approval_receipt' => 'receipt-y',
            ],
        ]);

        $this->assertSame(0, $noEvidence['approved_count']);
        $this->assertSame(1, $noEvidence['rejected_count']);
    }

    public function testApprovedCountRequiresHonestMergeGreenValidationAndApprovalReceipt(): void
    {
        // Missing honest merge.
        $notMerged = $this->summarize([
            array_merge($this->approvedProposal('m'), ['merged_honestly' => false]),
        ]);
        $this->assertSame(0, $notMerged['approved_count']);

        // Validation not green.
        $notGreen = $this->summarize([
            array_merge($this->approvedProposal('v'), ['validation' => 'red']),
        ]);
        $this->assertSame(0, $notGreen['approved_count']);

        // Missing approval receipt.
        $noReceipt = $this->approvedProposal('r');
        unset($noReceipt['approval_receipt']);
        $missingReceipt = $this->summarize([$noReceipt]);
        $this->assertSame(0, $missingReceipt['approved_count']);

        // All three present -> increments exactly once.
        $honest = $this->summarize([$this->approvedProposal('h')]);
        $this->assertSame(1, $honest['approved_count']);
        $this->assertSame(1, $honest['implemented_count']);
    }

    public function testNineApprovedProposalsBlockL7(): void
    {
        $proposals = [];
        for ($i = 1; $i <= 9; $i++) {
            $proposals[] = $this->approvedProposal('p'.$i);
        }

        $result = $this->summarize($proposals);

        $this->assertSame(9, $result['approved_count']);
        $this->assertSame(0, $result['invariant_breach_count']);
        $this->assertFalse($result['l7_self_construction_ready']);
        $this->assertSame(['insufficient_approved_self_construction_proposals'], $result['blockers']);
    }

    public function testTenApprovedProposalsPassL7WhenNoInvariantBreach(): void
    {
        $proposals = [];
        for ($i = 1; $i <= 10; $i++) {
            $proposals[] = $this->approvedProposal('q'.$i);
        }

        $result = $this->summarize($proposals);

        $this->assertSame(10, $result['approved_count']);
        $this->assertSame(10, $result['implemented_count']);
        $this->assertSame(0, $result['invariant_breach_count']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['l7_self_construction_ready']);
    }

    public function testReadinessKeysOnApprovedAndBreachNotOnImplementedCount(): void
    {
        // Canonical L7 promotion criterion (roadmap:269, runbook:130) is exactly
        // "10 approved + 0 invariant breached" — it does NOT require 10 implemented.
        // implemented_count / reverted_count are separate honest accounting and must
        // NOT gate readiness. 10 honestly-approved-but-reverted proposals (so
        // implemented_count=0) with no breach therefore still pass L7. This pins the
        // decoupling so a future tightening to require implemented>=10 cannot slip in.
        $proposals = [];
        for ($i = 1; $i <= 10; $i++) {
            $proposals[] = array_merge($this->approvedProposal('rv'.$i), ['reverted' => true]);
        }

        $result = $this->summarize($proposals);

        $this->assertSame(10, $result['approved_count']);
        $this->assertSame(0, $result['implemented_count']);
        $this->assertSame(10, $result['reverted_count']);
        $this->assertSame(0, $result['invariant_breach_count']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['l7_self_construction_ready']);
    }

    public function testTenApprovedWithOneInvariantBreachStillBlocks(): void
    {
        $proposals = [];
        for ($i = 1; $i <= 10; $i++) {
            $proposals[] = $this->approvedProposal('z'.$i);
        }
        // Add an 11th approved proposal that also breaches an invariant.
        $proposals[] = array_merge($this->approvedProposal('breach'), ['invariant_breach' => true]);

        $result = $this->summarize($proposals);

        $this->assertSame(11, $result['approved_count']);
        $this->assertSame(1, $result['invariant_breach_count']);
        $this->assertFalse($result['l7_self_construction_ready']);
        $this->assertSame(['invariant_breach_present'], $result['blockers']);
    }

    public function testServiceExposesOnlyCountingAndNeverApprovesOrApplies(): void
    {
        $reflection = new ReflectionClass(SelfConstructionProposalExecutionLoopService::class);

        $publicMethods = [];
        foreach ($reflection->getMethods() as $method) {
            if ($method->isPublic() && ! $method->isConstructor()) {
                $publicMethods[] = $method->getName();
            }
        }

        // The only callable behaviour is read-only counting: there is no public
        // method that could approve or apply a proposal.
        $this->assertSame(['summarize'], $publicMethods);
        $this->assertFalse($reflection->hasMethod('approve'));
        $this->assertFalse($reflection->hasMethod('apply'));
        $this->assertFalse($reflection->hasMethod('applyProposal'));
        $this->assertFalse($reflection->hasMethod('approveProposal'));
    }

    public function testEmptyProposalSetBlocksL7WithCleanCounts(): void
    {
        $result = $this->summarize([]);

        $this->assertSame(0, $result['approved_count']);
        $this->assertSame(0, $result['implemented_count']);
        $this->assertSame(0, $result['reverted_count']);
        $this->assertSame(0, $result['pending_human_review_count']);
        $this->assertSame(0, $result['invariant_breach_count']);
        $this->assertSame(0, $result['rejected_count']);
        $this->assertSame(10, $result['required_approved_proposals']);
        $this->assertFalse($result['l7_self_construction_ready']);
        $this->assertSame(['insufficient_approved_self_construction_proposals'], $result['blockers']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $proposals = [
            $this->approvedProposal('d1'),
            array_merge($this->approvedProposal('d2'), ['reverted' => true]),
            ['id' => 'd3', 'divergence_real' => false, 'evidence_refs' => []],
        ];

        $first = $this->summarize($proposals);
        $second = $this->summarize($proposals);

        $this->assertSame($first, $second);
    }

    /**
     * Anti-scaffold / generalization: counts must be computed from arbitrary
     * mixed inputs the production rules would see, not canned to fixed fixtures.
     */
    public function testGeneralisesOverArbitraryMixedInput(): void
    {
        $proposals = [];

        // 6 honest approvals, 2 of them reverted (still approved, not implemented).
        for ($i = 1; $i <= 6; $i++) {
            $proposals[] = $this->approvedProposal('g'.$i, implemented: $i <= 4);
            if ($i === 5 || $i === 6) {
                $proposals[count($proposals) - 1]['reverted'] = true;
            }
        }

        // 3 rejected (no divergence/evidence).
        for ($i = 1; $i <= 3; $i++) {
            $proposals[] = ['id' => 'rej'.$i, 'divergence_real' => false, 'evidence_refs' => []];
        }

        // 2 pending human review (not approved).
        for ($i = 1; $i <= 2; $i++) {
            $proposals[] = [
                'id' => 'pend'.$i,
                'divergence_real' => true,
                'evidence_refs' => ['e'],
                'merged_honestly' => false,
                'pending_human_review' => true,
            ];
        }

        $result = $this->summarize($proposals);

        $this->assertSame(6, $result['approved_count']);
        $this->assertSame(4, $result['implemented_count']);
        $this->assertSame(2, $result['reverted_count']);
        $this->assertSame(2, $result['pending_human_review_count']);
        $this->assertSame(3, $result['rejected_count']);
        $this->assertSame(0, $result['invariant_breach_count']);
        $this->assertFalse($result['l7_self_construction_ready']);
    }

    /**
     * blockers and the readiness flag are list<string> / bool contracts.
     */
    public function testBlockersIsListOfStringsAndReadyIsBool(): void
    {
        $result = $this->summarize([$this->approvedProposal('only')]);

        $this->assertIsArray($result['blockers']);
        $this->assertSame(array_values($result['blockers']), $result['blockers']);
        foreach ($result['blockers'] as $blocker) {
            $this->assertIsString($blocker);
        }
        $this->assertIsBool($result['l7_self_construction_ready']);
        $this->assertIsInt($result['approved_count']);
    }
}
