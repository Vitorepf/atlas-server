<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9InvariantProofResultVerifier;
use PHPUnit\Framework\TestCase;

final class L9InvariantProofResultVerifierTest extends TestCase
{
    private L9InvariantProofResultVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new L9InvariantProofResultVerifier();
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function fullyVerifiableEnvelope(): array
    {
        $proofSpec = [
            'theorem_ids' => ['thm_merge_truth', 'thm_scope_lock'],
            'proof_obligations' => [
                ['invariant_id' => 'inv_merge_truth'],
                ['invariant_id' => 'inv_scope_lock'],
            ],
        ];

        $result = [
            'theorem_id' => 'thm_merge_truth',
            'covered_invariant_ids' => ['inv_merge_truth', 'inv_scope_lock'],
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

        $this->assertSame('atlas.aaeos.l9.invariant_proof_result_verification.v1', $verification['schema_version']);
        $this->assertTrue($verification['verified']);
        $this->assertSame(['inv_merge_truth', 'inv_scope_lock'], $verification['covered_invariant_ids']);
        $this->assertSame([], $verification['missing_coverage']);
        $this->assertTrue($verification['reproducible']);
        $this->assertSame([], $verification['blockers']);
    }

    public function testReturnEnvelopeExposesEveryContractField(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertArrayHasKey('verified', $verification);
        $this->assertArrayHasKey('covered_invariant_ids', $verification);
        $this->assertArrayHasKey('missing_coverage', $verification);
        $this->assertArrayHasKey('reproducible', $verification);
        $this->assertArrayHasKey('blockers', $verification);

        $this->assertIsBool($verification['verified']);
        $this->assertIsArray($verification['covered_invariant_ids']);
        $this->assertIsArray($verification['missing_coverage']);
        $this->assertIsBool($verification['reproducible']);
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

    public function testTheoremMismatchRejects(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();
        $result['theorem_id'] = 'thm_not_in_spec';

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertFalse($verification['verified']);
        $this->assertContains('theorem_mismatch', $verification['blockers']);
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
        $result['reproduction_count'] = 3;
        $result['reproduction_digests_match'] = false;

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertFalse($verification['reproducible']);
        $this->assertFalse($verification['verified']);
        $this->assertContains('not_reproducible', $verification['blockers']);
    }

    public function testIncompleteInvariantCoverageComputesMissingAndRejects(): void
    {
        $proofSpec = [
            'theorem_ids' => ['thm_alpha'],
            'proof_obligations' => [
                ['invariant_id' => 'inv_a'],
                ['invariant_id' => 'inv_b'],
                ['invariant_id' => 'inv_c'],
            ],
        ];

        $result = [
            'theorem_id' => 'thm_alpha',
            'covered_invariant_ids' => ['inv_a'],
            'artifact_hash' => 'sha256:dead',
            'verifier_id' => 'verifier.local',
            'reproduction_count' => 2,
            'reproduction_digests_match' => true,
        ];

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertSame(['inv_a'], $verification['covered_invariant_ids']);
        $this->assertSame(['inv_b', 'inv_c'], $verification['missing_coverage']);
        $this->assertFalse($verification['verified']);
        $this->assertContains('invariant_coverage_incomplete', $verification['blockers']);
    }

    public function testRequiredCoverageReadFromExplicitListAndObligations(): void
    {
        $proofSpec = [
            'theorem_ids' => ['thm_beta'],
            'required_invariant_ids' => ['inv_provider_claim_truth'],
            'proof_obligations' => [
                ['invariant_id' => 'inv_sensitive_class'],
            ],
        ];

        $result = [
            'theorem_id' => 'thm_beta',
            'covered_invariant_ids' => ['inv_sensitive_class'],
            'artifact_hash' => 'sha256:c0de',
            'verifier_id' => 'verifier.local',
            'reproduction_count' => 2,
            'reproduction_digests_match' => true,
        ];

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertSame(['inv_sensitive_class'], $verification['covered_invariant_ids']);
        $this->assertSame(['inv_provider_claim_truth'], $verification['missing_coverage']);
        $this->assertFalse($verification['verified']);
    }

    public function testCoveredIdsHonourListStringContractWhenInputUnsortedAndDuplicated(): void
    {
        $proofSpec = [
            'theorem_ids' => ['thm_gamma'],
            'proof_obligations' => [
                ['invariant_id' => 'inv_z'],
                ['invariant_id' => 'inv_a'],
                ['invariant_id' => 'inv_m'],
            ],
        ];

        $result = [
            'theorem_id' => 'thm_gamma',
            'covered_invariant_ids' => ['inv_m', 'inv_a', 'inv_z', 'inv_a'],
            'artifact_hash' => 'sha256:abcd',
            'verifier_id' => 'verifier.local',
            'reproduction_count' => 2,
            'reproduction_digests_match' => true,
        ];

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertSame(['inv_a', 'inv_m', 'inv_z'], $verification['covered_invariant_ids']);
        $this->assertSame([], $verification['missing_coverage']);
        $this->assertSame(array_values($verification['covered_invariant_ids']), $verification['covered_invariant_ids']);
        $this->assertContainsOnlyString($verification['covered_invariant_ids']);
        $this->assertTrue($verification['verified']);
    }

    public function testCoverageOrderingIsDeterministicForNumericallyEqualButTextuallyDistinctIds(): void
    {
        // The upstream spec builder accepts numeric invariant ids, so a spec whose
        // obligations carry numerically-equal-but-textually-distinct ids ('1','01',
        // '1.0') is a plausible artifact. The verifier promises pure determinism:
        // the SAME invariant SET, presented in a different obligation order, must
        // yield byte-identical output. Bare sort()/SORT_REGULAR sees these ids as
        // numerically equal and leaves them in input order, so reordering the set
        // would shuffle the list — SORT_STRING is what makes the order set-defined.
        $base = static fn (array $obligationOrder): array => [
            'theorem_ids' => ['thm_num'],
            'proof_obligations' => array_map(
                static fn (string $id): array => ['invariant_id' => $id],
                $obligationOrder,
            ),
        ];

        $result = [
            'theorem_id' => 'thm_num',
            'covered_invariant_ids' => ['1', '01', '1.0'],
            'artifact_hash' => 'sha256:num',
            'verifier_id' => 'verifier.local',
            'reproduction_count' => 2,
            'reproduction_digests_match' => true,
        ];

        $forward = $this->verifier->verify($result, $base(['1', '01', '1.0']));
        $shuffled = $this->verifier->verify($result, $base(['1.0', '1', '01']));

        // Same SET, different obligation order -> identical verification envelope.
        $this->assertSame($forward, $shuffled);
        // Lexicographic (string) ordering, never numeric collapse.
        $this->assertSame(['01', '1', '1.0'], $forward['covered_invariant_ids']);
        $this->assertSame([], $forward['missing_coverage']);
        $this->assertTrue($forward['verified']);
    }

    public function testMultipleFailuresAccumulateOrderedBlockers(): void
    {
        $proofSpec = [
            'theorem_ids' => ['thm_only'],
            'proof_obligations' => [
                ['invariant_id' => 'inv_one'],
                ['invariant_id' => 'inv_two'],
            ],
        ];

        $result = [
            'theorem_id' => 'thm_wrong',
            'covered_invariant_ids' => ['inv_one'],
            'verifier_id' => '',
            'reproduction_count' => 0,
            'reproduction_digests_match' => false,
        ];

        $verification = $this->verifier->verify($result, $proofSpec);

        $this->assertFalse($verification['verified']);
        $this->assertSame(
            [
                'artifact_hash_missing',
                'theorem_mismatch',
                'verifier_identity_missing',
                'not_reproducible',
                'invariant_coverage_incomplete',
            ],
            $verification['blockers'],
        );
        $this->assertSame(['inv_two'], $verification['missing_coverage']);
    }

    public function testEmptySpecCannotMatchTheoremSoNeverPasses(): void
    {
        $result = [
            'theorem_id' => 'thm_anything',
            'covered_invariant_ids' => [],
            'artifact_hash' => 'sha256:ffff',
            'verifier_id' => 'verifier.local',
            'reproduction_count' => 5,
            'reproduction_digests_match' => true,
        ];

        $verification = $this->verifier->verify($result, []);

        $this->assertFalse($verification['verified']);
        $this->assertContains('theorem_mismatch', $verification['blockers']);
    }

    public function testVerificationIsDeterministic(): void
    {
        [$result, $proofSpec] = $this->fullyVerifiableEnvelope();

        $first = $this->verifier->verify($result, $proofSpec);
        $second = $this->verifier->verify($result, $proofSpec);

        $this->assertSame($first, $second);
    }
}
