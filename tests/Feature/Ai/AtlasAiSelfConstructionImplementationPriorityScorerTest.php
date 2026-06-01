<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\SelfConstructionImplementationPriorityScorer;
use Tests\TestCase;

final class AtlasAiSelfConstructionImplementationPriorityScorerTest extends TestCase
{
    private SelfConstructionImplementationPriorityScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new SelfConstructionImplementationPriorityScorer();
    }

    public function testReturnShapeMatchesSchemaWithSchemaVersion(): void
    {
        $result = $this->scorer->score([
            'strategic_leverage' => 4,
            'dependency_unlocks' => 3,
        ]);

        $this->assertSame('atlas.self_construction.implementation_priority.v1', $result['schema_version']);
        $this->assertArrayHasKey('mode', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('p_level', $result);
        $this->assertArrayHasKey('dominant_factor', $result);
        $this->assertArrayHasKey('weakest_factor', $result);
        $this->assertArrayHasKey('clamped_factors', $result);
        $this->assertArrayHasKey('additive_total', $result);
        $this->assertArrayHasKey('subtractive_total', $result);
    }

    /**
     * Rule (1): six additive terms summing to exactly 40 with all four subtractive
     * at 0 => score === 40 and p_level === 'P0'; lowering one additive so
     * score === 39 => p_level === 'P1' (>= 40 inclusive boundary).
     */
    public function testAdditiveFortyIsP0AndThirtyNineDropsToP1(): void
    {
        $p0 = $this->scorer->score([
            'strategic_leverage' => 10,
            'dependency_unlocks' => 10,
            'quality_improvement' => 10,
            'autonomy_enablement' => 10,
            'user_value' => 0,
            'evidence_confidence' => 0,
        ]);

        $this->assertSame(40, $p0['score']);
        $this->assertSame(0, $p0['subtractive_total']);
        $this->assertSame('P0', $p0['p_level']);

        $p1 = $this->scorer->score([
            'strategic_leverage' => 10,
            'dependency_unlocks' => 10,
            'quality_improvement' => 10,
            'autonomy_enablement' => 9,
            'user_value' => 0,
            'evidence_confidence' => 0,
        ]);

        $this->assertSame(39, $p1['score']);
        $this->assertSame('P1', $p1['p_level']);
    }

    /**
     * Rule (2): a factor set summing to exactly 12 => p_level === 'P2', and a set
     * summing to 11 => p_level === 'P3'.
     */
    public function testTwelveIsP2AndElevenIsP3(): void
    {
        $p2 = $this->scorer->score([
            'strategic_leverage' => 10,
            'dependency_unlocks' => 2,
        ]);

        $this->assertSame(12, $p2['score']);
        $this->assertSame('P2', $p2['p_level']);

        $p3 = $this->scorer->score([
            'strategic_leverage' => 10,
            'dependency_unlocks' => 1,
        ]);

        $this->assertSame(11, $p3['score']);
        $this->assertSame('P3', $p3['p_level']);
    }

    /**
     * Rule (3): strategic_leverage=99 and risk=-5 => clamped to 0..10 BEFORE
     * summation, so clamped_factors['strategic_leverage'] === 10 and
     * clamped_factors['risk'] === 0.
     */
    public function testFactorsAreClampedToZeroTenBeforeSummation(): void
    {
        $result = $this->scorer->score([
            'strategic_leverage' => 99,
            'risk' => -5,
        ]);

        $this->assertSame(10, $result['clamped_factors']['strategic_leverage']);
        $this->assertSame(0, $result['clamped_factors']['risk']);
        $this->assertSame(10, $result['additive_total']);
        $this->assertSame(0, $result['subtractive_total']);
    }

    /**
     * Rule (4): strategic_leverage=10 with all others 0 => additive_total === 10,
     * subtractive_total === 0, score === 10; then adding risk=10 => score === 0
     * (subtraction proven).
     */
    public function testSubtractionLowersScore(): void
    {
        $additiveOnly = $this->scorer->score([
            'strategic_leverage' => 10,
        ]);

        $this->assertSame(10, $additiveOnly['additive_total']);
        $this->assertSame(0, $additiveOnly['subtractive_total']);
        $this->assertSame(10, $additiveOnly['score']);

        $withRisk = $this->scorer->score([
            'strategic_leverage' => 10,
            'risk' => 10,
        ]);

        $this->assertSame(10, $withRisk['additive_total']);
        $this->assertSame(10, $withRisk['subtractive_total']);
        $this->assertSame(0, $withRisk['score']);
    }

    /**
     * Rule (5): dependency_unlocks=8 is the single largest factor and uncertainty=7
     * the single largest subtractive => dominant_factor === 'dependency_unlocks'
     * (max contributor across all 10), weakest_factor is the lowest-contributing
     * additive factor (tie broken by canonical order), and the two named keys are
     * never equal.
     */
    public function testDominantAcrossAllFactorsAndWeakestAdditiveNeverEqual(): void
    {
        $result = $this->scorer->score([
            'dependency_unlocks' => 8,
            'uncertainty' => 7,
        ]);

        $this->assertSame('dependency_unlocks', $result['dominant_factor']);
        $this->assertSame('strategic_leverage', $result['weakest_factor']);
        $this->assertNotSame($result['dominant_factor'], $result['weakest_factor']);
    }

    /**
     * Reinforces rule (5): the dominant factor can be subtractive when it is the
     * single largest magnitude across all ten factors, while the weakest stays an
     * additive factor and the two remain distinct (generalisation, inputs not in
     * the enumerated rows).
     */
    public function testSubtractiveFactorCanDominateWhileWeakestStaysAdditive(): void
    {
        $result = $this->scorer->score([
            'strategic_leverage' => 3,
            'dependency_unlocks' => 2,
            'maintenance_burden' => 9,
        ]);

        $this->assertSame('maintenance_burden', $result['dominant_factor']);
        $this->assertSame('quality_improvement', $result['weakest_factor']);
        $this->assertNotSame($result['dominant_factor'], $result['weakest_factor']);
    }

    /**
     * Generalisation guard: an all-zero factor set must still keep dominant and
     * weakest distinct (the two named keys are never equal), and the midpoint band
     * resolves P1 for a score strictly between the 40 and 12 anchors.
     */
    public function testAllZeroKeepsKeysDistinctAndMidBandResolvesP1(): void
    {
        $zero = $this->scorer->score([]);

        $this->assertSame(0, $zero['score']);
        $this->assertSame('P3', $zero['p_level']);
        $this->assertSame('deferred', $zero['mode']);
        $this->assertNotSame($zero['dominant_factor'], $zero['weakest_factor']);

        $mid = $this->scorer->score([
            'strategic_leverage' => 10,
            'dependency_unlocks' => 10,
            'quality_improvement' => 10,
        ]);

        $this->assertSame(30, $mid['score']);
        $this->assertSame('P1', $mid['p_level']);
        $this->assertSame('high_leverage', $mid['mode']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $factors = [
            'strategic_leverage' => 7,
            'dependency_unlocks' => 4,
            'risk' => 2,
            'uncertainty' => 3,
        ];

        $first = $this->scorer->score($factors);
        $second = $this->scorer->score($factors);

        $this->assertSame($first, $second);
    }
}
