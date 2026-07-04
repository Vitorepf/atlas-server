<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueGateBacktestReplay;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainValueGateBacktestReplayTest extends TestCase
{
    private AtlasExternalBrainValueGateBacktestReplay $replay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replay = new AtlasExternalBrainValueGateBacktestReplay();
    }

    // AC 2: tightening includes replay_reason and prevented_failure_modes for admitted poison
    public function test_tightening_for_poison_includes_reason_and_modes(): void
    {
        $result = $this->replay->replay([
            'admitted_poison_count' => 3,
            'poison_examples' => ['petreo_target', 'duplicate_task', 'test_only'],
            'rejected_green_count' => 0,
        ]);

        $this->assertSame('tighten_risk_ceiling', $result['action']);
        $this->assertNotNull($result['adjustment']);
        $this->assertNotEmpty($result['adjustment']['replay_reason']);
        $this->assertNotEmpty($result['adjustment']['prevented_failure_modes']);
        $this->assertContains('petreo_target', $result['adjustment']['prevented_failure_modes']);
    }

    // AC 3: loosening includes false_negative_risk when green tasks rejected
    public function test_loosening_for_green_includes_false_negative_risk(): void
    {
        $result = $this->replay->replay([
            'admitted_poison_count' => 0,
            'rejected_green_count' => 5,
            'total_admitted' => 10,
            'green_examples' => ['high_value_a', 'high_value_b'],
        ]);

        $this->assertSame('loosen_impact_floor', $result['action']);
        $this->assertNotNull($result['adjustment']);
        $this->assertGreaterThan(0, $result['adjustment']['false_negative_risk']);
    }

    // AC 4: no adjustment without non-empty audit_reason
    public function test_no_adjustment_has_non_empty_audit_reason(): void
    {
        $result = $this->replay->replay([
            'admitted_poison_count' => 0,
            'rejected_green_count' => 0,
        ]);

        $this->assertSame('no_adjustment', $result['action']);
        $this->assertNotEmpty($result['audit_reason']);
    }

    public function test_tightening_has_non_empty_audit_reason(): void
    {
        $result = $this->replay->replay(['admitted_poison_count' => 1]);

        $this->assertNotEmpty($result['audit_reason']);
    }

    public function test_empty_backtest_is_no_adjustment(): void
    {
        $result = $this->replay->replay([]);

        $this->assertSame('no_adjustment', $result['action']);
        $this->assertNull($result['adjustment']);
    }

    // AC: gate-passing candidates increment would_admit
    public function test_gate_passing_candidates_increment_would_admit(): void
    {
        $result = $this->replay->replayCandidates([
            ['task_packet_id' => 't1', 'outcome' => 'success', 'value_density' => 0.8, 'gate_passed' => true],
            ['task_packet_id' => 't2', 'outcome' => 'success', 'value_density' => 0.9, 'gate_passed' => true],
            ['task_packet_id' => 't3', 'outcome' => 'failed', 'value_density' => 0.2, 'gate_passed' => false],
        ]);

        $this->assertSame(2, $result['would_admit']);
        $this->assertSame(3, $result['total']);
    }

    // AC: rejected successful tasks increment false_reject_green
    public function test_rejected_successful_tasks_increment_false_reject_green(): void
    {
        $result = $this->replay->replayCandidates([
            ['task_packet_id' => 't1', 'outcome' => 'success', 'value_density' => 0.7, 'gate_passed' => false],
            ['task_packet_id' => 't2', 'outcome' => 'resolved', 'value_density' => 0.6, 'gate_passed' => false],
            ['task_packet_id' => 't3', 'outcome' => 'success', 'value_density' => 0.8, 'gate_passed' => true],
        ]);

        $this->assertSame(2, $result['false_reject_green']);
    }

    // AC: give_back or poison tasks rejected by gate increment true_reject_poison
    public function test_rejected_poison_tasks_increment_true_reject_poison(): void
    {
        $result = $this->replay->replayCandidates([
            ['task_packet_id' => 't1', 'outcome' => 'give_back', 'value_density' => 0.1, 'gate_passed' => false],
            ['task_packet_id' => 't2', 'outcome' => 'poison', 'value_density' => 0.05, 'gate_passed' => false],
            ['task_packet_id' => 't3', 'outcome' => 'success', 'value_density' => 0.9, 'gate_passed' => true],
        ]);

        $this->assertSame(2, $result['true_reject_poison']);
    }

    // AC: high poison rate produces threshold adjustment guidance
    public function test_high_poison_rate_produces_tighten_adjustment(): void
    {
        $candidates = [];
        for ($i = 0; $i < 10; $i++) {
            $candidates[] = ['task_packet_id' => "t{$i}", 'outcome' => 'poison', 'value_density' => 0.1, 'gate_passed' => false];
        }

        $result = $this->replay->replayCandidates($candidates, 0.5);

        $this->assertNotNull($result['threshold_adjustment']);
        $this->assertSame('tighten', $result['threshold_adjustment']['direction']);
        $this->assertGreaterThan(0.5, $result['threshold_adjustment']['suggested_threshold']);
    }

    // AC: high green miss rate produces loosen adjustment
    public function test_high_green_miss_rate_produces_loosen_adjustment(): void
    {
        $candidates = [];
        for ($i = 0; $i < 10; $i++) {
            $candidates[] = ['task_packet_id' => "t{$i}", 'outcome' => 'success', 'value_density' => 0.8, 'gate_passed' => false];
        }

        $result = $this->replay->replayCandidates($candidates, 0.5);

        $this->assertNotNull($result['threshold_adjustment']);
        $this->assertSame('loosen', $result['threshold_adjustment']['direction']);
        $this->assertLessThan(0.5, $result['threshold_adjustment']['suggested_threshold']);
    }

    // AC: clean gate produces no adjustment
    public function test_clean_gate_produces_no_adjustment(): void
    {
        $result = $this->replay->replayCandidates([
            ['task_packet_id' => 't1', 'outcome' => 'success', 'value_density' => 0.9, 'gate_passed' => true],
            ['task_packet_id' => 't2', 'outcome' => 'success', 'value_density' => 0.8, 'gate_passed' => true],
            ['task_packet_id' => 't3', 'outcome' => 'success', 'value_density' => 0.7, 'gate_passed' => true],
            ['task_packet_id' => 't4', 'outcome' => 'success', 'value_density' => 0.85, 'gate_passed' => true],
            ['task_packet_id' => 't5', 'outcome' => 'success', 'value_density' => 0.95, 'gate_passed' => true],
        ]);

        $this->assertNull($result['threshold_adjustment']);
    }
}
