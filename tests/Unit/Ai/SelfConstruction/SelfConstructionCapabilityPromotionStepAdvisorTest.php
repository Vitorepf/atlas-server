<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\SelfConstructionCapabilityPromotionStepAdvisor;
use PHPUnit\Framework\TestCase;

final class SelfConstructionCapabilityPromotionStepAdvisorTest extends TestCase
{
    private SelfConstructionCapabilityPromotionStepAdvisor $advisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->advisor = new SelfConstructionCapabilityPromotionStepAdvisor();
    }

    public function test_l3_step_with_gating_signal_false_is_not_promotable(): void
    {
        $result = $this->advisor->adviseNext(3, ['manual_command_passes' => false]);

        self::assertSame('atlas.self_construction.promotion_step.v1', $result['schema_version']);
        self::assertSame('L3->L4', $result['next_promotion']);
        self::assertSame(4, $result['next_level']);
        self::assertStringContainsString('manual command', $result['required_proof']);
        self::assertSame(['manual_command_passes'], $result['missing_proof']);
        self::assertFalse($result['is_promotable_now']);
        self::assertFalse($result['at_ceiling']);
    }

    public function test_l3_step_flips_to_promotable_when_gating_signal_true(): void
    {
        $result = $this->advisor->adviseNext(3, ['manual_command_passes' => true]);

        self::assertSame('L3->L4', $result['next_promotion']);
        self::assertSame(4, $result['next_level']);
        self::assertSame([], $result['missing_proof']);
        self::assertTrue($result['is_promotable_now']);
        self::assertFalse($result['at_ceiling']);
    }

    public function test_ceiling_level_has_no_next_promotion(): void
    {
        $result = $this->advisor->adviseNext(8, [
            'has_canonical_doc' => true,
            'has_spec' => true,
            'has_scaffold' => true,
            'manual_command_passes' => true,
            'agent_executable_with_receipt' => true,
            'repeated_safe_runs' => true,
            'learning_proposals_safe' => true,
            'strategic_selection_proven' => true,
        ]);

        self::assertSame('atlas.self_construction.promotion_step.v1', $result['schema_version']);
        self::assertNull($result['next_promotion']);
        self::assertNull($result['next_level']);
        self::assertNull($result['required_proof']);
        self::assertSame([], $result['missing_proof']);
        self::assertTrue($result['at_ceiling']);
        self::assertFalse($result['is_promotable_now']);
    }

    public function test_level_zero_step_targets_documentation_proof(): void
    {
        $result = $this->advisor->adviseNext(0, ['has_canonical_doc' => false]);

        self::assertSame('L0->L1', $result['next_promotion']);
        self::assertSame(1, $result['next_level']);
        self::assertStringContainsString('doc', $result['required_proof']);
        self::assertSame(['has_canonical_doc'], $result['missing_proof']);
        self::assertFalse($result['is_promotable_now']);
        self::assertFalse($result['at_ceiling']);
    }

    public function test_out_of_range_level_collapses_to_ceiling(): void
    {
        $result = $this->advisor->adviseNext(9, ['strategic_selection_proven' => true]);

        self::assertTrue($result['at_ceiling']);
        self::assertNull($result['next_promotion']);
        self::assertNull($result['next_level']);
        self::assertNull($result['required_proof']);
        self::assertSame([], $result['missing_proof']);
        self::assertFalse($result['is_promotable_now']);
    }

    public function test_all_eight_promotion_rules_map_in_canonical_ladder_order(): void
    {
        $expected = [
            0 => ['label' => 'L0->L1', 'next_level' => 1, 'signals' => ['has_canonical_doc']],
            1 => ['label' => 'L1->L2', 'next_level' => 2, 'signals' => ['has_spec']],
            2 => ['label' => 'L2->L3', 'next_level' => 3, 'signals' => ['has_scaffold']],
            3 => ['label' => 'L3->L4', 'next_level' => 4, 'signals' => ['manual_command_passes']],
            4 => ['label' => 'L4->L5', 'next_level' => 5, 'signals' => ['agent_executable_with_receipt', 'decision_receipt_present', 'gates_passed']],
            5 => ['label' => 'L5->L6', 'next_level' => 6, 'signals' => ['repeated_safe_runs', 'rollback_proven', 'drift_check_passed']],
            6 => ['label' => 'L6->L7', 'next_level' => 7, 'signals' => ['learning_proposals_safe', 'no_unsafe_mutation_detected']],
            7 => ['label' => 'L7->L8', 'next_level' => 8, 'signals' => ['priority_engine_present', 'build_graph_verified', 'metrics_proven']],
        ];

        self::assertCount(8, $expected);

        foreach ($expected as $level => $step) {
            // All signals absent => all are listed as missing proof.
            $blocked = $this->advisor->adviseNext($level, []);
            self::assertSame($step['label'], $blocked['next_promotion']);
            self::assertSame($step['next_level'], $blocked['next_level']);
            self::assertSame($level + 1, $blocked['next_level']);
            self::assertSame($step['signals'], $blocked['missing_proof']);
            self::assertFalse($blocked['is_promotable_now']);
            self::assertFalse($blocked['at_ceiling']);
            self::assertNotNull($blocked['required_proof']);

            // All signals true flips promotability (regardless of how many families required).
            $allTrue = array_fill_keys($step['signals'], true);
            $cleared = $this->advisor->adviseNext($level, $allTrue);
            self::assertSame($step['label'], $cleared['next_promotion']);
            self::assertSame([], $cleared['missing_proof']);
            self::assertTrue($cleared['is_promotable_now']);
        }
    }

    public function test_higher_level_promotion_requires_all_proof_families(): void
    {
        // L4->L5: one signal alone is not enough — all three families required.
        $partial = $this->advisor->adviseNext(4, ['agent_executable_with_receipt' => true]);
        self::assertFalse($partial['is_promotable_now']);
        self::assertNotContains('agent_executable_with_receipt', $partial['missing_proof']);
        self::assertContains('decision_receipt_present', $partial['missing_proof']);
        self::assertContains('gates_passed', $partial['missing_proof']);

        // All three present → promotable, missing_proof empty.
        $full = $this->advisor->adviseNext(4, [
            'agent_executable_with_receipt' => true,
            'decision_receipt_present' => true,
            'gates_passed' => true,
        ]);
        self::assertTrue($full['is_promotable_now']);
        self::assertSame([], $full['missing_proof']);

        // L5->L6: three families.
        $partialL5 = $this->advisor->adviseNext(5, ['repeated_safe_runs' => true]);
        self::assertFalse($partialL5['is_promotable_now']);
        self::assertContains('rollback_proven', $partialL5['missing_proof']);
        self::assertContains('drift_check_passed', $partialL5['missing_proof']);

        // L7->L8: three families — single strategic signal not enough.
        $partialL7 = $this->advisor->adviseNext(7, ['priority_engine_present' => true]);
        self::assertFalse($partialL7['is_promotable_now']);
        self::assertContains('build_graph_verified', $partialL7['missing_proof']);
        self::assertContains('metrics_proven', $partialL7['missing_proof']);
    }

    public function test_gating_signal_is_independent_of_unrelated_signals(): void
    {
        // L3 promotability is driven only by manual_command_passes, even when
        // every other ladder signal is true the gate stays closed while it is false.
        $result = $this->advisor->adviseNext(3, [
            'has_canonical_doc' => true,
            'has_spec' => true,
            'has_scaffold' => true,
            'manual_command_passes' => false,
            'agent_executable_with_receipt' => true,
            'repeated_safe_runs' => true,
            'learning_proposals_safe' => true,
            'strategic_selection_proven' => true,
        ]);

        self::assertSame(['manual_command_passes'], $result['missing_proof']);
        self::assertFalse($result['is_promotable_now']);
    }

    public function test_negative_level_clamps_up_to_floor_step(): void
    {
        $result = $this->advisor->adviseNext(-4, ['has_canonical_doc' => true]);

        self::assertSame('L0->L1', $result['next_promotion']);
        self::assertSame(1, $result['next_level']);
        self::assertTrue($result['is_promotable_now']);
        self::assertFalse($result['at_ceiling']);
    }

    public function test_non_strict_true_signal_does_not_satisfy_gate(): void
    {
        // Truthy-but-not-true (int 1, string) must NOT unlock promotion.
        $result = $this->advisor->adviseNext(2, ['has_scaffold' => 1]);

        self::assertSame(['has_scaffold'], $result['missing_proof']);
        self::assertFalse($result['is_promotable_now']);
    }

    public function test_advice_is_deterministic_for_identical_inputs(): void
    {
        $first = $this->advisor->adviseNext(5, ['repeated_safe_runs' => true]);
        $second = $this->advisor->adviseNext(5, ['repeated_safe_runs' => true]);

        self::assertSame($first, $second);
        self::assertSame('L5->L6', $first['next_promotion']);
        self::assertSame(6, $first['next_level']);
    }
}
