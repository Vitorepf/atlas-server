<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfImprovement;

use App\Services\Ai\SelfImprovement\SelfImprovementGradeTrajectoryClassifier;
use PHPUnit\Framework\TestCase;

final class SelfImprovementGradeTrajectoryClassifierTest extends TestCase
{
    private SelfImprovementGradeTrajectoryClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new SelfImprovementGradeTrajectoryClassifier();
    }

    public function testRisingPositiveGradesAreCompounding(): void
    {
        $result = $this->classifier->classify(['improved', 'improved', 'major_improvement']);

        $this->assertSame('compounding', $result['trajectory']);
        $this->assertEqualsWithDelta(1.8, $result['running_sum'], 1e-9);
        $this->assertGreaterThan(0.0, $result['running_sum']);
        $this->assertLessThan(2, $result['sign_flips']);
        $this->assertSame(0, $result['sign_flips']);
        $this->assertSame(3, $result['longest_positive_streak']);
        $this->assertSame(0, $result['longest_regression_streak']);
        $this->assertSame(3, $result['scored_count']);
    }

    public function testAlternatingWithPositiveSumIsThrashing(): void
    {
        $result = $this->classifier->classify(['major_improvement', 'regressed', 'major_improvement', 'regressed']);

        $this->assertSame('thrashing', $result['trajectory']);
        $this->assertEqualsWithDelta(1.0, $result['running_sum'], 1e-9);
        $this->assertGreaterThan(0.0, $result['running_sum']);
        $this->assertGreaterThanOrEqual(2, $result['sign_flips']);
        $this->assertSame(3, $result['sign_flips']);
        $this->assertSame(4, $result['scored_count']);
    }

    public function testNetNegativeIsRegressing(): void
    {
        $result = $this->classifier->classify(['regressed', 'invalid', 'improved']);

        $this->assertSame('regressing', $result['trajectory']);
        $this->assertEqualsWithDelta(-0.3, $result['running_sum'], 1e-9);
        $this->assertLessThan(0.0, $result['running_sum']);
        $this->assertSame(3, $result['scored_count']);
    }

    public function testEmptyAndAllUnknownAreFlatZero(): void
    {
        $empty = $this->classifier->classify([]);

        $this->assertSame('flat', $empty['trajectory']);
        $this->assertSame(0.0, $empty['running_sum']);
        $this->assertSame(0, $empty['longest_positive_streak']);
        $this->assertSame(0, $empty['longest_regression_streak']);
        $this->assertSame(0, $empty['sign_flips']);
        $this->assertSame(0, $empty['scored_count']);

        $allUnknown = $this->classifier->classify(['mystery', 'unscored', 'pending']);

        $this->assertSame('flat', $allUnknown['trajectory']);
        $this->assertSame(0.0, $allUnknown['running_sum']);
        $this->assertSame(0, $allUnknown['longest_positive_streak']);
        $this->assertSame(0, $allUnknown['longest_regression_streak']);
        $this->assertSame(0, $allUnknown['scored_count']);
    }

    public function testStreaksCountedOnMixedList(): void
    {
        $result = $this->classifier->classify([
            'major_improvement',
            'improved',
            'regressed',
            'invalid',
            'neutral',
            'improved',
        ]);

        $this->assertSame(2, $result['longest_positive_streak']);
        $this->assertSame(2, $result['longest_regression_streak']);
        $this->assertEqualsWithDelta(1.1, $result['running_sum'], 1e-9);
        $this->assertSame(6, $result['scored_count']);
        $this->assertSame(2, $result['sign_flips']);
        $this->assertSame('thrashing', $result['trajectory']);
    }
}
