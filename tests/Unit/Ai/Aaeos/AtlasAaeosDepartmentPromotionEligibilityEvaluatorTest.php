<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasAaeosDepartmentPromotionEligibilityEvaluator;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosDepartmentPromotionEligibilityEvaluatorTest extends TestCase
{
    private AtlasAaeosDepartmentPromotionEligibilityEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new AtlasAaeosDepartmentPromotionEligibilityEvaluator();
    }

    public function testSchemaVersionAndPreconditionKeysAreCanonical(): void
    {
        $result = $this->evaluator->evaluate(
            $this->greenDepartment(),
            $this->greenMetrics(),
            $this->greenOptions(),
        );

        $this->assertSame('atlas.aaeos.department_promotion_eligibility.v1', $result['schema_version']);
        $this->assertSame(['blockers', 'quality_bar', 'freshness'], $this->evaluator->preconditionKeys());
    }

    public function testAllGreenPreconditionsYieldEligibleVerdict(): void
    {
        $result = $this->evaluator->evaluate(
            $this->greenDepartment(),
            $this->greenMetrics(),
            $this->greenOptions(),
        );

        $this->assertSame('eligible', $result['verdict']);
        $this->assertSame(3, $result['target_tier']);
        $this->assertSame($result['current_tier'] + 1, $result['target_tier']);
        $this->assertSame([], $result['failed_preconditions']);
        $this->assertSame([], $result['blocking_reasons']);
        $this->assertTrue($result['preconditions']['blockers']['passed']);
        $this->assertTrue($result['preconditions']['quality_bar']['passed']);
        $this->assertTrue($result['preconditions']['freshness']['passed']);
    }

    public function testUnresolvedBlockerBlocksWhileQualityAndFreshnessPass(): void
    {
        $department = $this->greenDepartment();
        $department['blockers_to_next'] = [
            ['id' => 'sec-audit', 'resolved' => true],
            ['id' => 'load-test', 'resolved' => false, 'severity' => 'high'],
        ];

        $result = $this->evaluator->evaluate($department, $this->greenMetrics(), $this->greenOptions());

        $this->assertSame('blocked', $result['verdict']);
        $this->assertContains('blockers', $result['failed_preconditions']);
        $this->assertFalse($result['preconditions']['blockers']['passed']);
        $this->assertContains('load-test', $result['preconditions']['blockers']['unresolved']);
        $this->assertNotContains('sec-audit', $result['preconditions']['blockers']['unresolved']);
        $this->assertSame(2, $result['preconditions']['blockers']['total']);
        $this->assertContains('blocked_by_unresolved_blockers:load-test', $result['blocking_reasons']);
        $this->assertTrue($result['preconditions']['quality_bar']['passed']);
        $this->assertTrue($result['preconditions']['freshness']['passed']);
        $this->assertNotContains('quality_bar', $result['failed_preconditions']);
        $this->assertNotContains('freshness', $result['failed_preconditions']);
    }

    public function testScoreBelowTargetThresholdFailsQualityBarWithComputedDeficit(): void
    {
        $metrics = [
            'current_score' => 71.5,
            'tier_thresholds' => [1 => 50.0, 2 => 65.0, 3 => 80.25],
        ];

        $result = $this->evaluator->evaluate($this->greenDepartment(), $metrics, $this->greenOptions());

        $this->assertFalse($result['preconditions']['quality_bar']['passed']);
        $this->assertSame(80.25, $result['preconditions']['quality_bar']['required_threshold']);
        $this->assertSame(71.5, $result['preconditions']['quality_bar']['current_score']);
        $this->assertSame(round(80.25 - 71.5, 4), $result['preconditions']['quality_bar']['deficit']);
        $this->assertGreaterThan(0, $result['preconditions']['quality_bar']['deficit']);
        $this->assertSame('blocked', $result['verdict']);
        $this->assertContains('quality_bar', $result['failed_preconditions']);
        $this->assertContains('quality_bar_not_met', $result['blocking_reasons']);
    }

    public function testEvidenceAgedThirtyOneDaysFailsFreshnessButThirtyPasses(): void
    {
        $department = $this->greenDepartment();
        $department['last_evaluation'] = '2026-05-01T00:00:00+00:00';

        $passingThirty = $this->evaluator->evaluate(
            $department,
            $this->greenMetrics(),
            ['as_of' => '2026-05-31T00:00:00+00:00'],
        );

        $this->assertSame(30, $passingThirty['preconditions']['freshness']['age_days']);
        $this->assertTrue($passingThirty['preconditions']['freshness']['passed']);

        $failingThirtyOne = $this->evaluator->evaluate(
            $department,
            $this->greenMetrics(),
            ['as_of' => '2026-06-01T00:00:00+00:00'],
        );

        $this->assertSame(31, $failingThirtyOne['preconditions']['freshness']['age_days']);
        $this->assertFalse($failingThirtyOne['preconditions']['freshness']['passed']);
        $this->assertSame(30, $failingThirtyOne['preconditions']['freshness']['max_age_days']);
        $this->assertSame('blocked', $failingThirtyOne['verdict']);
        $this->assertContains('freshness', $failingThirtyOne['failed_preconditions']);
        $this->assertContains('evidence_stale', $failingThirtyOne['blocking_reasons']);
    }

    public function testCurrentTierAtMaxTierBlocksAndKeepsTargetClamped(): void
    {
        $department = $this->greenDepartment();
        $department['current_tier'] = 5;

        $result = $this->evaluator->evaluate(
            $department,
            ['current_score' => 99.0, 'tier_thresholds' => [5 => 90.0]],
            ['as_of' => '2026-06-01T00:00:00+00:00', 'max_tier' => 5],
        );

        $this->assertSame('blocked', $result['verdict']);
        $this->assertSame(5, $result['target_tier']);
        $this->assertSame(5, $result['current_tier']);
        $this->assertContains('already_at_max_tier', $result['blocking_reasons']);
    }

    public function testEligibilityHashIsStableAndShiftsWhenBlockerResolutionChanges(): void
    {
        $department = $this->greenDepartment();
        $department['blockers_to_next'] = [
            ['id' => 'load-test', 'resolved' => false],
        ];

        $first = $this->evaluator->evaluate($department, $this->greenMetrics(), $this->greenOptions());
        $second = $this->evaluator->evaluate($department, $this->greenMetrics(), $this->greenOptions());

        $this->assertSame($first['eligibility_hash'], $second['eligibility_hash']);

        $resolved = $this->greenDepartment();
        $resolved['blockers_to_next'] = [
            ['id' => 'load-test', 'resolved' => true],
        ];

        $changed = $this->evaluator->evaluate($resolved, $this->greenMetrics(), $this->greenOptions());

        $this->assertNotSame($first['eligibility_hash'], $changed['eligibility_hash']);
    }

    public function testPromotionAndWriteFlagsAreAlwaysFalse(): void
    {
        $eligible = $this->evaluator->evaluate(
            $this->greenDepartment(),
            $this->greenMetrics(),
            $this->greenOptions(),
        );

        $blockedDepartment = $this->greenDepartment();
        $blockedDepartment['blockers_to_next'] = [['id' => 'load-test', 'resolved' => false]];
        $blocked = $this->evaluator->evaluate($blockedDepartment, $this->greenMetrics(), $this->greenOptions());

        $this->assertFalse($eligible['promotion_allowed']);
        $this->assertFalse($eligible['canonical_write_allowed']);
        $this->assertFalse($eligible['auto_promote_allowed']);
        $this->assertFalse($blocked['promotion_allowed']);
        $this->assertFalse($blocked['canonical_write_allowed']);
        $this->assertFalse($blocked['auto_promote_allowed']);
    }

    public function testTargetThresholdFallbackResolvesWhenTierMapAbsent(): void
    {
        $result = $this->evaluator->evaluate(
            $this->greenDepartment(),
            ['current_score' => 88.0, 'target_threshold' => 70.0],
            $this->greenOptions(),
        );

        $this->assertSame(70.0, $result['preconditions']['quality_bar']['required_threshold']);
        $this->assertTrue($result['preconditions']['quality_bar']['passed']);
        $this->assertSame('eligible', $result['verdict']);
    }

    /**
     * @return array{current_tier: int, blockers_to_next: list<array{id: string, resolved: bool}>, last_evaluation: string}
     */
    private function greenDepartment(): array
    {
        return [
            'current_tier' => 2,
            'blockers_to_next' => [
                ['id' => 'sec-audit', 'resolved' => true],
            ],
            'last_evaluation' => '2026-05-25T00:00:00+00:00',
        ];
    }

    /**
     * @return array{current_score: float, tier_thresholds: array<int, float>}
     */
    private function greenMetrics(): array
    {
        return [
            'current_score' => 85.0,
            'tier_thresholds' => [1 => 50.0, 2 => 65.0, 3 => 80.0],
        ];
    }

    /**
     * @return array{as_of: string, max_evidence_age_days: int, max_tier: int}
     */
    private function greenOptions(): array
    {
        return [
            'as_of' => '2026-05-30T00:00:00+00:00',
            'max_evidence_age_days' => 30,
            'max_tier' => 5,
        ];
    }
}
