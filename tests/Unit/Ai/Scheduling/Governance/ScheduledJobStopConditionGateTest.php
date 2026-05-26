<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Scheduling\Governance;

use App\Services\Ai\Scheduling\Governance\ScheduledJobStopConditionGate;
use Tests\TestCase;

final class ScheduledJobStopConditionGateTest extends TestCase
{
    private ScheduledJobStopConditionGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new ScheduledJobStopConditionGate;
    }

    private function readyContext(): array
    {
        return [
            'job_id' => 'self_improvement_review',
            'runs_today' => 4,
            'daily_budget' => 24,
            'consecutive_failures' => 0,
            'failure_threshold' => 3,
            'pending_proposals' => 0,
            'operator_pause_flag' => false,
            'provider_available' => true,
        ];
    }

    public function test_runs_when_all_conditions_pass(): void
    {
        $r = $this->gate->evaluate($this->readyContext());
        $this->assertTrue($r['may_run']);
        $this->assertSame([], $r['stop_reasons']);
        $this->assertSame('atlas.scheduling.stop_condition_gate.v1', $r['schema_version']);
    }

    public function test_blocks_when_budget_exhausted(): void
    {
        $c = $this->readyContext();
        $c['runs_today'] = 24;
        $r = $this->gate->evaluate($c);
        $this->assertFalse($r['may_run']);
        $this->assertContains('budget_exhausted', $r['stop_reasons']);
    }

    public function test_blocks_on_consecutive_failures(): void
    {
        $c = $this->readyContext();
        $c['consecutive_failures'] = 3;
        $r = $this->gate->evaluate($c);
        $this->assertContains('consecutive_failures_threshold', $r['stop_reasons']);
    }

    public function test_blocks_on_drift_signature_repeated(): void
    {
        $c = $this->readyContext();
        $c['last_failure_signature'] = 'X';
        $c['recent_signatures'] = ['X', 'X', 'X'];
        $r = $this->gate->evaluate($c);
        $this->assertContains('drift_signature_repeated', $r['stop_reasons']);
    }

    public function test_blocks_when_proposals_pending(): void
    {
        $c = $this->readyContext();
        $c['pending_proposals'] = 1;
        $r = $this->gate->evaluate($c);
        $this->assertContains('proposal_pending_review', $r['stop_reasons']);
    }

    public function test_blocks_when_operator_paused(): void
    {
        $c = $this->readyContext();
        $c['operator_pause_flag'] = true;
        $r = $this->gate->evaluate($c);
        $this->assertContains('operator_paused_via_flag', $r['stop_reasons']);
    }

    public function test_blocks_when_provider_unavailable(): void
    {
        $c = $this->readyContext();
        $c['provider_available'] = false;
        $r = $this->gate->evaluate($c);
        $this->assertContains('provider_dependency_unavailable', $r['stop_reasons']);
    }

    public function test_accumulates_multiple_stop_reasons(): void
    {
        $r = $this->gate->evaluate([
            'runs_today' => 99,
            'consecutive_failures' => 99,
            'pending_proposals' => 5,
            'operator_pause_flag' => true,
            'provider_available' => false,
        ]);
        $this->assertGreaterThanOrEqual(5, count($r['stop_reasons']));
    }

    public function test_envelope_shape_is_stable(): void
    {
        $r = $this->gate->evaluate($this->readyContext());
        $this->assertSame([
            'schema_version', 'may_run', 'job_id', 'evaluated_at',
            'stop_reasons', 'checks', 'detail',
        ], array_keys($r));
    }
}
