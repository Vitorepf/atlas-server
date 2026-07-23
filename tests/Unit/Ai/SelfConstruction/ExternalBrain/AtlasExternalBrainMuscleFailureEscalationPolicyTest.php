<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleFailureEscalationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves the failure-class taxonomy layer of AtlasExternalBrainMuscleFailureEscalationPolicy:
 * failures classify into task_poison, worker_mismatch, flaky_test, scope_gap, proof_gap, or
 * infrastructure_contention, each mapped to a recommended_action (repair/respec/reroute/pause/
 * escalate), and "add more workers" is refused unless the failure is a genuine infrastructure
 * contention.
 */
final class AtlasExternalBrainMuscleFailureEscalationPolicyTest extends TestCase
{
    private function policy(): AtlasExternalBrainMuscleFailureEscalationPolicy
    {
        return new AtlasExternalBrainMuscleFailureEscalationPolicy;
    }

    // ── AC1: classifies into the 6 canonical failure classes ────────────────────

    public function test_repeated_give_back_classifies_as_task_poison_and_recommends_respec(): void
    {
        $result = $this->policy()->escalate(['root_cause' => 'give_back', 'repeat_count' => 3]);

        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::CLASS_TASK_POISON, $result['failure_class']);
        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::RECOMMEND_RESPEC, $result['recommended_action']);
    }

    public function test_flaky_test_signal_classifies_as_flaky_test_and_recommends_repair(): void
    {
        $result = $this->policy()->escalate([
            'root_cause' => 'test_failure',
            'repeat_count' => 1,
            'is_flaky' => true,
        ]);

        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::CLASS_FLAKY_TEST, $result['failure_class']);
        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::RECOMMEND_REPAIR, $result['recommended_action']);
    }

    public function test_worker_mismatch_signal_classifies_as_worker_mismatch_and_recommends_reroute(): void
    {
        $result = $this->policy()->escalate([
            'root_cause' => 'give_back',
            'repeat_count' => 1,
            'worker_mismatch' => true,
        ]);

        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::CLASS_WORKER_MISMATCH, $result['failure_class']);
        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::RECOMMEND_REROUTE, $result['recommended_action']);
    }

    public function test_local_client_stall_classifies_as_worker_mismatch(): void
    {
        $result = $this->policy()->escalate(['root_cause' => 'local_client_stall', 'repeat_count' => 1]);

        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::CLASS_WORKER_MISMATCH, $result['failure_class']);
    }

    public function test_scope_violation_count_classifies_as_scope_gap_and_recommends_respec(): void
    {
        $result = $this->policy()->escalate([
            'root_cause' => 'give_back',
            'repeat_count' => 1,
            'scope_violation_count' => 2,
        ]);

        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::CLASS_SCOPE_GAP, $result['failure_class']);
        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::RECOMMEND_RESPEC, $result['recommended_action']);
    }

    public function test_missing_evidence_count_classifies_as_proof_gap_and_recommends_repair(): void
    {
        $result = $this->policy()->escalate([
            'root_cause' => 'give_back',
            'repeat_count' => 1,
            'missing_evidence_count' => 1,
        ]);

        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::CLASS_PROOF_GAP, $result['failure_class']);
        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::RECOMMEND_REPAIR, $result['recommended_action']);
    }

    public function test_infrastructure_contention_signal_classifies_correctly_and_recommends_pause(): void
    {
        $result = $this->policy()->escalate([
            'root_cause' => 'give_back',
            'repeat_count' => 1,
            'infrastructure_contention' => true,
        ]);

        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::CLASS_INFRASTRUCTURE_CONTENTION, $result['failure_class']);
        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::RECOMMEND_PAUSE, $result['recommended_action']);
    }

    public function test_explicit_failure_class_fact_overrides_derived_classification(): void
    {
        $result = $this->policy()->escalate([
            'root_cause' => 'give_back',
            'repeat_count' => 1,
            'failure_class' => 'flaky_test',
        ]);

        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::CLASS_FLAKY_TEST, $result['failure_class']);
    }

    // ── AC3: unsafe "add more workers" rejection ────────────────────────────────

    public function test_add_more_workers_is_rejected_for_task_poison(): void
    {
        $result = $this->policy()->escalate([
            'root_cause' => 'give_back',
            'repeat_count' => 3,
            'requested_action' => 'add_more_workers',
        ]);

        $this->assertTrue($result['requested_action_rejected']);
        $this->assertNotEmpty($result['requested_action_rejection_reason']);
    }

    public function test_add_more_workers_is_rejected_for_scope_gap(): void
    {
        $result = $this->policy()->escalate([
            'root_cause' => 'give_back',
            'repeat_count' => 1,
            'scope_violation_count' => 1,
            'requested_action' => 'add_more_workers',
        ]);

        $this->assertTrue($result['requested_action_rejected']);
    }

    public function test_add_more_workers_is_allowed_for_infrastructure_contention(): void
    {
        $result = $this->policy()->escalate([
            'root_cause' => 'give_back',
            'repeat_count' => 1,
            'infrastructure_contention' => true,
            'requested_action' => 'add_more_workers',
        ]);

        $this->assertFalse($result['requested_action_rejected']);
        $this->assertNull($result['requested_action_rejection_reason']);
    }

    public function test_no_requested_action_never_flags_rejection(): void
    {
        $result = $this->policy()->escalate(['root_cause' => 'give_back', 'repeat_count' => 1]);

        $this->assertFalse($result['requested_action_rejected']);
    }

    // ── default classification never silently blames worker capacity ───────────

    public function test_unknown_signals_default_to_task_poison_not_worker_mismatch(): void
    {
        $result = $this->policy()->escalate(['root_cause' => 'something_unmapped', 'repeat_count' => 1]);

        $this->assertSame(AtlasExternalBrainMuscleFailureEscalationPolicy::CLASS_TASK_POISON, $result['failure_class']);
    }

    // ── failureEscalation: next_action, escalation_level, root_cause, retry_blocked_reason ──

    public function test_give_back_below_threshold_allows_retry(): void
    {
        $r = (new AtlasExternalBrainMuscleFailureEscalationPolicy)->failureEscalation([
            'root_cause' => 'give_back',
            'repeat_count' => 1,
            'max_repeat_count' => 3,
        ]);
        $this->assertSame('retry_with_adjusted_prompt', $r['next_action']);
        $this->assertSame('info', $r['escalation_level']);
        $this->assertSame('give_back', $r['root_cause']);
        $this->assertStringContainsString('may retry', $r['retry_blocked_reason']);
    }

    public function test_give_back_at_threshold_blocks_retry(): void
    {
        $r = (new AtlasExternalBrainMuscleFailureEscalationPolicy)->failureEscalation([
            'root_cause' => 'give_back',
            'repeat_count' => 3,
            'max_repeat_count' => 3,
        ]);
        $this->assertSame('respec_task', $r['next_action']);
        $this->assertSame('critical', $r['escalation_level']);
        $this->assertStringContainsString('exceeds threshold', $r['retry_blocked_reason']);
    }

    public function test_scope_violation_maps_to_respec_with_scope_repair(): void
    {
        $r = (new AtlasExternalBrainMuscleFailureEscalationPolicy)->failureEscalation([
            'root_cause' => 'scope_violation',
            'repeat_count' => 1,
        ]);
        $this->assertSame('respec_task_with_scope_repair', $r['next_action']);
    }

    public function test_local_client_stall_maps_to_switch_muscle(): void
    {
        $r = (new AtlasExternalBrainMuscleFailureEscalationPolicy)->failureEscalation([
            'root_cause' => 'local_client_stall',
            'repeat_count' => 1,
        ]);
        $this->assertSame('switch_muscle', $r['next_action']);
    }
}
