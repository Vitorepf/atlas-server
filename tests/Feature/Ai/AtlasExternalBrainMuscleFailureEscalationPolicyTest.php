<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleFailureEscalationPolicy;
use Tests\TestCase;

final class AtlasExternalBrainMuscleFailureEscalationPolicyTest extends TestCase
{
    private function policy(): AtlasExternalBrainMuscleFailureEscalationPolicy
    {
        return new AtlasExternalBrainMuscleFailureEscalationPolicy;
    }

    public function test_accepts_all_documented_root_causes(): void
    {
        foreach ([
            'give_back', 'test_failure', 'scope_violation', 'duplicate_capability',
            'malformed_acceptance', 'local_client_stall', 'repeated_retry',
        ] as $rootCause) {
            $result = $this->policy()->escalate(['root_cause' => $rootCause, 'repeat_count' => 1]);
            $this->assertSame($rootCause, $result['root_cause']);
        }
    }

    public function test_first_give_back_continues_retry(): void
    {
        $result = $this->policy()->escalate(['root_cause' => 'give_back', 'repeat_count' => 1]);

        $this->assertSame('continue_retry', $result['escalation_action']);
    }

    public function test_malformed_acceptance_repairs_task_spec(): void
    {
        $result = $this->policy()->escalate(['root_cause' => 'malformed_acceptance', 'repeat_count' => 1]);

        $this->assertSame('repair_task_spec', $result['escalation_action']);
    }

    public function test_duplicate_capability_always_quarantines(): void
    {
        $result = $this->policy()->escalate(['root_cause' => 'duplicate_capability', 'repeat_count' => 5]);

        $this->assertSame('quarantine_candidate', $result['escalation_action']);
    }

    public function test_local_client_stall_switches_muscle(): void
    {
        $result = $this->policy()->escalate(['root_cause' => 'local_client_stall', 'repeat_count' => 1]);

        $this->assertSame('switch_muscle', $result['escalation_action']);
    }

    public function test_repeated_failures_escalate_up_the_ladder(): void
    {
        $first = $this->policy()->escalate(['root_cause' => 'give_back', 'repeat_count' => 1]);
        $second = $this->policy()->escalate(['root_cause' => 'give_back', 'repeat_count' => 2]);
        $third = $this->policy()->escalate(['root_cause' => 'give_back', 'repeat_count' => 3]);
        $fourth = $this->policy()->escalate(['root_cause' => 'give_back', 'repeat_count' => 4]);

        $this->assertSame('continue_retry', $first['escalation_action']);
        $this->assertSame('adjust_prompt_variant', $second['escalation_action']);
        $this->assertSame('switch_muscle', $third['escalation_action']);
        $this->assertSame('operator_fix_required', $fourth['escalation_action']);
    }

    public function test_refuses_continue_retry_once_repeat_count_exceeds_threshold(): void
    {
        $result = $this->policy()->escalate([
            'root_cause' => 'repeated_retry',
            'repeat_count' => 1,
            'threshold' => 0,
        ]);

        $this->assertNotSame('continue_retry', $result['escalation_action']);
        $this->assertTrue($result['threshold_exceeded']);
        $this->assertTrue($result['forced_off_continue_retry']);
    }

    public function test_within_threshold_continue_retry_is_allowed(): void
    {
        $result = $this->policy()->escalate([
            'root_cause' => 'give_back',
            'repeat_count' => 1,
            'threshold' => 3,
        ]);

        $this->assertSame('continue_retry', $result['escalation_action']);
        $this->assertFalse($result['threshold_exceeded']);
        $this->assertFalse($result['forced_off_continue_retry']);
    }

    public function test_repeat_count_far_beyond_ladder_length_caps_at_final_step(): void
    {
        $result = $this->policy()->escalate(['root_cause' => 'scope_violation', 'repeat_count' => 50]);

        $this->assertSame('operator_fix_required', $result['escalation_action']);
    }

    public function test_unknown_root_cause_falls_back_to_default_ladder(): void
    {
        $result = $this->policy()->escalate(['root_cause' => 'something_unmapped', 'repeat_count' => 1]);

        $this->assertSame('adjust_prompt_variant', $result['escalation_action']);
    }
}
