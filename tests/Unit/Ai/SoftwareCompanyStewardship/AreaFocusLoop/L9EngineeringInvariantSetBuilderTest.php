<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9EngineeringInvariantSetBuilder;
use PHPUnit\Framework\TestCase;

final class L9EngineeringInvariantSetBuilderTest extends TestCase
{
    private L9EngineeringInvariantSetBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new L9EngineeringInvariantSetBuilder();
    }

    /**
     * @return list<string>
     */
    private function fullSacredSet(): array
    {
        return [
            'merge_truth',
            'scope_truth',
            'sensitive_class_containment',
            'sacred_gate_integrity',
            'provider_claim_truth',
            'canonical_doc_authority',
        ];
    }

    public function testBuildReturnsEngineeringOnlyInvariantSetThatIsImmovable(): void
    {
        $result = $this->builder->build([
            'requested_invariants' => $this->fullSacredSet(),
        ]);

        $this->assertSame('atlas.aaeos.l9.engineering_invariant_set.v1', $result['schema_version']);
        $this->assertTrue($result['built']);
        $this->assertSame('engineering_only', $result['scope']);
        $this->assertTrue($result['immovable']);
        $this->assertSame($this->fullSacredSet(), $result['invariant_ids']);
        $this->assertSame([], $result['rejected_invariants']);
        $this->assertSame([], $result['blockers']);
    }

    public function testRequiredEvidenceTypesAreComputedFromAdmittedInvariants(): void
    {
        $result = $this->builder->build([
            'requested_invariants' => $this->fullSacredSet(),
        ]);

        $this->assertSame([
            'merge_ref_diff',
            'governed_base_merge',
            'changed_file_set',
            'scope_declaration',
            'data_class_label',
            'local_first_route',
            'gate_signature',
            'invariant_lock_receipt',
            'provider_call_receipt',
            'attribution_ledger',
            'canonical_doc_ref',
            'authoring_source_proof',
        ], $result['required_evidence_types']);
    }

    public function testMissingMergeTruthInvariantBlocks(): void
    {
        $withoutMerge = array_values(array_filter(
            $this->fullSacredSet(),
            static fn (string $id): bool => $id !== 'merge_truth',
        ));

        $result = $this->builder->build([
            'requested_invariants' => $withoutMerge,
        ]);

        $this->assertFalse($result['built']);
        $this->assertContains('merge_truth_invariant_missing', $result['blockers']);
        $this->assertNotContains('merge_truth', $result['invariant_ids']);
    }

    public function testMissingProviderClaimTruthInvariantBlocks(): void
    {
        $withoutProvider = array_values(array_filter(
            $this->fullSacredSet(),
            static fn (string $id): bool => $id !== 'provider_claim_truth',
        ));

        $result = $this->builder->build([
            'requested_invariants' => $withoutProvider,
        ]);

        $this->assertFalse($result['built']);
        $this->assertContains('provider_claim_truth_invariant_missing', $result['blockers']);
        $this->assertNotContains('provider_claim_truth', $result['invariant_ids']);
    }

    public function testNonEngineeringInvariantByUnknownIdIsRejected(): void
    {
        $requested = $this->fullSacredSet();
        $requested[] = 'marketing_campaign_truth';

        $result = $this->builder->build([
            'requested_invariants' => $requested,
        ]);

        $this->assertSame(['marketing_campaign_truth'], $result['rejected_invariants']);
        $this->assertNotContains('marketing_campaign_truth', $result['invariant_ids']);
        $this->assertContains('non_engineering_invariant_rejected', $result['blockers']);
        $this->assertFalse($result['built']);
        // The sacred engineering ids still come through, unpolluted.
        $this->assertSame($this->fullSacredSet(), $result['invariant_ids']);
    }

    public function testEngineeringIdDeclaredWithNonEngineeringScopeIsRejected(): void
    {
        $requested = [
            ['id' => 'merge_truth', 'scope' => 'engineering_only'],
            ['id' => 'scope_truth', 'scope' => 'engineering_only'],
            ['id' => 'sensitive_class_containment', 'scope' => 'engineering_only'],
            ['id' => 'sacred_gate_integrity', 'scope' => 'engineering_only'],
            // Same canonical id, but asserted under a non-engineering scope: reject it.
            ['id' => 'provider_claim_truth', 'scope' => 'marketing'],
            ['id' => 'canonical_doc_authority', 'scope' => 'engineering_only'],
        ];

        $result = $this->builder->build([
            'requested_invariants' => $requested,
        ]);

        $this->assertSame(['provider_claim_truth'], $result['rejected_invariants']);
        $this->assertNotContains('provider_claim_truth', $result['invariant_ids']);
        // Rejecting it under bad scope also trips the required-invariant floor.
        $this->assertContains('provider_claim_truth_invariant_missing', $result['blockers']);
        $this->assertContains('non_engineering_invariant_rejected', $result['blockers']);
        $this->assertFalse($result['built']);
    }

    public function testSacredIdPoisonedByNonEngineeringScopeIsNeverAdmittedEvenWithCleanDuplicate(): void
    {
        // A hostile/contradictory declaration: the same sacred id is requested
        // once under the valid engineering scope and once under a non-engineering
        // scope. The poisoned declaration must win — the id is REJECTED and never
        // admitted, so it can never be laundered into the trusted set by the
        // parallel clean record. It must also trip the required-invariant floor.
        $requested = [
            ['id' => 'merge_truth', 'scope' => 'engineering_only'],
            ['id' => 'merge_truth', 'scope' => 'marketing'],
            ['id' => 'provider_claim_truth', 'scope' => 'engineering_only'],
        ];

        $result = $this->builder->build([
            'requested_invariants' => $requested,
        ]);

        $this->assertNotContains('merge_truth', $result['invariant_ids']);
        $this->assertContains('merge_truth', $result['rejected_invariants']);
        // An id is never simultaneously admitted and rejected.
        $this->assertSame([], array_values(array_intersect(
            $result['invariant_ids'],
            $result['rejected_invariants'],
        )));
        $this->assertContains('merge_truth_invariant_missing', $result['blockers']);
        $this->assertContains('non_engineering_invariant_rejected', $result['blockers']);
        $this->assertFalse($result['built']);
        // The order in which the clean vs poisoned record appears is irrelevant.
        $reversed = [
            ['id' => 'merge_truth', 'scope' => 'marketing'],
            ['id' => 'merge_truth', 'scope' => 'engineering_only'],
            ['id' => 'provider_claim_truth', 'scope' => 'engineering_only'],
        ];
        $this->assertSame(
            $result['invariant_ids'],
            $this->builder->build(['requested_invariants' => $reversed])['invariant_ids'],
        );
    }

    public function testInvariantIdsKeepCanonicalOrderRegardlessOfInputOrder(): void
    {
        $shuffled = [
            'canonical_doc_authority',
            'provider_claim_truth',
            'merge_truth',
            'sacred_gate_integrity',
            'scope_truth',
            'sensitive_class_containment',
        ];

        $result = $this->builder->build([
            'requested_invariants' => $shuffled,
        ]);

        // Output is the canonical sacred-set order, NOT the input order.
        $this->assertSame($this->fullSacredSet(), $result['invariant_ids']);
        $this->assertNotSame($shuffled, $result['invariant_ids']);

        // invariant_ids is a clean list<string> (sequential int keys, no coercion gaps).
        $this->assertSame(
            range(0, count($result['invariant_ids']) - 1),
            array_keys($result['invariant_ids']),
        );
    }

    public function testDuplicateRequestedInvariantsAreCollapsedOnce(): void
    {
        $result = $this->builder->build([
            'requested_invariants' => [
                'merge_truth',
                'merge_truth',
                'provider_claim_truth',
                'scope_truth',
                'scope_truth',
            ],
        ]);

        $this->assertSame(
            ['merge_truth', 'scope_truth', 'provider_claim_truth'],
            $result['invariant_ids'],
        );
        $this->assertSame([], $result['rejected_invariants']);
    }

    public function testEmptyInputBlocksOnBothRequiredInvariants(): void
    {
        $result = $this->builder->build([]);

        $this->assertFalse($result['built']);
        $this->assertSame([], $result['invariant_ids']);
        $this->assertSame([], $result['required_evidence_types']);
        $this->assertSame(
            ['merge_truth_invariant_missing', 'provider_claim_truth_invariant_missing'],
            $result['blockers'],
        );
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $inputs = ['requested_invariants' => $this->fullSacredSet()];

        $first = $this->builder->build($inputs);
        $second = $this->builder->build($inputs);

        $this->assertSame($first, $second);
    }
}
