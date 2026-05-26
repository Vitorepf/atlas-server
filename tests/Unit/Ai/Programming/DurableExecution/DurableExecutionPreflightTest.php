<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\DurableExecution;

use App\Services\Ai\Programming\DurableExecution\DurableExecutionPreflight;
use PHPUnit\Framework\TestCase;

/**
 * AP-283 / Safe Next Block #2 — Programming harness durable execution preflight.
 */
final class DurableExecutionPreflightTest extends TestCase
{
    private DurableExecutionPreflight $preflight;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preflight = new DurableExecutionPreflight;
    }

    private function readyish(): array
    {
        return [
            'work_item_id' => 'wi-1',
            'spec_hash' => 'sha:spec',
            'plan_hash' => 'sha:plan',
            'plan_json' => [
                'approval_status' => 'approved',
                'risk_band' => 'medium',
                'tests_to_run' => ['tests/FooTest.php'],
            ],
            'decision_receipt' => ['receipt_v2' => ['receipt_id' => 'rcpt-1']],
            'repair_attempts_so_far' => 0,
            'repair_budget' => 3,
        ];
    }

    public function test_ready_when_all_checks_pass(): void
    {
        $result = $this->preflight->evaluate($this->readyish());

        $this->assertSame('atlas.programming.durable_execution_preflight.v1', $result['schema_version']);
        $this->assertSame('AP-283', $result['ap_reference']);
        $this->assertTrue($result['may_execute']);
        $this->assertSame('ready_for_durable_execution', $result['status']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    public function test_blocks_when_spec_hash_missing(): void
    {
        $snap = $this->readyish();
        unset($snap['spec_hash']);
        $result = $this->preflight->evaluate($snap);

        $this->assertFalse($result['may_execute']);
        $this->assertContains('blocked_spec_missing', $result['blocking_reasons']);
    }

    public function test_blocks_when_plan_pending(): void
    {
        $snap = $this->readyish();
        $snap['plan_json']['approval_status'] = 'pending';
        $result = $this->preflight->evaluate($snap);

        $this->assertFalse($result['may_execute']);
        $this->assertContains('blocked_plan_not_approved', $result['blocking_reasons']);
    }

    public function test_blocks_when_plan_rejected(): void
    {
        $snap = $this->readyish();
        $snap['plan_json']['approval_status'] = 'rejected';
        $result = $this->preflight->evaluate($snap);

        $this->assertFalse($result['may_execute']);
        $this->assertContains('blocked_plan_not_approved', $result['blocking_reasons']);
    }

    public function test_blocks_high_risk_without_tests(): void
    {
        $snap = $this->readyish();
        $snap['plan_json']['risk_band'] = 'high';
        $snap['plan_json']['tests_to_run'] = [];
        $result = $this->preflight->evaluate($snap);

        $this->assertFalse($result['may_execute']);
        $this->assertContains('blocked_high_risk_without_tests', $result['blocking_reasons']);
    }

    public function test_blocks_critical_risk_without_tests(): void
    {
        $snap = $this->readyish();
        $snap['plan_json']['risk_band'] = 'critical';
        $snap['plan_json']['tests_to_run'] = [];
        $result = $this->preflight->evaluate($snap);

        $this->assertFalse($result['may_execute']);
        $this->assertContains('blocked_high_risk_without_tests', $result['blocking_reasons']);
    }

    public function test_blocks_when_decision_receipt_absent(): void
    {
        $snap = $this->readyish();
        unset($snap['decision_receipt']);
        $result = $this->preflight->evaluate($snap);

        $this->assertFalse($result['may_execute']);
        $this->assertContains('blocked_decision_receipt_missing', $result['blocking_reasons']);
    }

    public function test_blocks_when_repair_budget_exhausted(): void
    {
        $snap = $this->readyish();
        $snap['repair_attempts_so_far'] = 3;
        $snap['repair_budget'] = 3;
        $result = $this->preflight->evaluate($snap);

        $this->assertFalse($result['may_execute']);
        $this->assertContains('blocked_repair_budget_exhausted', $result['blocking_reasons']);
    }

    public function test_repair_budget_at_max_minus_one_passes(): void
    {
        $snap = $this->readyish();
        $snap['repair_attempts_so_far'] = 2;
        $snap['repair_budget'] = 3;
        $result = $this->preflight->evaluate($snap);

        $this->assertTrue($result['may_execute']);
        $this->assertSame(1, $result['evidence']['repair_budget_remaining']);
    }

    public function test_accumulates_multiple_blocking_reasons(): void
    {
        $result = $this->preflight->evaluate([
            // empty snapshot → spec missing, plan missing, receipt missing
        ]);

        $this->assertFalse($result['may_execute']);
        $this->assertGreaterThanOrEqual(3, count($result['blocking_reasons']));
        $this->assertContains('blocked_spec_missing', $result['blocking_reasons']);
        $this->assertContains('blocked_plan_not_approved', $result['blocking_reasons']);
        $this->assertContains('blocked_decision_receipt_missing', $result['blocking_reasons']);
    }

    public function test_evidence_block_carries_canonical_fields(): void
    {
        $result = $this->preflight->evaluate($this->readyish());

        $this->assertSame([
            'work_item_id',
            'spec_hash',
            'plan_hash',
            'plan_approval_status',
            'risk_level',
            'repair_budget_remaining',
            'decision_receipt_present',
        ], array_keys($result['evidence']));
        $this->assertTrue($result['evidence']['decision_receipt_present']);
        $this->assertSame('sha:spec', $result['evidence']['spec_hash']);
    }

    public function test_envelope_shape_is_stable(): void
    {
        $result = $this->preflight->evaluate($this->readyish());

        $this->assertSame([
            'schema_version',
            'ap_reference',
            'status',
            'may_execute',
            'blocking_reasons',
            'checks',
            'evidence',
        ], array_keys($result));
    }
}
