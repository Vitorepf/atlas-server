<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognitive\ClaimCoherence;

use App\Services\Ai\Cognitive\ClaimCoherence\ClaimSelfCoherenceScorer;
use PHPUnit\Framework\TestCase;

final class ClaimSelfCoherenceScorerTest extends TestCase
{
    private ClaimSelfCoherenceScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new ClaimSelfCoherenceScorer();
    }

    public function testHighConfidenceWithZeroEvidenceDropsBelowCoherentThreshold(): void
    {
        $result = $this->scorer->score('atlas', 'ships features', 'definitely', 0.9, 0);

        $this->assertSame('atlas.cognitive.claim_coherence.self_coherence.v1', $result['schema_version']);
        $this->assertSame(0.5, $result['coherence']);
        $this->assertLessThan(0.7, $result['coherence']);
        $this->assertNotSame('coherent', $result['status']);
        $this->assertContains('confidence_without_evidence', $result['penalties']);
    }

    public function testHedgeWithHighConfidenceAddsDistinctPenaltyAndLowersCoherence(): void
    {
        $hedged = $this->scorer->score('atlas', 'ships features', 'maybe stable', 0.9, 3);
        $plain = $this->scorer->score('atlas', 'ships features', 'definitely stable', 0.9, 3);

        $this->assertContains('hedge_with_high_confidence', $hedged['penalties']);
        $this->assertNotContains('hedge_with_high_confidence', $plain['penalties']);
        $this->assertSame(0.7, $hedged['coherence']);
        $this->assertSame(1.0, $plain['coherence']);
        $this->assertLessThan($plain['coherence'], $hedged['coherence']);
    }

    public function testEmptyPredicateYieldsEmptyTermPenaltyAndNotCoherent(): void
    {
        $result = $this->scorer->score('atlas', '', 'stable', 0.5, 2);

        $this->assertContains('empty_term', $result['penalties']);
        $this->assertSame(0.6, $result['coherence']);
        $this->assertNotSame('coherent', $result['status']);
        $this->assertSame('weak', $result['status']);
    }

    public function testSubjectInPredicateAlignmentYieldsStrictlyHigherCoherence(): void
    {
        $aligned = $this->scorer->score('atlas', 'atlas grows', 'definitely', 0.9, 0);
        $notAligned = $this->scorer->score('atlas', 'system grows', 'definitely', 0.9, 0);

        $this->assertSame(0.6, $aligned['coherence']);
        $this->assertSame(0.5, $notAligned['coherence']);
        $this->assertGreaterThan($notAligned['coherence'], $aligned['coherence']);
    }

    public function testBoundaryCoherenceExactlyZeroPointSevenIsCoherent(): void
    {
        $result = $this->scorer->score('atlas', 'ships features', 'maybe stable', 0.9, 3);

        $this->assertSame(0.7, $result['coherence']);
        $this->assertSame('coherent', $result['status']);
    }
}
