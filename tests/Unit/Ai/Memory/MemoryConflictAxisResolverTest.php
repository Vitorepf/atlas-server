<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Memory;

use App\Services\Ai\Memory\MemoryConflictAxisResolver;
use Tests\TestCase;

final class MemoryConflictAxisResolverTest extends TestCase
{
    private MemoryConflictAxisResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new MemoryConflictAxisResolver();
    }

    /**
     * (1) Authority dominates recency: a stale authoritative side (lower
     * authority_rank, OLDER recorded_ts) beats a fresh agent side (higher
     * authority_rank, NEWER recorded_ts).
     */
    public function testAuthorityDominatesRecency(): void
    {
        $authoritative = [
            'label' => 'human_policy',
            'authority_rank' => 0,
            'evidence_count' => 2,
            'recorded_ts' => 100,
        ];
        $agent = [
            'label' => 'agent_guess',
            'authority_rank' => 3,
            'evidence_count' => 9,
            'recorded_ts' => 900,
        ];

        $result = $this->resolver->resolve($authoritative, $agent);

        $this->assertSame('atlas.memory.conflict_axis_resolution.v1', $result['schema_version']);
        $this->assertSame('human_policy', $result['winner']);
        $this->assertSame('authority', $result['decisive_axis']);
        $this->assertSame(abs(0 - 3), $result['margin']);
        // R5: the lower-authority_rank side wins the authority axis.
        $this->assertSame(1, $result['axis_scores']['a']['authority']);
        $this->assertSame(-1, $result['axis_scores']['b']['authority']);
    }

    /**
     * (2) Authority tie -> higher evidence_count wins; authority axis flags
     * collapse to 0 on both sides.
     */
    public function testAuthorityTieDefersToEvidence(): void
    {
        $sideA = [
            'label' => 'evidence_heavy',
            'authority_rank' => 2,
            'evidence_count' => 7,
            'recorded_ts' => 400,
        ];
        $sideB = [
            'label' => 'evidence_light',
            'authority_rank' => 2,
            'evidence_count' => 2,
            'recorded_ts' => 900,
        ];

        $result = $this->resolver->resolve($sideA, $sideB);

        $this->assertSame('evidence_heavy', $result['winner']);
        $this->assertSame('evidence', $result['decisive_axis']);
        $this->assertSame(abs(7 - 2), $result['margin']);
        $this->assertSame(0, $result['axis_scores']['a']['authority']);
        $this->assertSame(0, $result['axis_scores']['b']['authority']);
        // The higher-evidence side wins the evidence axis.
        $this->assertSame(1, $result['axis_scores']['a']['evidence']);
        $this->assertSame(-1, $result['axis_scores']['b']['evidence']);
    }

    /**
     * (3) Authority + evidence tie -> strictly-newer recorded_ts wins.
     */
    public function testAuthorityAndEvidenceTieDefersToFreshness(): void
    {
        $older = [
            'label' => 'older_note',
            'authority_rank' => 1,
            'evidence_count' => 4,
            'recorded_ts' => 500,
        ];
        $newer = [
            'label' => 'newer_note',
            'authority_rank' => 1,
            'evidence_count' => 4,
            'recorded_ts' => 800,
        ];

        $result = $this->resolver->resolve($older, $newer);

        $this->assertSame('newer_note', $result['winner']);
        $this->assertSame('freshness', $result['decisive_axis']);
        $this->assertSame(abs(500 - 800), $result['margin']);
        // The newer side wins the freshness axis; the older side loses it.
        $this->assertSame(-1, $result['axis_scores']['a']['freshness']);
        $this->assertSame(1, $result['axis_scores']['b']['freshness']);
    }

    /**
     * (4) Identical authority_rank + evidence_count + recorded_ts -> full tie;
     * no decisive axis, zero margin, every axis flag is 0.
     */
    public function testFullTieAcrossAllAxes(): void
    {
        $side = [
            'label' => 'left',
            'authority_rank' => 2,
            'evidence_count' => 5,
            'recorded_ts' => 700,
        ];
        $mirror = [
            'label' => 'right',
            'authority_rank' => 2,
            'evidence_count' => 5,
            'recorded_ts' => 700,
        ];

        $result = $this->resolver->resolve($side, $mirror);

        $this->assertSame('tie', $result['winner']);
        $this->assertSame('none', $result['decisive_axis']);
        $this->assertSame(0, $result['margin']);
        $this->assertSame(0, $result['axis_scores']['a']['authority']);
        $this->assertSame(0, $result['axis_scores']['a']['evidence']);
        $this->assertSame(0, $result['axis_scores']['a']['freshness']);
        $this->assertSame(0, $result['axis_scores']['b']['authority']);
        $this->assertSame(0, $result['axis_scores']['b']['evidence']);
        $this->assertSame(0, $result['axis_scores']['b']['freshness']);
    }

    /**
     * (6) margin stays a non-negative int even when the decisive-axis values
     * straddle the native int range. A naive abs($a - $b) would overflow into a
     * float here, silently breaking the margin:int contract.
     */
    public function testMarginStaysIntWhenAxisValuesStraddleIntRange(): void
    {
        // Authority axis: PHP_INT_MIN beats PHP_INT_MAX (lower rank wins).
        $best = [
            'label' => 'best',
            'authority_rank' => PHP_INT_MIN,
            'evidence_count' => 1,
            'recorded_ts' => 1,
        ];
        $worst = [
            'label' => 'worst',
            'authority_rank' => PHP_INT_MAX,
            'evidence_count' => 1,
            'recorded_ts' => 1,
        ];

        $result = $this->resolver->resolve($best, $worst);

        $this->assertSame('best', $result['winner']);
        $this->assertSame('authority', $result['decisive_axis']);
        $this->assertIsInt($result['margin']);
        $this->assertGreaterThanOrEqual(0, $result['margin']);
        // True gap exceeds PHP_INT_MAX, so it saturates to the largest int gap.
        $this->assertSame(PHP_INT_MAX, $result['margin']);

        // Freshness axis: same straddle, with authority + evidence tied.
        $older = [
            'label' => 'older',
            'authority_rank' => 0,
            'evidence_count' => 1,
            'recorded_ts' => PHP_INT_MIN,
        ];
        $newer = [
            'label' => 'newer',
            'authority_rank' => 0,
            'evidence_count' => 1,
            'recorded_ts' => PHP_INT_MAX,
        ];

        $freshResult = $this->resolver->resolve($older, $newer);

        $this->assertSame('newer', $freshResult['winner']);
        $this->assertSame('freshness', $freshResult['decisive_axis']);
        $this->assertIsInt($freshResult['margin']);
        $this->assertSame(PHP_INT_MAX, $freshResult['margin']);
    }

    /**
     * (5) reasons list the decisive axis first and still enumerate the
     * non-deciding axes. In an evidence-decisive case, evidence is named
     * first and freshness is flagged as non-deciding.
     */
    public function testReasonsNameDecisiveAxisFirstThenNonDecidingAxes(): void
    {
        $sideA = [
            'label' => 'rich',
            'authority_rank' => 4,
            'evidence_count' => 10,
            'recorded_ts' => 200,
        ];
        $sideB = [
            'label' => 'sparse',
            'authority_rank' => 4,
            'evidence_count' => 3,
            'recorded_ts' => 999,
        ];

        $result = $this->resolver->resolve($sideA, $sideB);

        $this->assertSame('evidence', $result['decisive_axis']);
        // Decisive axis is named first.
        $this->assertSame('decisive:evidence', $result['reasons'][0]);
        // Non-deciding axes are still enumerated, including freshness.
        $this->assertContains('non_deciding:freshness', $result['reasons']);
        $this->assertContains('non_deciding:authority', $result['reasons']);
        $this->assertSame(
            ['decisive:evidence', 'non_deciding:authority', 'non_deciding:freshness'],
            $result['reasons'],
        );
    }
}
