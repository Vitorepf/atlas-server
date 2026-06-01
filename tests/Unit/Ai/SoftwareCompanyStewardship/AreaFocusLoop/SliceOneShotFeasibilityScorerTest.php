<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\SliceOneShotFeasibilityScorer;
use PHPUnit\Framework\TestCase;

final class SliceOneShotFeasibilityScorerTest extends TestCase
{
    private SliceOneShotFeasibilityScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new SliceOneShotFeasibilityScorer();
    }

    public function testSmallNewFileSliceWithinOneShotBudget(): void
    {
        $result = $this->scorer->score(40, 3, 0, 2, true);

        $this->assertSame(SliceOneShotFeasibilityScorer::SCHEMA_VERSION, $result['schema_version']);
        $this->assertTrue($result['one_shot_able']);
        $this->assertSame('one_shot', $result['feasibility_band']);
        $this->assertTrue($result['spend_allowed']);
        $this->assertSame(['within_one_shot_budget'], $result['reasons']);
    }

    public function testLargeMultiShotSliceCollectsOverBudgetReasons(): void
    {
        $result = $this->scorer->score(260, 12, 4, 5, false);

        $this->assertFalse($result['one_shot_able']);
        $this->assertSame('multi_shot', $result['feasibility_band']);
        $this->assertFalse($result['spend_allowed']);
        $this->assertTrue(in_array('loc_over_budget', $result['reasons'], true));
        $this->assertTrue(in_array('rule_count_over_budget', $result['reasons'], true));
    }

    public function testModerateExistingFileSliceLandsInTightBand(): void
    {
        $result = $this->scorer->score(130, 5, 0, 2, false);

        $this->assertSame('tight', $result['feasibility_band']);
        $this->assertTrue($result['one_shot_able']);
        $this->assertTrue($result['spend_allowed']);
        $this->assertTrue(in_array('loc_over_budget', $result['reasons'], true));
    }

    public function testNewFileSliceRelaxesExactlyOneTierFromTightToOneShot(): void
    {
        $existing = $this->scorer->score(130, 5, 0, 2, false);
        $newFile = $this->scorer->score(130, 5, 0, 2, true);

        $this->assertSame('tight', $existing['feasibility_band']);
        $this->assertSame('one_shot', $newFile['feasibility_band']);
    }

    public function testScoreDecreasesMonotonicallyAsInputsGrow(): void
    {
        $lowPressure = $this->scorer->score(0, 0, 0, 1, true);
        $highPressure = $this->scorer->score(200, 10, 3, 5, false);

        $this->assertGreaterThan($highPressure['score'], $lowPressure['score']);
        $this->assertEqualsWithDelta(0.95, $lowPressure['score'], 0.001);
        $this->assertEqualsWithDelta(0.0, $highPressure['score'], 0.001);
    }

    public function testRuleCountOnlyTightBandIsNotRelaxedForNewFileSlice(): void
    {
        $result = $this->scorer->score(50, 7, 0, 2, true);

        $this->assertSame('tight', $result['feasibility_band']);
        $this->assertTrue(in_array('rule_count_over_budget', $result['reasons'], true));
    }

    public function testMultiShotLocBoundaryIsNotRelaxedForNewFileSlice(): void
    {
        $result = $this->scorer->score(200, 5, 0, 2, true);

        $this->assertSame('multi_shot', $result['feasibility_band']);
        $this->assertFalse($result['one_shot_able']);
    }

    public function testNegativeInputsAreClampedToZero(): void
    {
        $result = $this->scorer->score(-10, -3, -1, -2, false);

        $this->assertSame('one_shot', $result['feasibility_band']);
        $this->assertSame(['within_one_shot_budget'], $result['reasons']);
    }

    public function testResultContainsAllRequiredKeys(): void
    {
        $result = $this->scorer->score(40, 3, 0, 2, true);

        $this->assertArrayHasKey('schema_version', $result);
        $this->assertArrayHasKey('one_shot_able', $result);
        $this->assertArrayHasKey('feasibility_band', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('spend_allowed', $result);
        $this->assertArrayHasKey('reasons', $result);
    }
}
