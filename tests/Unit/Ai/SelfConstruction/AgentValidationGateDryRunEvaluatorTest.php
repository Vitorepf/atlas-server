<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateDryRunEvaluator;
use PHPUnit\Framework\TestCase;

final class AgentValidationGateDryRunEvaluatorTest extends TestCase
{
    private function evaluator(): AgentValidationGateDryRunEvaluator
    {
        return new AgentValidationGateDryRunEvaluator;
    }

    private function plan(array $runs): array
    {
        return ['plan_id' => 'plan-1', 'plan_hash' => 'hash-1', 'ordered_runs' => $runs];
    }

    public function test_blocking_failure_records_abort_trace_with_aborted_gate_and_skipped_downstream_ids(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => true],
            ['gate_id' => 'gate_b', 'blocking' => false],
            ['gate_id' => 'gate_c', 'blocking' => false],
        ]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'fail', 'evidence_artifact' => 'artifact'],
        ]);

        $this->assertTrue($result['aborted']);
        $this->assertSame('gate_a', $result['abort_trace']['aborted_at_gate']);
        $this->assertSame(['gate_b', 'gate_c'], $result['abort_trace']['skipped_gate_ids']);
    }

    public function test_unsupported_synthetic_status_reports_unsupported_reason_and_counts_as_unknown(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => false],
        ]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'not_a_real_status', 'evidence_artifact' => 'artifact'],
        ]);

        $this->assertSame('unsupported_synthetic_status', $result['evaluations'][0]['observed_reason']);
        $this->assertSame(1, $result['counts']['unknown']);
    }

    public function test_missing_synthetic_input_on_blocking_gate_aborts_downstream_gates(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => true],
            ['gate_id' => 'gate_b', 'blocking' => false],
        ]);
        $result = $this->evaluator()->evaluate($plan, []);

        $this->assertSame('no_synthetic_input', $result['evaluations'][0]['observed_reason']);
        $this->assertSame('previous_blocking_gate_failed', $result['evaluations'][1]['observed_reason']);
        $this->assertTrue($result['aborted']);
        $this->assertSame(['gate_b'], $result['abort_trace']['skipped_gate_ids']);
    }

    public function test_no_abort_yields_null_abort_trace(): void
    {
        $plan = $this->plan([
            ['gate_id' => 'gate_a', 'blocking' => false],
        ]);
        $result = $this->evaluator()->evaluate($plan, [
            'gate_a' => ['status' => 'pass', 'evidence_artifact' => 'artifact'],
        ]);

        $this->assertFalse($result['aborted']);
        $this->assertNull($result['abort_trace']);
    }
}
