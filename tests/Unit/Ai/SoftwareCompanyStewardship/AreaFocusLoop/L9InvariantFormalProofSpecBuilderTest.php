<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9InvariantFormalProofSpecBuilder;
use PHPUnit\Framework\TestCase;

final class L9InvariantFormalProofSpecBuilderTest extends TestCase
{
    private L9InvariantFormalProofSpecBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new L9InvariantFormalProofSpecBuilder();
    }

    /**
     * @return list<array{invariant_id: string, statement: string}>
     */
    private function engineeringInvariants(): array
    {
        return [
            ['invariant_id' => 'merge_truth', 'statement' => 'A merge is reported only when it actually merged'],
            ['invariant_id' => 'provider_claim_truth', 'statement' => 'Provider claims must be evidence-backed'],
            ['invariant_id' => 'sensitive_class_locality', 'statement' => 'Sensitive classes never leave the machine'],
        ];
    }

    public function testBuildReturnsTheoremsAssumptionsObligationsVerifierRequirementsAndNotVerifiedStatus(): void
    {
        $spec = $this->builder->build($this->engineeringInvariants());

        // schema_version byte-for-byte canonical.
        $this->assertSame('atlas.loop.l9_invariant_formal_proof_spec.v1', $spec['schema_version']);

        // Aceite: theorem_ids — one per invariant, computed from the invariant ids.
        $this->assertSame(
            ['theorem.merge_truth', 'theorem.provider_claim_truth', 'theorem.sensitive_class_locality'],
            $spec['theorem_ids'],
        );
        $this->assertSame(3, $spec['theorem_count']);
        $this->assertSame(
            ['merge_truth', 'provider_claim_truth', 'sensitive_class_locality'],
            $spec['invariant_ids'],
        );
        $this->assertSame(3, $spec['invariant_count']);

        // Aceite: assumptions — the proof boundary's declared axioms, including the
        // honesty anchor that no verifier is attached yet.
        $this->assertSame(
            [
                'operator_is_sole_source_of_engineering_ends',
                'engineering_scope_only',
                'invariant_set_is_explicit_and_immovable',
                'no_formal_verifier_attached_yet',
            ],
            $spec['assumptions'],
        );

        // Aceite: proof_obligations — one per invariant, each binding a theorem to an
        // invariant and undischarged at design time.
        $this->assertSame(3, $spec['proof_obligation_count']);
        $this->assertCount(3, $spec['proof_obligations']);
        $this->assertSame('obligation.merge_truth', $spec['proof_obligations'][0]['obligation_id']);
        $this->assertSame('theorem.merge_truth', $spec['proof_obligations'][0]['theorem_id']);
        $this->assertSame('merge_truth', $spec['proof_obligations'][0]['invariant_id']);
        $this->assertSame(
            'No reachable system action may violate: A merge is reported only when it actually merged',
            $spec['proof_obligations'][0]['statement'],
        );
        $this->assertFalse($spec['proof_obligations'][0]['discharged']);
        $this->assertFalse($spec['proof_obligations'][2]['discharged']);

        // Aceite: verifier_requirements — what a future verifier must satisfy, plus
        // the computed coverage it must reach.
        $this->assertSame(
            [
                'artifact_hash_required',
                'verifier_identity_required',
                'reproducibility_required',
                'full_theorem_coverage_required',
            ],
            $spec['verifier_requirements'],
        );
        $this->assertSame(3, $spec['required_theorem_coverage']);

        // Aceite: proof_status=not_verified (byte-for-byte).
        $this->assertSame('not_verified', $spec['proof_status']);

        // A populated invariant set yields a ready design spec with no blockers.
        $this->assertSame('design_spec_ready', $spec['status']);
        $this->assertSame([], $spec['blockers']);
    }

    public function testEmptyInvariantSetBlocks(): void
    {
        $spec = $this->builder->build([]);

        // Aceite: empty invariant set blocks — nothing to prove.
        $this->assertSame('blocked', $spec['status']);
        $this->assertContains('empty_invariant_set', $spec['blockers']);

        $this->assertSame([], $spec['theorem_ids']);
        $this->assertSame(0, $spec['theorem_count']);
        $this->assertSame([], $spec['invariant_ids']);
        $this->assertSame(0, $spec['invariant_count']);
        $this->assertSame([], $spec['proof_obligations']);
        $this->assertSame(0, $spec['proof_obligation_count']);
        $this->assertSame(0, $spec['required_theorem_coverage']);

        // Even when blocked the status stays not_verified and proven stays false.
        $this->assertSame('not_verified', $spec['proof_status']);
        $this->assertFalse($spec['proven']);
    }

    public function testEntriesWithoutResolvableInvariantIdAreDroppedAndAllInvalidBlocks(): void
    {
        // Five entries, all without a resolvable id -> nothing survives -> blocked.
        $spec = $this->builder->build([
            ['invariant_id' => '   '],
            ['statement' => 'no id at all'],
            ['id' => ''],
            '',
            ['claim' => 'still no id'],
        ]);

        $this->assertSame([], $spec['invariant_ids']);
        $this->assertSame([], $spec['theorem_ids']);
        $this->assertSame('blocked', $spec['status']);
        $this->assertContains('empty_invariant_set', $spec['blockers']);
    }

    public function testGeneratedSpecNeverSetsProvenTrueAcrossReadyBlockedAndOtherSets(): void
    {
        // Aceite/DoD: a generated spec NEVER claims it is proven or formally verified,
        // and never authorizes delegation — for every input shape.
        $ready = $this->builder->build($this->engineeringInvariants());
        $blocked = $this->builder->build([]);
        $other = $this->builder->build([['invariant_id' => 'gate_integrity']]);

        foreach ([$ready, $blocked, $other] as $spec) {
            $this->assertFalse($spec['proven']);
            $this->assertFalse($spec['claims_formal_verification']);
            $this->assertSame('not_verified', $spec['proof_status']);
            // DoD: design the proof boundary BEFORE any delegation expands — the spec
            // never authorizes delegation; that stays gated behind a real verifier.
            $this->assertFalse($spec['delegation_authorized']);
        }
    }

    public function testTheoremsAndObligationsGeneraliseToADifferentInvariantSet(): void
    {
        // A wholly different set the well-formed fixture never contains: outputs must
        // track the inputs, proving the builder computes rather than canning.
        $spec = $this->builder->build([
            ['invariant_id' => 'gate_integrity', 'statement' => 'No promotion bypasses its gate'],
            ['invariant_id' => 'canonical_doc_authority'],
        ]);

        $this->assertSame(
            ['theorem.gate_integrity', 'theorem.canonical_doc_authority'],
            $spec['theorem_ids'],
        );
        $this->assertSame(['gate_integrity', 'canonical_doc_authority'], $spec['invariant_ids']);
        $this->assertSame(2, $spec['theorem_count']);
        $this->assertSame(2, $spec['required_theorem_coverage']);

        // Statement-bearing invariant folds its claim in; the bare one falls back.
        $this->assertSame(
            'No reachable system action may violate: No promotion bypasses its gate',
            $spec['proof_obligations'][0]['statement'],
        );
        $this->assertSame(
            'No reachable system action may violate invariant canonical_doc_authority',
            $spec['proof_obligations'][1]['statement'],
        );

        $this->assertSame('design_spec_ready', $spec['status']);
    }

    public function testInvariantIdsAreNormalisedDeduplicatedAndPlainStringsAccepted(): void
    {
        $spec = $this->builder->build([
            ['invariant_id' => 'Merge Truth!!'],   // normalises to merge_truth
            'scope_guard',                          // plain string entry
            ['invariant_id' => 'merge_truth'],      // duplicate of the first -> dropped
        ]);

        $this->assertSame(['theorem.merge_truth', 'theorem.scope_guard'], $spec['theorem_ids']);
        $this->assertSame(2, $spec['theorem_count']);
        // The first-seen original id is preserved (not the later duplicate's spelling).
        $this->assertSame('Merge Truth!!', $spec['proof_obligations'][0]['invariant_id']);
    }

    public function testTheoremIdsHonourListOfStringContractEvenForNumericInvariantIds(): void
    {
        // A numeric-looking id would coerce to an int array key internally; the
        // contract must still come back as a sequential list<string>.
        $spec = $this->builder->build([
            ['invariant_id' => '42'],
            ['invariant_id' => 'merge_truth'],
        ]);

        $this->assertTrue(array_is_list($spec['theorem_ids']));
        $this->assertTrue(array_is_list($spec['invariant_ids']));
        foreach ($spec['theorem_ids'] as $theoremId) {
            $this->assertIsString($theoremId);
        }
        $this->assertSame(['theorem.42', 'theorem.merge_truth'], $spec['theorem_ids']);
        $this->assertSame(['42', 'merge_truth'], $spec['invariant_ids']);
    }

    public function testSpecIdIsDeterministicContentAddressedAndOrderIndependent(): void
    {
        $invariants = $this->engineeringInvariants();

        $first = $this->builder->build($invariants);
        $second = $this->builder->build($invariants);

        // Same input -> same spec_id (pure, no clock/randomness).
        $this->assertSame($first['spec_id'], $second['spec_id']);
        $this->assertSame('l9proof_', substr($first['spec_id'], 0, 8));
        $this->assertSame(24, strlen($first['spec_id']));

        // Reordering the same set yields the same id (order-independent identity).
        $reordered = [$invariants[2], $invariants[0], $invariants[1]];
        $this->assertSame($first['spec_id'], $this->builder->build($reordered)['spec_id']);

        // A different invariant set yields a different id (generalises, not canned).
        $different = $this->builder->build([['invariant_id' => 'gate_integrity']]);
        $this->assertNotSame($first['spec_id'], $different['spec_id']);
    }
}
