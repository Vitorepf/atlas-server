<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Context\Aucri;

use App\Services\Ai\Context\Aucri\AucriSegmentRoiScorer;
use Tests\TestCase;

final class AucriSegmentRoiScorerTest extends TestCase
{
    private AucriSegmentRoiScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new AucriSegmentRoiScorer();
    }

    public function testMustKeepLowUtilityIsRetainedWhileLowRoiNonMustKeepIsDropped(): void
    {
        $result = $this->scorer->score([
            ['ref' => 'pinned_low', 'tokens' => 10, 'utility' => 0.1, 'must_keep' => true],
            ['ref' => 'high', 'tokens' => 10, 'utility' => 9.0, 'must_keep' => false],
            ['ref' => 'mid', 'tokens' => 10, 'utility' => 5.0, 'must_keep' => false],
            ['ref' => 'low', 'tokens' => 10, 'utility' => 0.1, 'must_keep' => false],
        ]);

        $this->assertSame('atlas.aucri.segment_roi_scoring.v1', $result['schema_version']);
        $this->assertContains('pinned_low', $result['kept_must_keep']);
        $this->assertNotContains('pinned_low', $result['dropped_candidates']);
        $this->assertContains('low', $result['dropped_candidates']);
        $this->assertNotContains('high', $result['dropped_candidates']);
        $this->assertNotContains('mid', $result['dropped_candidates']);

        $pinned = $this->segmentByRef($result['scored'], 'pinned_low');
        $this->assertFalse($pinned['drop_candidate']);
        $this->assertSame('must_keep_pinned', $pinned['reason']);

        $dropped = $this->segmentByRef($result['scored'], 'low');
        $this->assertTrue($dropped['drop_candidate']);
        $this->assertSame('below_median_roi_per_token', $dropped['reason']);
    }

    public function testSingleNonMustKeepSegmentYieldsZeroDropsAndInsufficientReason(): void
    {
        $result = $this->scorer->score([
            ['ref' => 'solo', 'tokens' => 7, 'utility' => 3.0, 'must_keep' => false],
        ]);

        $this->assertSame([], $result['dropped_candidates']);
        $this->assertContains('insufficient_segments_for_roi_cut', $result['reasons']);

        $solo = $this->segmentByRef($result['scored'], 'solo');
        $this->assertFalse($solo['drop_candidate']);
        $this->assertSame('insufficient_segments_for_roi_cut', $solo['reason']);
    }

    public function testRetainedTokensEqualsTotalMinusDroppedSegmentTokens(): void
    {
        $result = $this->scorer->score([
            ['ref' => 'pinned_low', 'tokens' => 12, 'utility' => 0.1, 'must_keep' => true],
            ['ref' => 'high', 'tokens' => 8, 'utility' => 9.0, 'must_keep' => false],
            ['ref' => 'mid', 'tokens' => 5, 'utility' => 5.0, 'must_keep' => false],
            ['ref' => 'low', 'tokens' => 9, 'utility' => 0.1, 'must_keep' => false],
        ]);

        $droppedTokens = 0;
        foreach ($result['scored'] as $segment) {
            if ($segment['drop_candidate'] === true) {
                $droppedTokens += $segment['tokens'];
            }
        }

        $this->assertSame(34, $result['total_tokens']);
        $this->assertSame(9, $droppedTokens);
        $this->assertSame($result['total_tokens'] - $droppedTokens, $result['retained_tokens']);
        $this->assertSame(25, $result['retained_tokens']);
    }

    public function testRoiPerTokenRoundingToSixDecimals(): void
    {
        $result = $this->scorer->score([
            ['ref' => 'frac', 'tokens' => 3, 'utility' => 0.5, 'must_keep' => false],
        ]);

        $frac = $this->segmentByRef($result['scored'], 'frac');
        $this->assertSame(0.166667, $frac['roi_per_token']);
    }

    public function testDeterministicOrderingBreaksRoiTiesByRefAscending(): void
    {
        $result = $this->scorer->score([
            ['ref' => 'zeta', 'tokens' => 10, 'utility' => 5.0, 'must_keep' => false],
            ['ref' => 'alpha', 'tokens' => 10, 'utility' => 5.0, 'must_keep' => false],
        ]);

        $this->assertSame(0.5, $result['scored'][0]['roi_per_token']);
        $this->assertSame(0.5, $result['scored'][1]['roi_per_token']);
        $this->assertSame('alpha', $result['scored'][0]['ref']);
        $this->assertSame('zeta', $result['scored'][1]['ref']);
        $this->assertSame([], $result['dropped_candidates']);
    }

    public function testRoiTieBreakOnNumericStringRefsIsLexicographicNotNumeric(): void
    {
        // Equal roi_per_token (all 0.5) forces the ref tie-break. Numeric-string refs
        // must order lexicographically ("10","100","2"), NOT numerically ("2","10","100").
        $result = $this->scorer->score([
            ['ref' => '2', 'tokens' => 10, 'utility' => 5.0, 'must_keep' => false],
            ['ref' => '100', 'tokens' => 10, 'utility' => 5.0, 'must_keep' => false],
            ['ref' => '10', 'tokens' => 10, 'utility' => 5.0, 'must_keep' => false],
        ]);

        $orderedRefs = array_map(static fn (array $segment): string => $segment['ref'], $result['scored']);
        $this->assertSame(['10', '100', '2'], $orderedRefs);
    }

    /**
     * @param  array<int, array{ref: string, tokens: int, utility: float, must_keep: bool, roi_per_token: float, drop_candidate: bool, reason: string}>  $scored
     * @return array{ref: string, tokens: int, utility: float, must_keep: bool, roi_per_token: float, drop_candidate: bool, reason: string}
     */
    private function segmentByRef(array $scored, string $ref): array
    {
        foreach ($scored as $segment) {
            if ($segment['ref'] === $ref) {
                return $segment;
            }
        }

        $this->fail("Segment with ref '{$ref}' not found in scored output.");
    }
}
