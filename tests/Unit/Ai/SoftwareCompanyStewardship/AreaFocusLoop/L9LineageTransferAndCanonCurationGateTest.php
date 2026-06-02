<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9LineageTransferAndCanonCurationGate;
use PHPUnit\Framework\TestCase;

final class L9LineageTransferAndCanonCurationGateTest extends TestCase
{
    private L9LineageTransferAndCanonCurationGate $gate;

    protected function setUp(): void
    {
        $this->gate = new L9LineageTransferAndCanonCurationGate();
    }

    /**
     * @return array{transfer: array<string, mixed>, validation: array<string, mixed>, operatorDecision: array<string, mixed>}
     */
    private function admissibleInputs(): array
    {
        return [
            'transfer' => [
                'lineage_id' => 'lineage-fast-merge-train',
                'transfer_id' => 'transfer-001',
                'within_q2_boundary' => true,
            ],
            'validation' => [
                'validated' => true,
                'retained_delta' => 0.18,
                'reverted' => false,
            ],
            'operatorDecision' => [
                'operator_signed' => true,
                'approved' => true,
            ],
        ];
    }

    public function testAdmittedTransferReturnsTheFullContract(): void
    {
        $inputs = $this->admissibleInputs();

        $result = $this->gate->admit(
            $inputs['transfer'],
            $inputs['validation'],
            $inputs['operatorDecision'],
        );

        // Acceptance: admit(...) returns admitted, canon_change_proposal,
        // required_signatures and blockers.
        $this->assertSame('atlas.aaeos.l9.lineage_transfer_canon_curation.v1', $result['schema_version']);
        $this->assertTrue($result['admitted']);
        $this->assertSame([], $result['blockers']);

        // canon_change_proposal is a real proposal when admitted.
        $this->assertIsArray($result['canon_change_proposal']);
        $this->assertSame('lineage-fast-merge-train', $result['canon_change_proposal']['lineage_id']);
        $this->assertSame('transfer-001', $result['canon_change_proposal']['transfer_id']);
        $this->assertSame('aaeos_engineering', $result['canon_change_proposal']['scope']);
        $this->assertSame('proposed', $result['canon_change_proposal']['status']);
        $this->assertSame(
            'canon_'.substr(hash('sha256', 'l9_canon_change|lineage_fast_merge_train|transfer_001'), 0, 16),
            $result['canon_change_proposal']['proposal_id'],
        );

        // required_signatures: the operator curation signature is always required.
        $this->assertSame(['operator_canon_curation'], $result['required_signatures']);

        $this->assertTrue($result['operator_curated']);
        $this->assertTrue($result['outcome_validated']);
        $this->assertTrue($result['within_q2_boundary']);
    }

    public function testNoOperatorCurationRejects(): void
    {
        $inputs = $this->admissibleInputs();

        $result = $this->gate->admit(
            $inputs['transfer'],
            $inputs['validation'],
            // The operator did not curate this transfer (no approval).
            ['operator_signed' => true, 'approved' => false],
        );

        $this->assertFalse($result['admitted']);
        $this->assertFalse($result['operator_curated']);
        $this->assertContains('no_operator_curation', $result['blockers']);
        // Un-admitted transfer never produces a canon change proposal.
        $this->assertNull($result['canon_change_proposal']);
        // The operator curation signature is still required even when rejected.
        $this->assertSame(['operator_canon_curation'], $result['required_signatures']);
    }

    public function testMissingOperatorDecisionRejects(): void
    {
        $inputs = $this->admissibleInputs();

        $result = $this->gate->admit(
            $inputs['transfer'],
            $inputs['validation'],
            // No operator decision at all.
            [],
        );

        $this->assertFalse($result['admitted']);
        $this->assertFalse($result['operator_curated']);
        $this->assertSame(['no_operator_curation'], $result['blockers']);
        $this->assertNull($result['canon_change_proposal']);
    }

    public function testNonOperatorDecisionDoesNotCurateTheCanon(): void
    {
        $inputs = $this->admissibleInputs();

        $result = $this->gate->admit(
            $inputs['transfer'],
            $inputs['validation'],
            // A provider approving its own transfer is not operator curation.
            ['actor' => 'provider', 'approved' => true],
        );

        $this->assertFalse($result['admitted']);
        $this->assertFalse($result['operator_curated']);
        $this->assertContains('no_operator_curation', $result['blockers']);
    }

    public function testNoValidatedOutcomeRejects(): void
    {
        $inputs = $this->admissibleInputs();

        $result = $this->gate->admit(
            $inputs['transfer'],
            // No validated outcome on the transferred evolution.
            ['validated' => false, 'retained_delta' => 0.0],
            $inputs['operatorDecision'],
        );

        $this->assertFalse($result['admitted']);
        $this->assertFalse($result['outcome_validated']);
        $this->assertSame(['no_validated_outcome'], $result['blockers']);
        $this->assertNull($result['canon_change_proposal']);
    }

    public function testRevertedOutcomeIsNotValidated(): void
    {
        $inputs = $this->admissibleInputs();

        $result = $this->gate->admit(
            $inputs['transfer'],
            // A reverted outcome (measured-or-reverted) does not survive.
            ['validated' => true, 'reverted' => true],
            $inputs['operatorDecision'],
        );

        $this->assertFalse($result['admitted']);
        $this->assertFalse($result['outcome_validated']);
        $this->assertContains('no_validated_outcome', $result['blockers']);
    }

    public function testOutsideQ2BoundaryRejects(): void
    {
        $inputs = $this->admissibleInputs();

        $result = $this->gate->admit(
            // The transfer is explicitly outside the proven Q2 boundary.
            [
                'lineage_id' => 'lineage-fast-merge-train',
                'transfer_id' => 'transfer-001',
                'within_q2_boundary' => false,
            ],
            $inputs['validation'],
            $inputs['operatorDecision'],
        );

        $this->assertFalse($result['admitted']);
        $this->assertFalse($result['within_q2_boundary']);
        $this->assertSame(['outside_q2_boundary'], $result['blockers']);
        $this->assertNull($result['canon_change_proposal']);
    }

    public function testMissingQ2BoundaryRejects(): void
    {
        $inputs = $this->admissibleInputs();

        $result = $this->gate->admit(
            // No Q2 boundary anchor or flag at all.
            ['lineage_id' => 'lineage-x', 'transfer_id' => 'transfer-x'],
            $inputs['validation'],
            $inputs['operatorDecision'],
        );

        $this->assertFalse($result['admitted']);
        $this->assertFalse($result['within_q2_boundary']);
        $this->assertContains('outside_q2_boundary', $result['blockers']);
    }

    public function testAllThreeFailuresAccumulateInCanonicalOrder(): void
    {
        $result = $this->gate->admit(
            // Outside Q2 boundary.
            ['lineage_id' => 'lineage-y', 'within_q2_boundary' => false],
            // No validated outcome.
            ['validated' => false],
            // No operator curation.
            [],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame(
            ['no_operator_curation', 'no_validated_outcome', 'outside_q2_boundary'],
            $result['blockers'],
        );
        $this->assertNull($result['canon_change_proposal']);
        $this->assertSame(['operator_canon_curation'], $result['required_signatures']);
    }

    public function testGeneralisesToADifferentAdmissibleTransferWithVerdictAndAnchor(): void
    {
        // Inputs deliberately unlike the happy-path fixture: a verdict token
        // instead of an approved flag, a Q2 anchor instead of a flag, and an
        // outcome_validated alias. A canned/keyed implementation would fail.
        $result = $this->gate->admit(
            [
                'lineage_id' => 'lineage-typed-contracts',
                'transfer_id' => 'transfer-typed-77',
                'q2_boundary' => ['q2_boundary_id' => 'q2-proof-991'],
            ],
            ['outcome_validated' => true, 'retained_delta' => 0.42],
            ['actor' => 'operator', 'verdict' => 'approve'],
        );

        $this->assertTrue($result['admitted']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['operator_curated']);
        $this->assertTrue($result['outcome_validated']);
        $this->assertTrue($result['within_q2_boundary']);
        $this->assertIsArray($result['canon_change_proposal']);
        $this->assertSame('lineage-typed-contracts', $result['canon_change_proposal']['lineage_id']);
        $this->assertSame(
            'canon_'.substr(hash('sha256', 'l9_canon_change|lineage_typed_contracts|transfer_typed_77'), 0, 16),
            $result['canon_change_proposal']['proposal_id'],
        );
    }

    public function testProposalIdDiffersForDifferentTransfers(): void
    {
        $inputs = $this->admissibleInputs();

        $first = $this->gate->admit(
            ['lineage_id' => 'lineage-a', 'transfer_id' => 'transfer-1', 'within_q2_boundary' => true],
            $inputs['validation'],
            $inputs['operatorDecision'],
        );
        $second = $this->gate->admit(
            ['lineage_id' => 'lineage-b', 'transfer_id' => 'transfer-2', 'within_q2_boundary' => true],
            $inputs['validation'],
            $inputs['operatorDecision'],
        );

        $this->assertIsArray($first['canon_change_proposal']);
        $this->assertIsArray($second['canon_change_proposal']);
        $this->assertNotSame(
            $first['canon_change_proposal']['proposal_id'],
            $second['canon_change_proposal']['proposal_id'],
        );
    }

    public function testRequiredSignaturesIsAlwaysAListOfStrings(): void
    {
        $inputs = $this->admissibleInputs();

        $result = $this->gate->admit(
            $inputs['transfer'],
            $inputs['validation'],
            $inputs['operatorDecision'],
        );

        $this->assertSame(array_values($result['required_signatures']), $result['required_signatures']);
        foreach ($result['required_signatures'] as $signature) {
            $this->assertIsString($signature);
        }
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $inputs = $this->admissibleInputs();

        $first = $this->gate->admit($inputs['transfer'], $inputs['validation'], $inputs['operatorDecision']);
        $second = $this->gate->admit($inputs['transfer'], $inputs['validation'], $inputs['operatorDecision']);

        $this->assertSame($first, $second);
    }
}
