<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context\Aucri;

use App\Services\Ai\Context\Aucri\ContextAblationDamageClassifier;
use Tests\TestCase;

final class ContextAblationDamageClassifierTest extends TestCase
{
    private ContextAblationDamageClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new ContextAblationDamageClassifier();
    }

    public function testMustKeepDropBelowFullIsCriticalKeep(): void
    {
        $result = $this->classifier->classify(
            [
                'quality_score' => 0.9,
                'must_keep_coverage' => 1.0,
                'evidence_coverage' => 0.9,
                'sufficiency' => 0.9,
            ],
            [
                'quality_score' => 0.9,
                'must_keep_coverage' => 0.8,
                'evidence_coverage' => 0.9,
                'sufficiency' => 0.9,
            ],
        );

        $this->assertSame('atlas.aucri.context_ablation_damage.v1', $result['schema_version']);
        $this->assertSame('group_is_critical_keep', $result['verdict']);
        $this->assertTrue($result['group_critical']);
        $this->assertContains('must_keep', $result['damaged_dimensions']);
    }

    public function testEvidenceCoverageDropAloneIsCriticalKeep(): void
    {
        $result = $this->classifier->classify(
            [
                'quality_score' => 0.9,
                'must_keep_coverage' => 1.0,
                'evidence_coverage' => 0.9,
                'sufficiency' => 0.9,
            ],
            [
                'quality_score' => 0.9,
                'must_keep_coverage' => 1.0,
                'evidence_coverage' => 0.7,
                'sufficiency' => 0.9,
            ],
        );

        $this->assertTrue($result['group_critical']);
        $this->assertSame('group_is_critical_keep', $result['verdict']);
        $this->assertContains('evidence_coverage', $result['damaged_dimensions']);
        $this->assertNotContains('must_keep', $result['damaged_dimensions']);
    }

    public function testSufficiencyDropAloneIsSoftLoss(): void
    {
        $result = $this->classifier->classify(
            [
                'quality_score' => 0.9,
                'must_keep_coverage' => 1.0,
                'evidence_coverage' => 0.9,
                'sufficiency' => 0.9,
            ],
            [
                'quality_score' => 0.9,
                'must_keep_coverage' => 1.0,
                'evidence_coverage' => 0.9,
                'sufficiency' => 0.7,
            ],
        );

        $this->assertFalse($result['group_critical']);
        $this->assertSame('group_is_soft_loss', $result['verdict']);
        $this->assertSame(['sufficiency'], $result['damaged_dimensions']);
        $this->assertNotContains('quality', $result['damaged_dimensions']);
    }

    public function testEqualOrBetterMetricsAreRemovable(): void
    {
        $result = $this->classifier->classify(
            [
                'quality_score' => 0.9,
                'must_keep_coverage' => 1.0,
                'evidence_coverage' => 0.9,
                'sufficiency' => 0.9,
            ],
            [
                'quality_score' => 0.95,
                'must_keep_coverage' => 1.0,
                'evidence_coverage' => 0.9,
                'sufficiency' => 0.92,
            ],
        );

        $this->assertSame('group_is_removable', $result['verdict']);
        $this->assertSame([], $result['damaged_dimensions']);
        $this->assertSame([], $result['reasons']);
    }

    public function testReasonsMatchDamagedDimensionsCountAndRuleOrder(): void
    {
        $result = $this->classifier->classify(
            [
                'quality_score' => 0.9,
                'must_keep_coverage' => 1.0,
                'evidence_coverage' => 0.9,
                'sufficiency' => 0.9,
            ],
            [
                'quality_score' => 0.6,
                'must_keep_coverage' => 1.0,
                'evidence_coverage' => 0.9,
                'sufficiency' => 0.7,
            ],
        );

        $this->assertCount(count($result['damaged_dimensions']), $result['reasons']);
        $this->assertSame(['sufficiency', 'quality'], $result['damaged_dimensions']);

        $sufficiencyIndex = array_search('sufficiency', $result['damaged_dimensions'], true);
        $qualityIndex = array_search('quality', $result['damaged_dimensions'], true);
        $this->assertLessThan($qualityIndex, $sufficiencyIndex);

        // R7: each reason is the fixed human string for the parallel damaged
        // dimension, in the same order (content + correspondence, not just count).
        $this->assertSame(
            [
                'removed group reduced sufficiency — answer completeness degraded',
                'removed group reduced quality score — output fidelity degraded',
            ],
            $result['reasons'],
        );
    }

    public function testEpsilonTreatsEqualMetricsAsUndamaged(): void
    {
        $result = $this->classifier->classify(
            [
                'quality_score' => 0.81,
                'must_keep_coverage' => 1.0,
                'evidence_coverage' => 0.42,
                'sufficiency' => 0.73,
            ],
            [
                'quality_score' => 0.81,
                'must_keep_coverage' => 1.0,
                'evidence_coverage' => 0.42,
                'sufficiency' => 0.73,
            ],
        );

        $this->assertSame([], $result['damaged_dimensions']);
        $this->assertFalse($result['group_critical']);
        $this->assertSame('group_is_removable', $result['verdict']);
    }
}
