<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8MetaCompoundingFactorCandidateMiner;
use PHPUnit\Framework\TestCase;

final class L8MetaCompoundingFactorCandidateMinerTest extends TestCase
{
    private L8MetaCompoundingFactorCandidateMiner $miner;

    protected function setUp(): void
    {
        $this->miner = new L8MetaCompoundingFactorCandidateMiner();
    }

    public function testMineReturnsCanonicalSchemaVersion(): void
    {
        $result = $this->miner->mine([
            'windows' => [
                [
                    'signal_id' => 'review_density',
                    'observations' => [0.1, 0.2, 0.3, 0.4],
                    'dm_dt' => [0.01, 0.02, 0.03, 0.04],
                ],
            ],
        ]);

        $this->assertSame(
            'atlas.aaeos.l8.meta_compounding.factor_candidates.v1',
            $result['schema_version']
        );
    }

    public function testCandidateIncludesFactorIdSourceRefsAndCorrelationHint(): void
    {
        $result = $this->miner->mine([
            'windows' => [
                [
                    'signal_id' => 'review_density',
                    'observations' => [0.1, 0.2, 0.3, 0.4],
                    'dm_dt' => [0.01, 0.02, 0.03, 0.04],
                    'source_refs' => ['evidence:cycle/42', 'evidence:cycle/43'],
                ],
            ],
        ]);

        $this->assertFalse($result['insufficient_evidence']);
        $this->assertCount(1, $result['candidates']);

        $candidate = $result['candidates'][0];

        $this->assertSame('review_density', $candidate['factor_id']);
        $this->assertSame(['evidence:cycle/42', 'evidence:cycle/43'], $candidate['source_refs']);
        $this->assertEqualsWithDelta(1.0, $candidate['correlation_hint'], 0.0001);
        $this->assertSame(4, $candidate['sample_count']);
        $this->assertFalse($candidate['duplicate_of_current']);
    }

    public function testNoEvidenceReturnsEmptyCandidatesWithInsufficientEvidenceTrue(): void
    {
        $result = $this->miner->mine([]);

        $this->assertSame([], $result['candidates']);
        $this->assertTrue($result['insufficient_evidence']);
        $this->assertSame(0, $result['window_count']);
    }

    public function testDuplicateCurrentFactorIsRejected(): void
    {
        $result = $this->miner->mine([
            'current_factors' => ['governed_memory', 'evidence'],
            'windows' => [
                [
                    'signal_id' => 'evidence',
                    'observations' => [0.1, 0.2, 0.3, 0.4],
                    'dm_dt' => [0.01, 0.02, 0.03, 0.04],
                ],
                [
                    'signal_id' => 'review_density',
                    'observations' => [0.1, 0.2, 0.3, 0.4],
                    'dm_dt' => [0.02, 0.04, 0.06, 0.08],
                ],
            ],
        ]);

        $factorIds = array_column($result['candidates'], 'factor_id');

        $this->assertNotContains('evidence', $factorIds);
        $this->assertContains('review_density', $factorIds);
        $this->assertSame(['evidence'], $result['rejected_duplicates']);
    }

    public function testUncorrelatedWindowDiscoversNoFactorFromNoise(): void
    {
        // Orthogonal series: real measured window, enough samples, but the
        // signal carries no measurable correlation with dM/dt -> Pearson 0.0,
        // below the floor -> no candidate is imagined into existence.
        $result = $this->miner->mine([
            'windows' => [
                [
                    'signal_id' => 'noise_channel',
                    'observations' => [0.0, 1.0, 0.0, 1.0],
                    'dm_dt' => [0.0, 0.0, 1.0, 1.0],
                ],
            ],
        ]);

        $this->assertSame([], $result['candidates']);
        $this->assertTrue($result['insufficient_evidence']);
        $this->assertSame(1, $result['window_count']);
    }

    public function testNegativeCorrelationCandidateCarriesSignedHintWithinBounds(): void
    {
        $result = $this->miner->mine([
            'windows' => [
                [
                    'signal_id' => 'context_loss_rate',
                    'observations' => [0.4, 0.3, 0.2, 0.1],
                    'dm_dt' => [0.01, 0.02, 0.03, 0.04],
                ],
            ],
        ]);

        $this->assertCount(1, $result['candidates']);

        $hint = $result['candidates'][0]['correlation_hint'];

        $this->assertEqualsWithDelta(-1.0, $hint, 0.0001);
        $this->assertGreaterThanOrEqual(-1.0, $hint);
        $this->assertLessThanOrEqual(1.0, $hint);
    }

    public function testInsufficientSamplesYieldNoCandidate(): void
    {
        $result = $this->miner->mine([
            'windows' => [
                [
                    'signal_id' => 'too_short',
                    'observations' => [0.1, 0.2],
                    'dm_dt' => [0.01, 0.02],
                ],
            ],
        ]);

        $this->assertSame([], $result['candidates']);
        $this->assertTrue($result['insufficient_evidence']);
    }

    public function testMiningNeverAddsToEquationAndDefaultsToCanonicalCurrentFactors(): void
    {
        $result = $this->miner->mine([
            'windows' => [
                [
                    'signal_id' => 'sovereignty',
                    'observations' => [1.0, 2.0, 3.0, 4.0],
                    'dm_dt' => [2.0, 4.0, 6.0, 8.0],
                ],
            ],
        ]);

        // 'sovereignty' is a canonical current factor; with no explicit
        // current_factors the canonical set is used and it is rejected.
        $this->assertFalse($result['adds_to_equation']);
        $this->assertContains('sovereignty', $result['current_factors']);
        $this->assertContains('sovereignty', $result['rejected_duplicates']);
        $this->assertSame([], $result['candidates']);
    }

    public function testCandidatesAreSortedDeterministically(): void
    {
        $evidence = [
            'current_factors' => ['gates'],
            'windows' => [
                [
                    'signal_id' => 'zeta_signal',
                    'observations' => [0.1, 0.2, 0.3, 0.4],
                    'dm_dt' => [0.01, 0.02, 0.03, 0.04],
                ],
                [
                    'signal_id' => 'alpha_signal',
                    'observations' => [1.0, 2.0, 3.0, 4.0],
                    'dm_dt' => [4.0, 3.0, 2.0, 1.0],
                ],
            ],
        ];

        $first = $this->miner->mine($evidence);
        $second = $this->miner->mine($evidence);

        $this->assertSame($first, $second);
        $this->assertSame(
            ['alpha_signal', 'zeta_signal'],
            array_column($first['candidates'], 'factor_id')
        );
    }

    public function testRejectedDuplicatesAreStringSortedNotNumericallyCoerced(): void
    {
        // factor ids are slugs and may be purely numeric (slug keeps digits).
        // rejected_duplicates must sort as strings (matching the candidate list's
        // strcmp ordering), never coerce numeric-string ids to a numeric order.
        $result = $this->miner->mine([
            'current_factors' => ['100', '99', '20'],
            'windows' => [
                ['signal_id' => '100', 'observations' => [0.1, 0.2, 0.3, 0.4], 'dm_dt' => [1.0, 2.0, 3.0, 4.0]],
                ['signal_id' => '99', 'observations' => [0.1, 0.2, 0.3, 0.4], 'dm_dt' => [1.0, 2.0, 3.0, 4.0]],
                ['signal_id' => '20', 'observations' => [0.1, 0.2, 0.3, 0.4], 'dm_dt' => [1.0, 2.0, 3.0, 4.0]],
            ],
        ]);

        // String order: "100" < "20" < "99". Numeric coercion would give 20,99,100.
        $this->assertSame(['100', '20', '99'], $result['rejected_duplicates']);
        $this->assertSame([], $result['candidates']);
    }

    public function testOverflowMagnitudeWindowNeverFabricatesACandidate(): void
    {
        // Huge magnitudes drive the squared deltas to +INF, so the Pearson
        // denominator is non-finite and the raw ratio is NAN. The [-1,1] clamp
        // cannot tame NAN (max/min propagate it), so without an explicit non-finite
        // guard this window would either pass the correlation floor with a NAN hint
        // (out of [-1,1]) or a spurious 1.0 — fabricating a factor from overflow
        // garbage, violating "discover from measured evidence, not imagination".
        $huge = 1e200;

        $result = $this->miner->mine([
            'windows' => [
                [
                    'signal_id' => 'overflow_channel',
                    'observations' => [$huge, -$huge, $huge, -$huge],
                    'dm_dt' => [$huge, -$huge, $huge, -$huge],
                ],
                // A genuinely correlated window in the same batch must still survive.
                [
                    'signal_id' => 'real_channel',
                    'observations' => [0.1, 0.2, 0.3, 0.4],
                    'dm_dt' => [1.0, 2.0, 3.0, 4.0],
                ],
            ],
        ]);

        $factorIds = array_column($result['candidates'], 'factor_id');
        $this->assertNotContains('overflow_channel', $factorIds);
        $this->assertContains('real_channel', $factorIds);

        foreach ($result['candidates'] as $candidate) {
            $this->assertIsFloat($candidate['correlation_hint']);
            $this->assertFalse(is_nan($candidate['correlation_hint']));
            $this->assertGreaterThanOrEqual(-1.0, $candidate['correlation_hint']);
            $this->assertLessThanOrEqual(1.0, $candidate['correlation_hint']);
        }
    }

    public function testSourceRefsFallBackToWindowReferenceWhenAbsent(): void
    {
        $result = $this->miner->mine([
            'windows' => [
                [
                    'signal_id' => 'merge_latency',
                    'observations' => [0.1, 0.2, 0.3, 0.4],
                    'dm_dt' => [0.01, 0.02, 0.03, 0.04],
                ],
            ],
        ]);

        $this->assertSame(
            ['evidence_window:merge_latency'],
            $result['candidates'][0]['source_refs']
        );
    }
}
