<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10ConvergenceProofResultVerifier;
use PHPUnit\Framework\TestCase;

final class L10ConvergenceProofResultVerifierTest extends TestCase
{
    private L10ConvergenceProofResultVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new L10ConvergenceProofResultVerifier();
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function fullyVerifiableEnvelope(): array
    {
        $proofSpec = [
            'theorem_ids' => ['thm_convergence_bound', 'thm_no_invariant_drift'],
            'recursion_depth_model' => ['max_depth' => 5],
            'covered_invariant_ids' => ['inv_p5_self_deception'],
            'proof_obligations' => [
                ['invariant_id' => 'inv_q2_proven'],
                ['invariant_id' => 'inv_no_divergence'],
            ],
        ];

        $result = [
            'theorem_id' => 'thm_convergence_bound',
            'covered_theorem_ids' => ['thm_convergence_bound', 'thm_no_invariant_drift'],
            'proven_invariant_ids' => ['inv_p5_self_deception', 'inv_q2_proven', 'inv_no_divergence'],
            'proven_depth' => 4,
            'artifact_hash' => 'sha256:9f1b',
            'verifier_id' => 'verifier.lean.local',
            'reproduction_count' => 3,
            'reproduction_digests_match' => true,
        ];

        return [$result, $proofSpec];
    }

    public function testFullyVerifiableEnvelopePassesWithComputedFields(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertSame('atlas.aaeos.l10.convergence_proof_result_verification.v1', $verification['schema_version']);
        $this->assertTrue($verification['verified']);
        $this->assertSame(['thm_convergence_bound', 'thm_no_invariant_drift'], $verification['covered_theorem_ids']);
        $this->assertSame(4, $verification['max_proven_depth']);
        $this->assertTrue($verification['reproducible']);
        $this->assertSame([], $verification['missing_coverage']);
        $this->assertSame([], $verification['blockers']);
    }

    public function testReturnEnvelopeExposesEveryContractField(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertArrayHasKey('verified', $verification);
        $this->assertArrayHasKey('covered_theorem_ids', $verification);
        $this->assertArrayHasKey('max_proven_depth', $verification);
        $this->assertArrayHasKey('reproducible', $verification);
        $this->assertArrayHasKey('missing_coverage', $verification);
        $this->assertArrayHasKey('blockers', $verification);

        $this->assertIsBool($verification['verified']);
        $this->assertIsArray($verification['covered_theorem_ids']);
        $this->assertIsInt($verification['max_proven_depth']);
        $this->assertIsBool($verification['reproducible']);
        $this->assertIsArray($verification['missing_coverage']);
        $this->assertIsArray($verification['blockers']);
    }

    public function testMissingArtifactHashRejects(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();
        unset($result['artifact_hash']);

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertFalse($verification['verified']);
        $this->assertContains('artifact_hash_missing', $verification['blockers']);
    }

    public function testBlankArtifactHashRejects(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();
        $result['artifact_hash'] = '   ';

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertFalse($verification['verified']);
        $this->assertContains('artifact_hash_missing', $verification['blockers']);
    }

    public function testProofBeyondCoveredInvariantsRejects(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();
        $result['proven_invariant_ids'][] = 'inv_outside_spec';

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertFalse($verification['verified']);
        $this->assertContains('proof_beyond_covered_invariants', $verification['blockers']);
    }

    public function testProofWithinCoveredInvariantsDoesNotFlagOverreach(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();
        $result['proven_invariant_ids'] = ['inv_q2_proven'];

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertTrue($verification['verified']);
        $this->assertNotContains('proof_beyond_covered_invariants', $verification['blockers']);
    }

    public function testMissingVerifierIdentityMeansUnverifiableNeverPasses(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();
        unset($result['verifier_id']);

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertFalse($verification['verified']);
        $this->assertContains('verifier_identity_missing', $verification['blockers']);
    }

    public function testProseFlagWithoutReproductionNeverPasses(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();
        $result['reproduction_count'] = 1;
        $result['reproduction_digests_match'] = true;

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertFalse($verification['reproducible']);
        $this->assertFalse($verification['verified']);
        $this->assertContains('not_reproducible', $verification['blockers']);
    }

    public function testDivergingReproductionDigestsAreNotReproducible(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();
        $result['reproduction_count'] = 4;
        $result['reproduction_digests_match'] = false;

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertFalse($verification['reproducible']);
        $this->assertFalse($verification['verified']);
        $this->assertContains('not_reproducible', $verification['blockers']);
    }

    public function testIncompleteTheoremCoverageComputesMissingAndRejects(): void
    {
        $proofSpec = [
            'theorem_ids' => ['thm_alpha', 'thm_beta', 'thm_gamma'],
            'recursion_depth_model' => ['max_depth' => 3],
            'proof_obligations' => [
                ['invariant_id' => 'inv_a'],
            ],
        ];

        $result = [
            'covered_theorem_ids' => ['thm_alpha'],
            'proven_invariant_ids' => ['inv_a'],
            'proven_depth' => 2,
            'artifact_hash' => 'sha256:dead',
            'verifier_id' => 'verifier.local',
            'reproduction_count' => 2,
            'reproduction_digests_match' => true,
        ];

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertSame(['thm_alpha'], $verification['covered_theorem_ids']);
        $this->assertSame(['thm_beta', 'thm_gamma'], $verification['missing_coverage']);
        $this->assertFalse($verification['verified']);
        $this->assertContains('theorem_coverage_incomplete', $verification['blockers']);
    }

    public function testUnverifiableProofCollapsesMaxProvenDepthToZero(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();
        unset($result['verifier_id']);

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertFalse($verification['verified']);
        $this->assertSame(0, $verification['max_proven_depth']);
    }

    public function testProvenDepthIsClampedToSpecModeledMaxDepth(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();
        $proofSpec['recursion_depth_model'] = ['max_depth' => 2];
        $result['proven_depth'] = 9;

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertTrue($verification['verified']);
        $this->assertSame(2, $verification['max_proven_depth']);
    }

    public function testProvenDepthBelowModelIsKeptVerbatim(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();
        $proofSpec['recursion_depth_model'] = ['max_depth' => 8];
        $result['proven_depth'] = 3;

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertTrue($verification['verified']);
        $this->assertSame(3, $verification['max_proven_depth']);
    }

    public function testNonRepresentableProvenDepthStaysClampedWithoutWarning(): void
    {
        // A proven_depth float at/beyond 2^63 is not representable as an int: a
        // raw (int) cast emits a runtime warning and overflows to a platform-
        // dependent value, breaking purity/determinism. It must saturate to a
        // large int and then clamp to the spec's modeled max_depth like any
        // other oversized claim.
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();
        $proofSpec['recursion_depth_model'] = ['max_depth' => 5];
        $result['proven_depth'] = 1.0e19;

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        });

        try {
            $verification = $this->verifier->verify($result, $proofSpec);
        } finally {
            restore_error_handler();
        }

        // The raw (int) cast on a >=2^63 float emits "not representable as an
        // int" — the guard must suppress that entirely.
        $this->assertSame([], $warnings);
        $this->assertTrue($verification['verified']);
        $this->assertSame(5, $verification['max_proven_depth']);
    }

    public function testNonFiniteProvenDepthCollapsesToZeroBeforeModelClamp(): void
    {
        // INF/NAN carry no proven depth: they collapse to zero, then min() with
        // the modeled max keeps the proven depth at zero (a non-finite claim
        // cannot earn any recursion depth).
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();
        $proofSpec['recursion_depth_model'] = ['max_depth' => 5];
        $result['proven_depth'] = INF;

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertTrue($verification['verified']);
        $this->assertSame(0, $verification['max_proven_depth']);
    }

    public function testCoveredTheoremIdsHonourListStringContractWhenInputUnsortedAndDuplicated(): void
    {
        $proofSpec = [
            'theorem_ids' => ['thm_z', 'thm_a', 'thm_m'],
            'recursion_depth_model' => ['max_depth' => 1],
            'proof_obligations' => [
                ['invariant_id' => 'inv_only'],
            ],
        ];

        $result = [
            'covered_theorem_ids' => ['thm_m', 'thm_a', 'thm_z', 'thm_a'],
            'proven_invariant_ids' => ['inv_only'],
            'proven_depth' => 1,
            'artifact_hash' => 'sha256:abcd',
            'verifier_id' => 'verifier.local',
            'reproduction_count' => 2,
            'reproduction_digests_match' => true,
        ];

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertSame(['thm_a', 'thm_m', 'thm_z'], $verification['covered_theorem_ids']);
        $this->assertSame([], $verification['missing_coverage']);
        $this->assertSame(array_values($verification['covered_theorem_ids']), $verification['covered_theorem_ids']);
        $this->assertContainsOnlyString($verification['covered_theorem_ids']);
        $this->assertTrue($verification['verified']);
    }

    public function testMultipleFailuresAccumulateOrderedBlockers(): void
    {
        $proofSpec = [
            'theorem_ids' => ['thm_one', 'thm_two'],
            'recursion_depth_model' => ['max_depth' => 4],
            'covered_invariant_ids' => ['inv_allowed'],
            'proof_obligations' => [],
        ];

        $result = [
            'covered_theorem_ids' => ['thm_one'],
            'proven_invariant_ids' => ['inv_allowed', 'inv_forbidden'],
            'proven_depth' => 3,
            'verifier_id' => '',
            'reproduction_count' => 0,
            'reproduction_digests_match' => false,
        ];

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertFalse($verification['verified']);
        $this->assertSame(
            [
                'artifact_hash_missing',
                'theorem_coverage_incomplete',
                'verifier_identity_missing',
                'not_reproducible',
                'proof_beyond_covered_invariants',
            ],
            $verification['blockers'],
        );
        $this->assertSame(['thm_two'], $verification['missing_coverage']);
        $this->assertSame(0, $verification['max_proven_depth']);
    }

    public function testEmptySpecCannotCoverAnyTheoremSoNeverPasses(): void
    {
        $result = [
            'covered_theorem_ids' => ['thm_anything'],
            'proven_invariant_ids' => [],
            'proven_depth' => 5,
            'artifact_hash' => 'sha256:ffff',
            'verifier_id' => 'verifier.local',
            'reproduction_count' => 5,
            'reproduction_digests_match' => true,
        ];

        $verification = $this->verifier->verify($result, []);

        $this->assertFalse($verification['verified']);
        $this->assertSame([], $verification['covered_theorem_ids']);
        $this->assertContains('theorem_coverage_incomplete', $verification['blockers']);
        $this->assertSame(0, $verification['max_proven_depth']);
    }

    public function testVerificationIsDeterministic(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();

        $first = $this->verifier->verify($result, $proofSpec);
        $second = $this->verifier->verify($result, $proofSpec);

        $this->assertSame($first, $second);
    }

    public function testCoveredTheoremIdsUseStringOrderForNumericLookingIds(): void
    {
        $proofSpec = [
            'theorem_ids' => ['9', '100', '10', '2'],
            'recursion_depth_model' => ['max_depth' => 1],
            'proof_obligations' => [
                ['invariant_id' => 'inv_only'],
            ],
        ];

        $result = [
            'covered_theorem_ids' => ['9', '100', '10', '2'],
            'proven_invariant_ids' => ['inv_only'],
            'proven_depth' => 1,
            'artifact_hash' => 'sha256:abcd',
            'verifier_id' => 'verifier.local',
            'reproduction_count' => 2,
            'reproduction_digests_match' => true,
        ];

        $verification = $this->verifier->verify($result, $proofSpec);

        // list<string> contract: numeric-looking ids order lexicographically,
        // not numerically (a bare SORT_REGULAR sort would give 2,9,10,100).
        $this->assertSame(['10', '100', '2', '9'], $verification['covered_theorem_ids']);
        $this->assertContainsOnlyString($verification['covered_theorem_ids']);
    }

    public function testSortIsStableForNumericallyEqualButDistinctIds(): void
    {
        // '1', '01' and '1.0' are SORT_REGULAR-equal but textually distinct. The
        // same theorem SET presented in two different orders must yield the same
        // ordered covered_theorem_ids, or determinism is broken.
        $orderA = [
            'covered_theorem_ids' => ['1', '01', '1.0', 'alpha', 'beta'],
            'proven_invariant_ids' => [],
            'proven_depth' => 1,
            'artifact_hash' => 'sha256:abcd',
            'verifier_id' => 'verifier.local',
            'reproduction_count' => 2,
            'reproduction_digests_match' => true,
        ];
        $orderB = $orderA;
        $orderB['covered_theorem_ids'] = ['1.0', 'alpha', '01', 'beta', '1'];

        $specA = [
            'theorem_ids' => ['1', '01', '1.0', 'alpha', 'beta'],
            'recursion_depth_model' => ['max_depth' => 3],
            'proof_obligations' => [],
        ];
        $specB = $specA;
        $specB['theorem_ids'] = ['1.0', 'alpha', '01', 'beta', '1'];

        $resultA = $this->verifier->verify($orderA, $specA)['covered_theorem_ids'];
        $resultB = $this->verifier->verify($orderB, $specB)['covered_theorem_ids'];

        $this->assertSame($resultA, $resultB);
        $this->assertSame(['01', '1', '1.0', 'alpha', 'beta'], $resultA);
    }

    public function testMissingCoverageUsesStableStringOrder(): void
    {
        // missing_coverage is also a list<string>: same required SET in two
        // different input orders must produce the same ordered missing list.
        $resultClaim = [
            'covered_theorem_ids' => [],
            'proven_invariant_ids' => [],
            'proven_depth' => 1,
            'artifact_hash' => 'sha256:abcd',
            'verifier_id' => 'verifier.local',
            'reproduction_count' => 2,
            'reproduction_digests_match' => true,
        ];

        $specA = [
            'theorem_ids' => ['1', '01', '1.0', 'b', 'a'],
            'recursion_depth_model' => ['max_depth' => 3],
            'proof_obligations' => [],
        ];
        $specB = $specA;
        $specB['theorem_ids'] = ['1.0', 'a', '01', 'b', '1'];

        $missingA = $this->verifier->verify($resultClaim, $specA)['missing_coverage'];
        $missingB = $this->verifier->verify($resultClaim, $specB)['missing_coverage'];

        $this->assertSame($missingA, $missingB);
        $this->assertSame(['01', '1', '1.0', 'a', 'b'], $missingA);
    }
}
