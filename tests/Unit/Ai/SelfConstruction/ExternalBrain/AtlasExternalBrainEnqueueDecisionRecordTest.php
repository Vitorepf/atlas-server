<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEnqueueDecisionRecord;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainEnqueueDecisionRecordTest extends TestCase
{
    private AtlasExternalBrainEnqueueDecisionRecord $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasExternalBrainEnqueueDecisionRecord();
    }

    // AC: enqueue refused without value_density and expected_compounding_effect
    public function test_enqueue_without_value_density_refused(): void
    {
        $result = $this->gate->record([
            'decision' => 'enqueue',
            'validation_evidence' => [],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing:value_density', $result['blockers']);
    }

    public function test_enqueue_without_compounding_effect_refused(): void
    {
        $result = $this->gate->record([
            'decision' => 'enqueue',
            'validation_evidence' => ['value_density' => 0.8],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing:expected_compounding_effect', $result['blockers']);
    }

    // AC: accepted enqueue includes value_density, expected_compounding_effect, operator_visible_reason
    public function test_accepted_enqueue_includes_required_fields(): void
    {
        $result = $this->gate->record([
            'decision' => 'enqueue',
            'validation_evidence' => [
                'value_density' => 0.9,
                'expected_compounding_effect' => 'reduces_give_back_risk',
            ],
            'expected_proof_path' => 'unit_tests',
            'operator_visible_reason' => 'High-value Brain family fix',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(0.9, $result['record']['value_density']);
        $this->assertSame('reduces_give_back_risk', $result['record']['expected_compounding_effect']);
        $this->assertSame('High-value Brain family fix', $result['record']['operator_visible_reason']);
    }

    // AC: defer/consolidate/reject require decline_reason but not enqueue evidence
    public function test_defer_with_decline_reason_accepted(): void
    {
        $result = $this->gate->record([
            'decision' => 'defer',
            'decline_reason' => 'awaiting prerequisite',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertArrayNotHasKey('value_density', $result['record']);
    }

    public function test_reject_without_decline_reason_refused(): void
    {
        $result = $this->gate->record([
            'decision' => 'reject',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing:decline_reason', $result['blockers']);
    }

    public function test_consolidate_with_reason_accepted(): void
    {
        $result = $this->gate->record([
            'decision' => 'consolidate',
            'decline_reason' => 'duplicate of existing task',
        ]);

        $this->assertTrue($result['accepted']);
    }

    // ── why-this-task-now fields ──

    public function test_enqueue_without_proof_path_is_refused(): void
    {
        $result = $this->gate->record([
            'decision' => 'enqueue',
            'validation_evidence' => [
                'value_density' => 0.9,
                'expected_compounding_effect' => 'reduces_give_back_risk',
            ],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing:expected_proof_path', $result['blockers']);
    }

    public function test_accepted_enqueue_includes_all_why_this_task_now_fields(): void
    {
        $result = $this->gate->record([
            'decision' => 'enqueue',
            'validation_evidence' => [
                'value_density' => 0.9,
                'expected_compounding_effect' => 'reduces_give_back_risk',
            ],
            'alternatives_considered' => ['task_a', 'task_b'],
            'chosen_leverage_reason' => 'highest_compounding_effect',
            'rejected_padding_risk' => 'low_value_docs_sync',
            'expected_proof_path' => 'unit_tests_and_gate',
            'duplicate_check_summary' => 'no_existing_match',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertSame(['task_a', 'task_b'], $result['record']['alternatives_considered']);
        $this->assertSame('highest_compounding_effect', $result['record']['chosen_leverage_reason']);
        $this->assertSame('low_value_docs_sync', $result['record']['rejected_padding_risk']);
        $this->assertSame('unit_tests_and_gate', $result['record']['expected_proof_path']);
        $this->assertSame('no_existing_match', $result['record']['duplicate_check_summary']);
        $this->assertFalse($result['record']['invalid']);
    }

    public function test_alternatives_without_leverage_reason_is_invalid(): void
    {
        $result = $this->gate->record([
            'decision' => 'enqueue',
            'validation_evidence' => [
                'value_density' => 0.9,
                'expected_compounding_effect' => 'reduces_give_back_risk',
            ],
            'alternatives_considered' => ['task_a'],
            'chosen_leverage_reason' => '',
            'expected_proof_path' => 'unit_tests',
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertContains('missing:chosen_leverage_reason', $result['blockers']);
    }

    public function test_enqueue_with_proof_path_and_no_alternatives_is_valid(): void
    {
        $result = $this->gate->record([
            'decision' => 'enqueue',
            'validation_evidence' => [
                'value_density' => 0.9,
                'expected_compounding_effect' => 'reduces_give_back_risk',
            ],
            'expected_proof_path' => 'unit_tests',
        ]);

        $this->assertTrue($result['accepted']);
        $this->assertFalse($result['record']['invalid']);
        $this->assertSame([], $result['record']['alternatives_considered']);
    }
}
