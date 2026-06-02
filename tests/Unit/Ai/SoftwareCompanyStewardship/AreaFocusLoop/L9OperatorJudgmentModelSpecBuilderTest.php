<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9OperatorJudgmentModelSpecBuilder;
use PHPUnit\Framework\TestCase;

final class L9OperatorJudgmentModelSpecBuilderTest extends TestCase
{
    private L9OperatorJudgmentModelSpecBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new L9OperatorJudgmentModelSpecBuilder();
    }

    public function testBuildReturnsGovernedSpecWithAllRequiredFields(): void
    {
        $result = $this->builder->build(
            [
                'decision_count' => 24,
                'labeled_examples' => 24,
                'features' => [
                    'code_taste_signal',
                    'architecture_pattern_signal',
                    'quality_bar_signal',
                ],
            ],
            [
                'allowed_decision_classes' => ['low_risk_refactor', 'doc_update'],
                'override_channel' => 'operator_review_gate',
            ],
        );

        $this->assertSame('atlas.aaeos.l9.operator_judgment_model_spec.v1', $result['schema_version']);

        // model_spec_id is computed deterministically from the spec inputs.
        $this->assertSame('ojms_b9afc4569584e28a', $result['model_spec_id']);

        // feature_set, labels, delegated_risk_classes, anti_goodhart_anchors, override_contract.
        $this->assertSame(
            ['code_taste_signal', 'architecture_pattern_signal', 'quality_bar_signal'],
            $result['feature_set'],
        );
        $this->assertSame(['allow', 'confirmation', 'review', 'block'], $result['labels']);
        $this->assertSame(['low_risk_refactor', 'doc_update'], $result['delegated_risk_classes']);
        $this->assertSame(
            [
                'measured_or_reverted',
                'operator_override_is_ground_truth',
                'held_out_operator_decisions',
                'divergence_triggers_relearn',
            ],
            $result['anti_goodhart_anchors'],
        );

        $this->assertSame('operator_review_gate', $result['override_contract']['override_channel']);
        $this->assertTrue($result['override_contract']['operator_can_override']);
        $this->assertTrue($result['override_contract']['override_is_ground_truth']);
        $this->assertTrue($result['override_contract']['every_pre_decision_carries_receipt']);
        $this->assertSame('revert_and_relearn', $result['override_contract']['divergence_action']);

        $this->assertSame('spec_ready', $result['status']);
        $this->assertSame([], $result['blockers']);
    }

    public function testSpecNeverEnablesMutation(): void
    {
        $result = $this->builder->build(
            [
                'decision_count' => 10,
                'labeled_examples' => 10,
            ],
            [
                'allowed_decision_classes' => ['low_risk_refactor'],
                'override_channel' => 'operator_override',
            ],
        );

        // The judgment model is a governed spec before runtime: it never mutates.
        $this->assertFalse($result['enables_mutation']);
    }

    public function testNoQ2BoundaryBlocks(): void
    {
        $result = $this->builder->build(
            [
                'decision_count' => 12,
                'labeled_examples' => 12,
            ],
            [
                // No allowed classes and no proven max risk level => no Q2 boundary.
                'override_channel' => 'operator_override',
            ],
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('no_q2_boundary', $result['blockers']);
        $this->assertSame([], $result['delegated_risk_classes']);
    }

    public function testNoOverrideChannelBlocks(): void
    {
        $result = $this->builder->build(
            [
                'decision_count' => 12,
                'labeled_examples' => 12,
            ],
            [
                'allowed_decision_classes' => ['low_risk_refactor'],
                // No override channel at all.
            ],
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('no_override_channel', $result['blockers']);
        $this->assertFalse($result['override_contract']['operator_can_override']);
        $this->assertFalse($result['override_contract']['override_is_ground_truth']);
        $this->assertSame('', $result['override_contract']['override_channel']);
    }

    public function testBothBoundaryAndOverrideMissingProduceBothBlockers(): void
    {
        $result = $this->builder->build(
            [
                'decision_count' => 5,
                'labeled_examples' => 5,
            ],
            [],
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(['no_q2_boundary', 'no_override_channel'], $result['blockers']);
    }

    public function testDelegatedRiskClassesNeverExceedTheQ2Boundary(): void
    {
        $result = $this->builder->build(
            [
                'decision_count' => 30,
                'labeled_examples' => 30,
            ],
            [
                'allowed_decision_classes' => ['low_risk_refactor', 'doc_update', 'test_add'],
                'override_channel' => 'operator_review_gate',
            ],
        );

        // Spec inherits exactly the proven boundary, never wider.
        $this->assertSame(
            ['low_risk_refactor', 'doc_update', 'test_add'],
            $result['delegated_risk_classes'],
        );
        $this->assertSame([], $result['blockers']);
    }

    public function testFeatureSetIsDerivedFromCorpusSignalsWhenNotExplicit(): void
    {
        $result = $this->builder->build(
            [
                'decision_count' => 40,
                'labeled_examples' => 40,
                'rationale_refs' => ['r1', 'r2', 'r3'],
                'override_refs' => ['o1', 'o2'],
            ],
            [
                'allowed_decision_classes' => ['low_risk_refactor'],
                'override_channel' => 'operator_override',
            ],
        );

        $this->assertSame(
            [
                'operator_decision_class',
                'code_taste_signal',
                'architecture_pattern_signal',
                'quality_bar_signal',
                'approve_reject_rationale',
                'historical_override_signal',
            ],
            $result['feature_set'],
        );
        // model_spec_id reflects the derived feature set, proving it is computed.
        $this->assertSame('ojms_018b91862a7e0b14', $result['model_spec_id']);
    }

    public function testFeatureSetDropsSignalsWithoutCorpusEvidence(): void
    {
        $result = $this->builder->build(
            [
                // Only a decision count: just the decision-class feature, nothing else.
                'decision_count' => 3,
            ],
            [
                'allowed_decision_classes' => ['low_risk_refactor'],
                'override_channel' => 'operator_override',
            ],
        );

        $this->assertSame(['operator_decision_class'], $result['feature_set']);
    }

    public function testFeatureSetDerivesQualitySignalsFromS133ListShapedLabeledExamples(): void
    {
        // The real S133 corpus emits labeled_examples as a list<string>, not a
        // scalar count. The judgment-quality signals must still be derived from it
        // (a list of two labels means there ARE labeled examples to learn from).
        $result = $this->builder->build(
            [
                'decision_count' => 2,
                'labeled_examples' => ['review_gate_1', 'cockpit_1'],
            ],
            [
                'allowed_decision_classes' => ['low_risk_refactor'],
                'override_channel' => 'operator_review_gate',
            ],
        );

        $this->assertSame(
            [
                'operator_decision_class',
                'code_taste_signal',
                'architecture_pattern_signal',
                'quality_bar_signal',
            ],
            $result['feature_set'],
        );
    }

    public function testEmptyListShapedLabeledExamplesDropsQualitySignals(): void
    {
        // An empty labeled_examples list means no labeled evidence: the quality
        // signals must NOT be derived (a list with count 0 is not "> 0").
        $result = $this->builder->build(
            [
                'decision_count' => 2,
                'labeled_examples' => [],
            ],
            [
                'allowed_decision_classes' => ['low_risk_refactor'],
                'override_channel' => 'operator_review_gate',
            ],
        );

        $this->assertSame(['operator_decision_class'], $result['feature_set']);
    }

    public function testExplicitFeaturesAreCleanedDeduplicatedAndOrdered(): void
    {
        $result = $this->builder->build(
            [
                'decision_count' => 8,
                'features' => [
                    '  code_taste_signal  ',
                    'code_taste_signal',
                    '',
                    'architecture_pattern_signal',
                    42,
                ],
            ],
            [
                'allowed_decision_classes' => ['low_risk_refactor'],
                'override_channel' => 'operator_override',
            ],
        );

        // Trimmed, empties dropped, duplicates removed, int coerced, list re-indexed.
        $this->assertSame(
            ['code_taste_signal', 'architecture_pattern_signal', '42'],
            $result['feature_set'],
        );
        $this->assertSame(array_values($result['feature_set']), $result['feature_set']);
    }

    public function testDelegatedRiskClassesHonourListStringContractUnderIntKeys(): void
    {
        $result = $this->builder->build(
            [
                'decision_count' => 8,
                'labeled_examples' => 8,
            ],
            [
                'allowed_decision_classes' => ['low_risk_refactor', '', 'low_risk_refactor', 7],
                'override_channel' => 'operator_override',
            ],
        );

        $delegated = $result['delegated_risk_classes'];
        $this->assertSame(['low_risk_refactor', '7'], $delegated);
        $this->assertSame(array_values($delegated), $delegated);

        foreach ($delegated as $class) {
            $this->assertIsString($class);
        }
    }

    public function testMaxRiskLevelAloneSatisfiesQ2Boundary(): void
    {
        $result = $this->builder->build(
            [
                'decision_count' => 6,
                'labeled_examples' => 6,
            ],
            [
                // No explicit classes, but a proven max risk level => boundary present.
                'max_risk_level' => 'low',
                'override_channel' => 'operator_override',
            ],
        );

        $this->assertSame([], $result['blockers']);
        $this->assertSame('spec_ready', $result['status']);
    }

    public function testOverrideChannelPresentFlagFallsBackToCanonicalChannel(): void
    {
        $result = $this->builder->build(
            [
                'decision_count' => 6,
                'labeled_examples' => 6,
            ],
            [
                'allowed_decision_classes' => ['low_risk_refactor'],
                'override_channel_present' => true,
            ],
        );

        $this->assertSame('operator_override', $result['override_contract']['override_channel']);
        $this->assertTrue($result['override_contract']['operator_can_override']);
        $this->assertNotContains('no_override_channel', $result['blockers']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $corpus = [
            'decision_count' => 17,
            'labeled_examples' => 17,
            'rationale_refs' => ['r1'],
        ];
        $q2Boundary = [
            'allowed_decision_classes' => ['low_risk_refactor', 'doc_update'],
            'override_channel' => 'operator_review_gate',
        ];

        $first = $this->builder->build($corpus, $q2Boundary);
        $second = $this->builder->build($corpus, $q2Boundary);

        $this->assertSame($first, $second);
    }
}
