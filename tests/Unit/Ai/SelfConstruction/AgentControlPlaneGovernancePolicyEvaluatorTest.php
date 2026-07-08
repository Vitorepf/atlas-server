<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneGovernancePolicyEvaluator;
use PHPUnit\Framework\TestCase;

final class AgentControlPlaneGovernancePolicyEvaluatorTest extends TestCase
{
    private function evaluator(): AgentControlPlaneGovernancePolicyEvaluator
    {
        return new AgentControlPlaneGovernancePolicyEvaluator;
    }

    public function test_read_only_analysis_can_be_governance_policy_clear_while_every_runtime_flag_stays_false(): void
    {
        $matrix = $this->evaluator()->runtimeActionMatrix([
            'kill_switch_state' => 'armed',
            'budget_gate_state' => 'green',
        ], 'low');

        $readOnly = $matrix['read_only'];
        $this->assertSame('clear', $readOnly['classification']);
        $this->assertSame([], $readOnly['blockers']);

        foreach ($matrix as $lane => $entry) {
            foreach ($entry['runtime_safety'] as $flag => $value) {
                if ($flag === 'runtime_safety_all_false') {
                    $this->assertTrue($value, "lane {$lane} runtime_safety_all_false should be true");
                    continue;
                }
                $this->assertFalse($value, "lane {$lane} flag {$flag} must remain false regardless of classification");
            }
        }
    }

    public function test_dispatch_ledger_write_provider_call_apply_patch_and_self_programming_are_blocked_without_approval_or_unsafe_policy(): void
    {
        $matrix = $this->evaluator()->runtimeActionMatrix([
            'kill_switch_state' => 'armed',
            'budget_gate_state' => 'green',
            'operator_approval_present' => false,
        ], 'low');

        $this->assertSame('blocked', $matrix['dispatch']['classification']);
        $this->assertContains('operator_approval_required', $matrix['dispatch']['blockers']);

        $this->assertSame('blocked', $matrix['ledger_write']['classification']);
        $this->assertContains('operator_approval_required', $matrix['ledger_write']['blockers']);

        $this->assertSame('blocked', $matrix['provider_call']['classification']);
        $this->assertContains('operator_approval_required', $matrix['provider_call']['blockers']);

        $applyPatch = $this->evaluator()->evaluate(['action' => 'apply_patch', 'risk_level' => 'low'], [
            'kill_switch_state' => 'armed',
            'budget_gate_state' => 'green',
            'operator_approval_present' => false,
        ]);
        $this->assertSame('governance_policy_blocked', $applyPatch['status']);
        $this->assertContains('operator_approval_required', $applyPatch['blockers']);

        $this->assertSame('blocked', $matrix['self_programming']['classification']);
        $this->assertContains('self_programming_requires_separate_program_stage', $matrix['self_programming']['blockers']);

        // Unsafe policy state (kill switch not armed) blocks even a normally-clear lane.
        $unsafePolicy = $this->evaluator()->runtimeActionMatrix([
            'kill_switch_state' => 'tripped',
            'budget_gate_state' => 'green',
        ], 'low');
        $this->assertSame('blocked', $unsafePolicy['read_only']['classification']);
        $this->assertContains('kill_switch_not_armed:tripped', $unsafePolicy['read_only']['blockers']);
    }

    public function test_unknown_high_and_critical_risk_levels_are_fail_closed_with_a_blocker(): void
    {
        foreach (['unknown', 'not_a_real_risk_level', 'high', 'critical'] as $riskInput) {
            $result = $this->evaluator()->evaluate(['action' => 'read_only_analysis', 'risk_level' => $riskInput], [
                'kill_switch_state' => 'armed',
                'budget_gate_state' => 'green',
                'operator_approval_present' => false,
            ]);

            $this->assertSame('governance_policy_blocked', $result['status'], "risk_level={$riskInput} must fail closed");
            $this->assertContains('operator_approval_required', $result['blockers']);
            $this->assertTrue($result['approval_required']);
        }
    }

    public function test_runtime_action_matrix_never_grants_runtime_authority_even_when_clear(): void
    {
        $matrix = $this->evaluator()->runtimeActionMatrix([
            'kill_switch_state' => 'armed',
            'budget_gate_state' => 'green',
            'operator_approval_present' => true,
        ], 'low');

        $this->assertSame('clear', $matrix['dispatch']['classification']);
        $this->assertFalse($matrix['dispatch']['runtime_safety']['dispatch_allowed']);
        $this->assertFalse($matrix['dispatch']['runtime_safety']['approval_granted']);
    }
}
