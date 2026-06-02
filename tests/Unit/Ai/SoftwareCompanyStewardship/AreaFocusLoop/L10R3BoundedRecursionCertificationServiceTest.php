<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10R3BoundedRecursionCertificationService;
use PHPUnit\Framework\TestCase;

final class L10R3BoundedRecursionCertificationServiceTest extends TestCase
{
    private L10R3BoundedRecursionCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new L10R3BoundedRecursionCertificationService();
    }

    /**
     * Every composed R3 artefact green, each in its OWN native shape with an
     * explicit evidence ref. Inputs are arrays (not the bool fast-path) so the real
     * branches are exercised end to end: the S149 verifier carries `verified` +
     * `max_proven_depth`, the S150 gate carries `allowed` + `max_allowed_depth`, the
     * S151 detector carries `divergence_detected`/`gaming_detected`.
     *
     * @return array<string,array<string,mixed>>
     */
    private function fullyCertifiedInputs(): array
    {
        return [
            'proof' => ['verified' => true, 'max_proven_depth' => 5, 'evidence_ref' => 'evidence://l10/r3/proof/s149'],
            'depth_gate' => ['allowed' => true, 'max_allowed_depth' => 5, 'evidence_ref' => 'evidence://l10/r3/depth_gate/s150'],
            'divergence' => ['divergence_detected' => false, 'gaming_detected' => false, 'evidence_ref' => 'evidence://l10/r3/divergence/s151'],
        ];
    }

    public function testCertifiesWhenProofDepthGateAndDivergenceAllPass(): void
    {
        $result = $this->service->certify($this->fullyCertifiedInputs());

        // Acceptance: schema + the three composed flags + r3_certified + the proven
        // depth + divergence_free/gaming_free + blockers + evidence_refs.
        $this->assertSame('atlas.aaeos.l10.r3_bounded_recursion_certification.v1', $result['schema_version']);
        $this->assertTrue($result['proof']);
        $this->assertTrue($result['depth_gate']);
        $this->assertTrue($result['divergence']);
        $this->assertTrue($result['r3_certified']);
        $this->assertSame('r3_bounded_recursion', $result['status']);
        $this->assertSame(5, $result['max_proven_depth']);
        $this->assertTrue($result['divergence_free']);
        $this->assertTrue($result['gaming_free']);
        $this->assertFalse($result['runtime_mutation_performed']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(3, $result['checks_passed_count']);
        $this->assertSame(3, $result['check_total']);
        $this->assertSame(
            [
                'evidence://l10/r3/proof/s149',
                'evidence://l10/r3/depth_gate/s150',
                'evidence://l10/r3/divergence/s151',
            ],
            $result['evidence_refs'],
        );
    }

    public function testResultExposesEveryAcceptanceField(): void
    {
        $result = $this->service->certify($this->fullyCertifiedInputs());

        // Acceptance enumerates exactly these returned fields.
        foreach (
            [
                'r3_certified',
                'max_proven_depth',
                'divergence_free',
                'gaming_free',
                'blockers',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function testBoolArtefactsAreAcceptedFastPath(): void
    {
        $result = $this->service->certify([
            'proof' => true,
            'depth_gate' => true,
            'divergence' => true,
        ]);

        $this->assertTrue($result['r3_certified']);
        $this->assertSame('r3_bounded_recursion', $result['status']);
        $this->assertSame(3, $result['checks_passed_count']);
        // Verified proof with no explicit depth and no gate ceiling proves depth 0.
        $this->assertSame(0, $result['max_proven_depth']);
        $this->assertTrue($result['divergence_free']);
        $this->assertTrue($result['gaming_free']);
        // A certified bool artefact with no explicit ref gets a deterministic met marker.
        $this->assertSame(
            [
                'evidence://l10/r3/proof/met',
                'evidence://l10/r3/depth_gate/met',
                'evidence://l10/r3/divergence/met',
            ],
            $result['evidence_refs'],
        );
    }

    public function testMissingProofBlocksEvenWhenDepthGateAndDivergencePass(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // Convergence proof (S149) not verified — R3 has no bound.
        $inputs['proof'] = ['verified' => false, 'max_proven_depth' => 9];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['proof']);
        $this->assertTrue($result['depth_gate']);
        $this->assertTrue($result['divergence']);
        // Acceptance: proof missing blocks.
        $this->assertFalse($result['r3_certified']);
        $this->assertSame('blocked_not_r3', $result['status']);
        $this->assertSame(['convergence_proof_missing'], $result['blockers']);
        $this->assertSame(2, $result['checks_passed_count']);
        // With no verified proof the proven depth collapses to 0, even though the
        // (unverified) proof claimed depth 9 and the gate allows 5.
        $this->assertSame(0, $result['max_proven_depth']);
    }

    public function testDivergenceDetectedBlocksEvenWithProofAndDepthGateGreen(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // The detector (S151) reports divergence in the recursive-improvement evidence.
        $inputs['divergence'] = ['divergence_detected' => true, 'gaming_detected' => false];

        $result = $this->service->certify($inputs);

        $this->assertTrue($result['proof']);
        $this->assertTrue($result['depth_gate']);
        $this->assertFalse($result['divergence']);
        // Acceptance: divergence detected blocks.
        $this->assertFalse($result['r3_certified']);
        $this->assertSame(['divergence_or_gaming_detected'], $result['blockers']);
        // The proven depth still reflects the proven-and-gated bound; the divergence
        // verdict is reported separately.
        $this->assertSame(5, $result['max_proven_depth']);
        $this->assertFalse($result['divergence_free']);
        $this->assertTrue($result['gaming_free']);
    }

    public function testGamingDetectedBlocksAndIsReportedSeparatelyFromDivergence(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // Metric gaming, not divergence: dm/dt up while quality degrades (S151 doctrine).
        $inputs['divergence'] = ['divergence_detected' => false, 'gaming_detected' => true];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['divergence']);
        $this->assertFalse($result['r3_certified']);
        $this->assertSame(['divergence_or_gaming_detected'], $result['blockers']);
        // divergence_free stays true (no divergence), but gaming_free is false.
        $this->assertTrue($result['divergence_free']);
        $this->assertFalse($result['gaming_free']);
    }

    public function testDepthGateHardStopBlocksEvenWhenAllowedFlagIsTrue(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // The gate raised a hard stop: it must close regardless of the allow flag.
        $inputs['depth_gate'] = ['allowed' => true, 'hard_stop' => true, 'max_allowed_depth' => 5];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['depth_gate']);
        $this->assertFalse($result['r3_certified']);
        $this->assertSame(['depth_gate_not_held'], $result['blockers']);
        // A closed depth gate means no bound is held: proven depth is 0.
        $this->assertSame(0, $result['max_proven_depth']);
    }

    public function testDepthGateTruthyNonBoolHardStopStillClosesTheGate(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // The hard stop is a safety VETO, not a pass flag: a truthy-but-non-bool
        // hard_stop (e.g. 1 arriving from a serialized envelope) must still close the
        // gate fail-closed. Reading it as strict === true would fail OPEN here and let
        // R3 certify recursion past a raised depth-limit hard stop.
        $inputs['depth_gate'] = ['allowed' => true, 'hard_stop' => 1, 'max_allowed_depth' => 5];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['depth_gate']);
        $this->assertFalse($result['r3_certified']);
        $this->assertSame(['depth_gate_not_held'], $result['blockers']);
        $this->assertSame(0, $result['max_proven_depth']);

        // An explicit falsey hard_stop is "no hard stop": the gate stays governed by
        // the allow flag and is not forced closed by the veto clause.
        $inputs['depth_gate'] = ['allowed' => true, 'hard_stop' => 0, 'max_allowed_depth' => 5];
        $governed = $this->service->certify($inputs);
        $this->assertTrue($governed['depth_gate']);
        $this->assertTrue($governed['r3_certified']);
    }

    public function testDivergenceTruthyNonBoolVetoFlagsStillBlockFailClosed(): void
    {
        // The detector's divergence_detected / gaming_detected / hard_stop_required
        // are safety VETOES, mirroring the depth gate's hard_stop: a truthy-but-non-bool
        // value (e.g. 1 arriving from a serialized envelope that cast the bool to an
        // int) must still close the divergence check fail-closed. Reading them as
        // strict === true would fail OPEN and let R3 certify recursion even though the
        // S151 detector reported divergence / gaming / a hard stop.
        foreach (['divergence_detected', 'gaming_detected', 'hard_stop_required'] as $vetoFlag) {
            $inputs = $this->fullyCertifiedInputs();
            $inputs['divergence'] = [$vetoFlag => 1];

            $result = $this->service->certify($inputs);

            $this->assertFalse(
                $result['divergence'],
                "Truthy non-bool {$vetoFlag} must close the divergence check",
            );
            $this->assertFalse($result['r3_certified']);
            $this->assertSame(['divergence_or_gaming_detected'], $result['blockers']);
        }

        // A truthy-non-bool divergence_detected must also flip divergence_free off, and
        // a truthy-non-bool gaming_detected must flip gaming_free off — the verdict
        // fields never advertise "free" when the detector raised the signal as 1.
        $diverged = $this->service->certify([
            'proof' => true,
            'depth_gate' => true,
            'divergence' => ['divergence_detected' => 1, 'gaming_detected' => false],
        ]);
        $this->assertFalse($diverged['divergence_free']);
        $this->assertTrue($diverged['gaming_free']);

        $gamed = $this->service->certify([
            'proof' => true,
            'depth_gate' => true,
            'divergence' => ['divergence_detected' => false, 'gaming_detected' => 1],
        ]);
        $this->assertTrue($gamed['divergence_free']);
        $this->assertFalse($gamed['gaming_free']);

        // An explicit falsey veto leaves the check governed by the absence of a signal:
        // a 0 / false divergence_detected is "no divergence", so the evidence certifies.
        $clean = $this->service->certify([
            'proof' => true,
            'depth_gate' => true,
            'divergence' => ['divergence_detected' => 0, 'gaming_detected' => 0],
        ]);
        $this->assertTrue($clean['divergence']);
        $this->assertTrue($clean['r3_certified']);
        $this->assertTrue($clean['divergence_free']);
        $this->assertTrue($clean['gaming_free']);
    }

    public function testDepthGateNotAllowedBlocks(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        $inputs['depth_gate'] = ['allowed' => false, 'max_allowed_depth' => 5];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['depth_gate']);
        $this->assertFalse($result['r3_certified']);
        $this->assertSame(['depth_gate_not_held'], $result['blockers']);
        $this->assertSame(0, $result['max_proven_depth']);
    }

    public function testProvenDepthIsTheMinimumOfProofDepthAndGateCeiling(): void
    {
        // Proof proves depth 8 but the gate only permits 3: the proven, GATED bound
        // is 3 — never above the gate ceiling.
        $result = $this->service->certify([
            'proof' => ['verified' => true, 'max_proven_depth' => 8],
            'depth_gate' => ['allowed' => true, 'max_allowed_depth' => 3],
            'divergence' => ['divergence_detected' => false, 'gaming_detected' => false],
        ]);

        $this->assertTrue($result['r3_certified']);
        $this->assertSame(3, $result['max_proven_depth']);
    }

    public function testProvenDepthNeverExceedsTheProofProvenDepth(): void
    {
        // The gate would permit 12, but the proof only proves convergence to depth 4:
        // the certified bound can never exceed what is PROVEN, so it stays 4.
        $result = $this->service->certify([
            'proof' => ['verified' => true, 'max_proven_depth' => 4],
            'depth_gate' => ['allowed' => true, 'max_allowed_depth' => 12],
            'divergence' => ['divergence_detected' => false, 'gaming_detected' => false],
        ]);

        $this->assertTrue($result['r3_certified']);
        $this->assertSame(4, $result['max_proven_depth']);
    }

    public function testNegativeProofDepthIsFlooredAtZero(): void
    {
        // A nonsensical negative proven depth must never produce a negative bound.
        $result = $this->service->certify([
            'proof' => ['verified' => true, 'max_proven_depth' => -7],
            'depth_gate' => ['allowed' => true, 'max_allowed_depth' => 5],
            'divergence' => ['divergence_detected' => false, 'gaming_detected' => false],
        ]);

        $this->assertTrue($result['r3_certified']);
        $this->assertSame(0, $result['max_proven_depth']);
    }

    public function testRequestedRuntimeMutationIsNeverPerformed(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // An input advertises a requested runtime mutation: R3 is certification, NOT
        // execution, so the mutation is never performed regardless of the verdict.
        $inputs['proof']['requested_runtime_mutation'] = true;
        $inputs['mutation_request'] = ['mutate_runtime' => true, 'apply' => true];

        $certified = $this->service->certify($inputs);

        $this->assertTrue($certified['r3_certified']);
        $this->assertFalse($certified['runtime_mutation_performed']);

        // Even when the certification is BLOCKED, no mutation is ever performed.
        $inputs['divergence'] = ['divergence_detected' => true];
        $blocked = $this->service->certify($inputs);

        $this->assertFalse($blocked['r3_certified']);
        $this->assertFalse($blocked['runtime_mutation_performed']);
    }

    public function testBlockersAreOrderedProofDepthGateDivergenceAcrossMultipleGaps(): void
    {
        // Proof and divergence fail; the depth gate passes. Blocker order is the
        // safety-first walk: proof, then depth_gate, then divergence.
        $result = $this->service->certify([
            'proof' => ['verified' => false],
            'depth_gate' => true,
            'divergence' => ['divergence_detected' => true, 'gaming_detected' => true],
        ]);

        $this->assertSame(
            ['convergence_proof_missing', 'divergence_or_gaming_detected'],
            $result['blockers'],
        );
        $this->assertFalse($result['r3_certified']);
        $this->assertSame(1, $result['checks_passed_count']);
        $this->assertTrue($result['depth_gate']);
        $this->assertSame(0, $result['max_proven_depth']);
    }

    public function testEmptyInputsBlockEveryComposedCheck(): void
    {
        $result = $this->service->certify([]);

        $this->assertFalse($result['proof']);
        $this->assertFalse($result['depth_gate']);
        $this->assertFalse($result['divergence']);
        $this->assertFalse($result['r3_certified']);
        $this->assertSame('blocked_not_r3', $result['status']);
        $this->assertSame(0, $result['checks_passed_count']);
        $this->assertSame(0, $result['max_proven_depth']);
        // Fail-closed: absent divergence evidence is neither divergence-free nor gaming-free.
        $this->assertFalse($result['divergence_free']);
        $this->assertFalse($result['gaming_free']);
        $this->assertSame(
            [
                'convergence_proof_missing',
                'depth_gate_not_held',
                'divergence_or_gaming_detected',
            ],
            $result['blockers'],
        );
        $this->assertSame([], $result['evidence_refs']);
        $this->assertFalse($result['runtime_mutation_performed']);
    }

    public function testUncertifiedCheckCarriesBlockedMarkerAndIsAbsentFromEvidenceRefs(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        $inputs['divergence'] = ['divergence_detected' => true];

        $result = $this->service->certify($inputs);

        $checksByKey = [];
        foreach ($result['checks'] as $check) {
            $checksByKey[$check['key']] = $check;
        }

        $this->assertFalse($checksByKey['divergence']['passed']);
        $this->assertSame('evidence://l10/r3/divergence/blocked', $checksByKey['divergence']['evidence_ref']);
        // The blocked check's marker is never advertised as proof.
        $this->assertNotContains('evidence://l10/r3/divergence/blocked', $result['evidence_refs']);
        // Each composed check reports its originating slice.
        $this->assertSame('S149', $checksByKey['proof']['slice']);
        $this->assertSame('S150', $checksByKey['depth_gate']['slice']);
        $this->assertSame('S151', $checksByKey['divergence']['slice']);
    }

    public function testReadOnlyDeterminismAndNoRecursionExecutionSideEffect(): void
    {
        $inputs = $this->fullyCertifiedInputs();

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);

        // Read-only: identical inputs yield an identical certification (no hidden state).
        $this->assertSame($first, $second);

        // R3 is bounded recursion CERTIFICATION, not recursion execution: the result
        // never advertises a promotion / execution field and the phase stays R3.
        $this->assertFalse($first['runtime_mutation_performed']);
        $this->assertArrayNotHasKey('promoted', $first);
        $this->assertArrayNotHasKey('recursion_executed', $first);
        $this->assertArrayNotHasKey('recursion_allowed', $first);
        $this->assertArrayNotHasKey('next_level', $first);
        $this->assertSame('R3', $first['phase']);
        $this->assertStringNotContainsString('r4', $first['status']);
    }

    public function testBlockersListIsAlwaysAReindexedListEvenWithGaps(): void
    {
        $result = $this->service->certify([
            'proof' => true,
            'depth_gate' => false,
            'divergence' => ['divergence_detected' => true],
        ]);

        $this->assertSame(array_values($result['blockers']), $result['blockers']);
        $this->assertSame(['depth_gate_not_held', 'divergence_or_gaming_detected'], $result['blockers']);
    }

    public function testStringNumericDepthsAreHonouredAndProvenBoundStaysAnInt(): void
    {
        // Depth fields arriving as numeric strings (e.g. from a JSON envelope) are
        // honoured and the proven bound is still a real int min of the two.
        $result = $this->service->certify([
            'proof' => ['verified' => true, 'max_proven_depth' => '6'],
            'depth_gate' => ['allowed' => true, 'max_allowed_depth' => '2'],
            'divergence' => ['divergence_detected' => false, 'gaming_detected' => false],
        ]);

        $this->assertTrue($result['r3_certified']);
        $this->assertSame(2, $result['max_proven_depth']);
    }
}
