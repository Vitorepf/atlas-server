<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9Q2ProvenInvariantCertificationService;
use PHPUnit\Framework\TestCase;

final class L9Q2ProvenInvariantCertificationServiceTest extends TestCase
{
    private L9Q2ProvenInvariantCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new L9Q2ProvenInvariantCertificationService();
    }

    /**
     * A fully composed, fully proven Q2 input: sovereignty locked, two sacred
     * invariants, both covered by verified proofs, and delegation that relies only
     * on proven invariants. Arrays (not the bool fast-path) exercise the real
     * branches end to end.
     *
     * @return array<string,mixed>
     */
    private function certifiedInputs(): array
    {
        return [
            'sovereignty' => ['operator_is_sole_source' => true, 'system_as_value_source' => false],
            'invariant_set' => [
                ['invariant_id' => 'merge_truth'],
                ['invariant_id' => 'provider_claim_truth'],
            ],
            'proof_specs' => [
                ['theorem_id' => 'thm_merge', 'proof_status' => 'not_verified'],
                ['theorem_id' => 'thm_provider', 'proof_status' => 'not_verified'],
            ],
            'proof_results' => [
                ['verified' => true, 'covered_invariant_ids' => ['merge_truth']],
                ['verified' => true, 'covered_invariant_ids' => ['provider_claim_truth']],
            ],
            'delegation' => [
                ['decision_class' => 'merge_truth', 'invariant_id' => 'merge_truth', 'risk_level' => 'medium'],
            ],
        ];
    }

    public function testCertifiesWhenSovereigntyLockedProofsCoverSetAndDelegationWithinProof(): void
    {
        $result = $this->service->certify($this->certifiedInputs());

        // Acceptance: schema + every required computed field.
        $this->assertSame('atlas.aaeos.l9.q2_proven_invariant_certification.v1', $result['schema_version']);
        $this->assertSame('L9-Q2', $result['phase']);
        $this->assertTrue($result['q2_certified']);
        $this->assertTrue($result['sovereignty_locked']);
        $this->assertSame(2, $result['invariant_count']);
        $this->assertSame(2, $result['proven_invariant_count']);
        $this->assertSame(['merge_truth', 'provider_claim_truth'], $result['proven_invariant_ids']);
        $this->assertSame('l9_q2_proven', $result['status']);
        $this->assertSame([], $result['blockers']);

        // delegation_boundary is computed: the requested class is proof-bounded.
        $this->assertSame(['merge_truth'], $result['delegation_boundary']['allowed_decision_classes']);
        $this->assertSame([], $result['delegation_boundary']['blocked_decision_classes']);
        $this->assertSame('medium', $result['delegation_boundary']['max_risk_level']);
        $this->assertTrue($result['delegation_boundary']['within_proof']);
        $this->assertTrue($result['delegation_boundary']['operator_override_required']);
    }

    public function testResultExposesEveryRequiredKey(): void
    {
        $result = $this->service->certify($this->certifiedInputs());

        foreach ([
            'schema_version',
            'q2_certified',
            'sovereignty_locked',
            'proven_invariant_count',
            'delegation_boundary',
            'blockers',
        ] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function testSovereigntyMissingBlocks(): void
    {
        $inputs = $this->certifiedInputs();
        // Operator authority not asserted: sovereignty is not locked.
        $inputs['sovereignty'] = ['operator_is_sole_source' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['sovereignty_locked']);
        $this->assertFalse($result['q2_certified']);
        $this->assertSame('blocked_not_l9_q2', $result['status']);
        $this->assertContains('sovereignty_missing', $result['blockers']);
        // Proof + delegation are otherwise intact: the sole gap is sovereignty.
        $this->assertSame(['sovereignty_missing'], $result['blockers']);
        $this->assertSame(2, $result['proven_invariant_count']);
    }

    public function testAbsentSovereigntyInputBlocksFailClosed(): void
    {
        $inputs = $this->certifiedInputs();
        unset($inputs['sovereignty']);

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['sovereignty_locked']);
        $this->assertFalse($result['q2_certified']);
        $this->assertSame('sovereignty_missing', $result['blockers'][0]);
    }

    public function testSystemAsValueSourceForfeitsSovereigntyEvenWithOperatorAuthority(): void
    {
        $inputs = $this->certifiedInputs();
        // The system claims to be a value source: sovereignty is forfeited outright.
        $inputs['sovereignty'] = ['operator_is_sole_source' => true, 'system_as_value_source' => true];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['sovereignty_locked']);
        $this->assertFalse($result['q2_certified']);
        $this->assertContains('sovereignty_missing', $result['blockers']);
    }

    public function testProofMissingBlocksWhenNoVerifiedProofCoversTheSet(): void
    {
        $inputs = $this->certifiedInputs();
        // Both results are unverified envelopes: a design spec / unverified result
        // is NOT a proof, so nothing in the sacred set is proven.
        $inputs['proof_results'] = [
            ['verified' => false, 'covered_invariant_ids' => ['merge_truth']],
            ['verified' => false, 'covered_invariant_ids' => ['provider_claim_truth']],
        ];

        $result = $this->service->certify($inputs);

        $this->assertSame(0, $result['proven_invariant_count']);
        $this->assertSame([], $result['proven_invariant_ids']);
        $this->assertFalse($result['q2_certified']);
        $this->assertContains('proof_missing', $result['blockers']);
    }

    public function testUnverifiedProofResultProvesNothing(): void
    {
        $inputs = $this->certifiedInputs();
        // One verified (merge_truth), one unverified (provider_claim_truth).
        $inputs['proof_results'] = [
            ['verified' => true, 'covered_invariant_ids' => ['merge_truth']],
            ['verified' => false, 'covered_invariant_ids' => ['provider_claim_truth']],
        ];
        // Drop the delegation that relies on the now-unproven invariant.
        $inputs['delegation'] = [
            ['decision_class' => 'merge_truth', 'invariant_id' => 'merge_truth', 'risk_level' => 'low'],
        ];

        $result = $this->service->certify($inputs);

        // Only the verified-covered invariant is proven.
        $this->assertSame(1, $result['proven_invariant_count']);
        $this->assertSame(['merge_truth'], $result['proven_invariant_ids']);
        // At least one invariant is proven, so proof_missing does NOT fire.
        $this->assertNotContains('proof_missing', $result['blockers']);
        $this->assertTrue($result['q2_certified']);
    }

    public function testDelegationBeyondProofBlocks(): void
    {
        $inputs = $this->certifiedInputs();
        // Only merge_truth is proven; provider_claim_truth has no verified proof.
        $inputs['proof_results'] = [
            ['verified' => true, 'covered_invariant_ids' => ['merge_truth']],
        ];
        // Delegation requests a class bound to the UNPROVEN invariant.
        $inputs['delegation'] = [
            ['decision_class' => 'merge_decision', 'invariant_id' => 'merge_truth', 'risk_level' => 'low'],
            ['decision_class' => 'provider_claim_decision', 'invariant_id' => 'provider_claim_truth', 'risk_level' => 'high'],
        ];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['q2_certified']);
        $this->assertContains('delegation_beyond_proof', $result['blockers']);
        $this->assertFalse($result['delegation_boundary']['within_proof']);
        // The proof-bounded class is allowed; the over-reaching class is blocked.
        $this->assertSame(['merge_decision'], $result['delegation_boundary']['allowed_decision_classes']);
        $this->assertSame(['provider_claim_decision'], $result['delegation_boundary']['blocked_decision_classes']);
        // max_risk_level reflects only ALLOWED classes (the high-risk class is blocked).
        $this->assertSame('low', $result['delegation_boundary']['max_risk_level']);
    }

    public function testBlockersAreOrderedSovereigntyThenProofThenDelegation(): void
    {
        // Every Q2 precondition fails at once: no sovereignty, no verified proof,
        // and a delegation request that can never be proof-bounded.
        $result = $this->service->certify([
            'sovereignty' => false,
            'invariant_set' => ['merge_truth'],
            'proof_results' => [
                ['verified' => false, 'covered_invariant_ids' => ['merge_truth']],
            ],
            'delegation' => [
                ['decision_class' => 'merge_decision', 'invariant_id' => 'merge_truth'],
            ],
        ]);

        $this->assertFalse($result['q2_certified']);
        // Safety-first ordering: sovereignty leads, then proof, then delegation.
        $this->assertSame(
            ['sovereignty_missing', 'proof_missing', 'delegation_beyond_proof'],
            $result['blockers'],
        );
    }

    public function testProvenCountNeverExceedsInvariantSetWhenVerifiedProofCoversOutOfSetId(): void
    {
        $inputs = $this->certifiedInputs();
        // A verified proof also covers an id OUTSIDE the sacred set, plus a
        // duplicate of an in-set id: neither may inflate proven_invariant_count.
        $inputs['proof_results'] = [
            ['verified' => true, 'covered_invariant_ids' => ['merge_truth', 'merge_truth', 'rogue_invariant_not_in_set']],
            ['verified' => true, 'covered_invariant_ids' => ['provider_claim_truth']],
        ];

        $result = $this->service->certify($inputs);

        // Bound honoured: count == number of distinct in-set proven ids, never more.
        $this->assertSame(2, $result['invariant_count']);
        $this->assertSame(2, $result['proven_invariant_count']);
        $this->assertLessThanOrEqual($result['invariant_count'], $result['proven_invariant_count']);
        $this->assertNotContains('rogue_invariant_not_in_set', $result['proven_invariant_ids']);
        $this->assertSame(['merge_truth', 'provider_claim_truth'], $result['proven_invariant_ids']);
    }

    public function testInvariantSetHonoursListOfStringContractAndIgnsCoercibleNonStrings(): void
    {
        $result = $this->service->certify([
            'sovereignty' => true,
            // Non-string and blank ids must NOT coerce into the set; duplicates collapse.
            'invariant_set' => ['merge_truth', 0, 1, '', '  ', 'merge_truth', 'scope_truth'],
            'proof_results' => [
                ['verified' => true, 'covered_invariant_ids' => ['merge_truth', 'scope_truth']],
            ],
            'delegation' => [],
        ]);

        // Only the two real string ids survive; ints 0/1 and blanks are dropped.
        $this->assertSame(2, $result['invariant_count']);
        $this->assertSame(2, $result['proven_invariant_count']);
        $this->assertSame(['merge_truth', 'scope_truth'], $result['proven_invariant_ids']);
        $this->assertTrue($result['q2_certified']);
    }

    public function testEmptyDelegationIsVacuouslyWithinProof(): void
    {
        $inputs = $this->certifiedInputs();
        $inputs['delegation'] = [];

        $result = $this->service->certify($inputs);

        $this->assertTrue($result['delegation_boundary']['within_proof']);
        $this->assertSame([], $result['delegation_boundary']['allowed_decision_classes']);
        $this->assertSame([], $result['delegation_boundary']['blocked_decision_classes']);
        // No allowed class => the risk floor is 'none'.
        $this->assertSame('none', $result['delegation_boundary']['max_risk_level']);
        $this->assertNotContains('delegation_beyond_proof', $result['blockers']);
        $this->assertTrue($result['q2_certified']);
    }

    public function testEmptyInputsBlockOnEveryComposedPrecondition(): void
    {
        $result = $this->service->certify([]);

        $this->assertFalse($result['sovereignty_locked']);
        $this->assertSame(0, $result['invariant_count']);
        $this->assertSame(0, $result['proven_invariant_count']);
        $this->assertFalse($result['q2_certified']);
        $this->assertSame('blocked_not_l9_q2', $result['status']);
        // With no delegation requested, the delegation boundary is not breached, so
        // only sovereignty and proof fire.
        $this->assertSame(['sovereignty_missing', 'proof_missing'], $result['blockers']);
    }

    public function testBareStringDelegationClassReliesOnInvariantOfSameId(): void
    {
        $result = $this->service->certify([
            'sovereignty' => true,
            'invariant_set' => ['merge_truth'],
            'proof_results' => [
                ['verified' => true, 'covered_invariant_ids' => ['merge_truth']],
            ],
            // Bare-string class 'merge_truth' is bound to the merge_truth proof;
            // bare-string class 'scope_truth' has no proof => blocked.
            'delegation' => ['merge_truth', 'scope_truth'],
        ]);

        $this->assertSame(['merge_truth'], $result['delegation_boundary']['allowed_decision_classes']);
        $this->assertSame(['scope_truth'], $result['delegation_boundary']['blocked_decision_classes']);
        $this->assertFalse($result['delegation_boundary']['within_proof']);
        $this->assertContains('delegation_beyond_proof', $result['blockers']);
        $this->assertFalse($result['q2_certified']);
    }

    public function testSameClassBoundBeyondProofPoisonsItOutOfAllowedSet(): void
    {
        // The same decision_class is requested twice: once relying on a PROVEN
        // invariant (would be allowed) and once relying on an UNPROVEN invariant
        // (must be blocked). Because delegation is bounded by proof, the
        // over-reaching request must poison the class: it stays blocked and must
        // NOT also leak into the allowed set, and its risk must NOT inflate
        // max_risk_level (which reflects ALLOWED classes only).
        $result = $this->service->certify([
            'sovereignty' => true,
            'invariant_set' => ['proven_inv'],
            'proof_results' => [
                ['verified' => true, 'covered_invariant_ids' => ['proven_inv']],
            ],
            'delegation' => [
                // A genuinely proof-bounded class survives, at medium risk.
                ['decision_class' => 'safe_class', 'invariant_id' => 'proven_inv', 'risk_level' => 'medium'],
                // The dual class: proof-bounded here (high risk) ...
                ['decision_class' => 'dual_class', 'invariant_id' => 'proven_inv', 'risk_level' => 'high'],
                // ... but over-reaching here, which must poison it.
                ['decision_class' => 'dual_class', 'invariant_id' => 'unproven_inv', 'risk_level' => 'low'],
            ],
        ]);

        $boundary = $result['delegation_boundary'];
        // The poisoned class is blocked only; the clean class stays allowed.
        $this->assertSame(['safe_class'], $boundary['allowed_decision_classes']);
        $this->assertSame(['dual_class'], $boundary['blocked_decision_classes']);
        $this->assertNotContains('dual_class', $boundary['allowed_decision_classes']);
        // max_risk_level reflects only the surviving allowed class (medium), never
        // the poisoned dual class's high risk.
        $this->assertSame('medium', $boundary['max_risk_level']);
        // A blocked class breaches the boundary, so Q2 is not certified.
        $this->assertFalse($boundary['within_proof']);
        $this->assertContains('delegation_beyond_proof', $result['blockers']);
        $this->assertFalse($result['q2_certified']);
        // list<string> contract: allowed set stays a re-indexed 0..n list.
        $this->assertSame(array_values($boundary['allowed_decision_classes']), $boundary['allowed_decision_classes']);
    }

    public function testIdenticalInputsAreDeterministic(): void
    {
        $inputs = $this->certifiedInputs();

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);

        $this->assertSame($first, $second);
    }

    public function testBlockersListIsAlwaysAZeroIndexedList(): void
    {
        $result = $this->service->certify($this->certifiedInputs());

        $this->assertSame(array_values($result['blockers']), $result['blockers']);

        $blocked = $this->service->certify([]);
        $this->assertSame(array_values($blocked['blockers']), $blocked['blockers']);
    }
}
