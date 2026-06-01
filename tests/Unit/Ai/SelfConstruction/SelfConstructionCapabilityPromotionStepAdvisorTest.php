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
            0 => ['label' => 'L0->L1', 'next_level' => 1, 'signal' => 'has_canonical_doc'],
            1 => ['label' => 'L1->L2', 'next_level' => 2, 'signal' => 'has_spec'],
            2 => ['label' => 'L2->L3', 'next_level' => 3, 'signal' => 'has_scaffold'],
            3 => ['label' => 'L3->L4', 'next_level' => 4, 'signal' => 'manual_command_passes'],
            4 => ['label' => 'L4->L5', 'next_level' => 5, 'signal' => 'agent_executable_with_receipt'],
            5 => ['label' => 'L5->L6', 'next_level' => 6, 'signal' => 'repeated_safe_runs'],
            6 => ['label' => 'L6->L7', 'next_level' => 7, 'signal' => 'learning_proposals_safe'],
            7 => ['label' => 'L7->L8', 'next_level' => 8, 'signal' => 'strategic_selection_proven'],
        ];

        // Exactly 8 distinct promotion steps cover the contiguous ladder L0..L8.
        self::assertCount(8, $expected);

        foreach ($expected as $level => $step) {
            // Gating signal absent => that step's single key is the missing proof.
            $blocked = $this->advisor->adviseNext($level, []);
            self::assertSame($step['label'], $blocked['next_promotion']);
            self::assertSame($step['next_level'], $blocked['next_level']);
            self::assertSame($level + 1, $blocked['next_level']);
            self::assertSame([$step['signal']], $blocked['missing_proof']);
            self::assertFalse($blocked['is_promotable_now']);
            self::assertFalse($blocked['at_ceiling']);
            self::assertNotNull($blocked['required_proof']);

            // The same step with only its gating signal true flips promotability.
            $cleared = $this->advisor->adviseNext($level, [$step['signal'] => true]);
            self::assertSame($step['label'], $cleared['next_promotion']);
            self::assertSame([], $cleared['missing_proof']);
            self::assertTrue($cleared['is_promotable_now']);
        }
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
