<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchPlannerEligibilityEvaluator;
use Tests\TestCase;

final class AgentDispatchPlannerEligibilityEvaluatorTest extends TestCase
{
    private function evaluator(): AgentDispatchPlannerEligibilityEvaluator
    {
        return new AgentDispatchPlannerEligibilityEvaluator;
    }

    private function eligibleTask(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 't1',
            'allowed_files' => ['app/Demo/Foo.php'],
            'acceptance_criteria' => ['phpunit green'],
            'dependency_state' => 'ready',
        ], $overrides);
    }

    private function eligibleAgent(array $overrides = []): array
    {
        return array_merge([
            'agent_id' => 'a1',
            'status' => 'available',
            'kind' => 'worker',
            'capabilities' => [],
            'max_parallel_tasks' => 3,
            'current_task_count' => 0,
        ], $overrides);
    }

    // ── task eligibility: missing scope ──────────────────────────────────────────

    public function test_task_with_no_allowed_files_requires_repair(): void
    {
        $result = $this->evaluator()->evaluateTaskEligibility($this->eligibleTask(['allowed_files' => []]));

        self::assertSame(AgentDispatchPlannerEligibilityEvaluator::TASK_STATUS_REPAIR_REQUIRED, $result['status']);
        self::assertContains('missing_allowed_files', $result['reason_codes']);
    }

    // ── task eligibility: no runnable proof ──────────────────────────────────────

    public function test_task_with_no_acceptance_criteria_requires_repair(): void
    {
        $result = $this->evaluator()->evaluateTaskEligibility($this->eligibleTask(['acceptance_criteria' => []]));

        self::assertSame(AgentDispatchPlannerEligibilityEvaluator::TASK_STATUS_REPAIR_REQUIRED, $result['status']);
        self::assertContains('missing_acceptance_proof', $result['reason_codes']);
    }

    // ── task eligibility: blocked dependencies ───────────────────────────────────

    public function test_task_with_blocked_dependency_state_requires_repair(): void
    {
        $result = $this->evaluator()->evaluateTaskEligibility($this->eligibleTask(['dependency_state' => 'blocked']));

        self::assertSame(AgentDispatchPlannerEligibilityEvaluator::TASK_STATUS_REPAIR_REQUIRED, $result['status']);
        self::assertContains('dependency_blocked', $result['reason_codes']);
    }

    // ── task eligibility: test-only packet ───────────────────────────────────────

    public function test_test_only_allowed_files_requires_repair(): void
    {
        $result = $this->evaluator()->evaluateTaskEligibility($this->eligibleTask([
            'allowed_files' => ['tests/Unit/Demo/FooTest.php'],
        ]));

        self::assertSame(AgentDispatchPlannerEligibilityEvaluator::TASK_STATUS_REPAIR_REQUIRED, $result['status']);
        self::assertContains('test_only_packet', $result['reason_codes']);
    }

    // ── task eligibility: poison / quarantine (give_back_risk, stale, contradiction) ──

    public function test_high_give_back_risk_requires_quarantine_not_repair(): void
    {
        $result = $this->evaluator()->evaluateTaskEligibility($this->eligibleTask(['give_back_risk' => 0.9]));

        self::assertSame(AgentDispatchPlannerEligibilityEvaluator::TASK_STATUS_QUARANTINE_REQUIRED, $result['status']);
        self::assertContains('give_back_risk_above_quarantine_ceiling', $result['reason_codes']);
    }

    public function test_stale_duplicate_requires_quarantine(): void
    {
        $result = $this->evaluator()->evaluateTaskEligibility($this->eligibleTask(['is_stale_duplicate' => true]));

        self::assertSame(AgentDispatchPlannerEligibilityEvaluator::TASK_STATUS_QUARANTINE_REQUIRED, $result['status']);
        self::assertContains('stale_duplicate_detected', $result['reason_codes']);
    }

    public function test_contradiction_risk_requires_quarantine(): void
    {
        $result = $this->evaluator()->evaluateTaskEligibility($this->eligibleTask(['has_contradiction_risk' => true]));

        self::assertSame(AgentDispatchPlannerEligibilityEvaluator::TASK_STATUS_QUARANTINE_REQUIRED, $result['status']);
        self::assertContains('contradiction_risk_detected', $result['reason_codes']);
    }

    public function test_quarantine_takes_precedence_over_repair_reasons(): void
    {
        $result = $this->evaluator()->evaluateTaskEligibility($this->eligibleTask([
            'allowed_files' => [],
            'is_stale_duplicate' => true,
        ]));

        self::assertSame(AgentDispatchPlannerEligibilityEvaluator::TASK_STATUS_QUARANTINE_REQUIRED, $result['status']);
    }

    public function test_fully_valid_task_is_eligible_with_no_reason_codes(): void
    {
        $result = $this->evaluator()->evaluateTaskEligibility($this->eligibleTask());

        self::assertSame(AgentDispatchPlannerEligibilityEvaluator::TASK_STATUS_ELIGIBLE, $result['status']);
        self::assertSame([], $result['reason_codes']);
    }

    // ── agent eligibility: unavailable status ────────────────────────────────────

    public function test_agent_with_unavailable_status_is_ineligible(): void
    {
        $result = $this->evaluator()->evaluate(
            [$this->eligibleTask()],
            [$this->eligibleAgent(['status' => 'offline'])],
        );

        $evaluation = $result['evaluation_matrix'][0]['evaluations'][0];
        self::assertFalse($evaluation['is_eligible']);
        self::assertContains('agent_status_ineligible:offline', $evaluation['reasons']);
    }

    // ── agent eligibility: missing capabilities ──────────────────────────────────

    public function test_agent_missing_required_capabilities_is_ineligible(): void
    {
        $result = $this->evaluator()->evaluate(
            [$this->eligibleTask(['required_capabilities' => ['php_backend']])],
            [$this->eligibleAgent(['capabilities' => []])],
        );

        $evaluation = $result['evaluation_matrix'][0]['evaluations'][0];
        self::assertFalse($evaluation['is_eligible']);
        self::assertContains('capability_mismatch', $evaluation['reasons']);
    }

    // ── agent eligibility: capacity exhaustion ───────────────────────────────────

    public function test_agent_at_max_parallel_capacity_is_ineligible(): void
    {
        $result = $this->evaluator()->evaluate(
            [$this->eligibleTask()],
            [$this->eligibleAgent(['max_parallel_tasks' => 2, 'current_task_count' => 2])],
        );

        $evaluation = $result['evaluation_matrix'][0]['evaluations'][0];
        self::assertFalse($evaluation['is_eligible']);
        self::assertContains('capacity_full', $evaluation['reasons']);
    }

    // ── agent eligibility: incompatible kind ─────────────────────────────────────

    public function test_dry_run_agent_kind_requires_dry_run_only_task(): void
    {
        $result = $this->evaluator()->evaluate(
            [$this->eligibleTask(['dry_run_only' => false])],
            [$this->eligibleAgent(['kind' => 'dry_run_agent'])],
        );

        $evaluation = $result['evaluation_matrix'][0]['evaluations'][0];
        self::assertFalse($evaluation['is_eligible']);
        self::assertContains('dry_run_agent_requires_dry_run_only_task', $evaluation['reasons']);
    }

    // ── evaluate: eligible pairs require BOTH task and agent eligibility ────────

    public function test_evaluate_pair_is_eligible_only_when_agent_side_has_no_reasons(): void
    {
        $result = $this->evaluator()->evaluate(
            [$this->eligibleTask()],
            [$this->eligibleAgent()],
        );

        $evaluation = $result['evaluation_matrix'][0]['evaluations'][0];
        self::assertTrue($evaluation['is_eligible']);
        self::assertSame([], $evaluation['reasons']);
        self::assertSame(1, $result['eligible_pair_count']);
        self::assertSame(0, $result['ineligible_pair_count']);
    }

    public function test_evaluate_counts_eligible_and_ineligible_pairs_across_multiple_agents(): void
    {
        $result = $this->evaluator()->evaluate(
            [$this->eligibleTask()],
            [
                $this->eligibleAgent(['agent_id' => 'ok']),
                $this->eligibleAgent(['agent_id' => 'bad', 'status' => 'offline']),
            ],
        );

        self::assertSame(1, $result['eligible_pair_count']);
        self::assertSame(1, $result['ineligible_pair_count']);
        self::assertSame(2, $result['pair_total']);
    }

    // ── stable per-task evaluations (deterministic hash / structure) ─────────────

    public function test_eligibility_hash_is_deterministic_for_identical_inputs(): void
    {
        $evaluator = $this->evaluator();
        $tasks = [$this->eligibleTask()];
        $agents = [$this->eligibleAgent()];

        $a = $evaluator->evaluate($tasks, $agents)['eligibility_hash'];
        $b = $evaluator->evaluate($tasks, $agents)['eligibility_hash'];

        self::assertSame($a, $b);
    }

    public function test_evaluate_never_permits_any_execution_side_effect_flag(): void
    {
        $result = $this->evaluator()->evaluate([$this->eligibleTask()], [$this->eligibleAgent()]);

        foreach ([
            'runtime_execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'claim_real_allowed',
        ] as $flag) {
            self::assertFalse($result[$flag], "{$flag} must be false");
        }
    }

    // ── repair reasons distinguish packet repair from worker routing ────────────

    public function test_task_level_repair_reasons_are_disjoint_from_agent_level_routing_reasons(): void
    {
        $taskEligibility = $this->evaluator()->evaluateTaskEligibility($this->eligibleTask(['allowed_files' => []]));
        $pairResult = $this->evaluator()->evaluate(
            [$this->eligibleTask()],
            [$this->eligibleAgent(['status' => 'offline'])],
        );

        self::assertContains('missing_allowed_files', $taskEligibility['reason_codes']);
        self::assertContains('agent_status_ineligible:offline', $pairResult['evaluation_matrix'][0]['evaluations'][0]['reasons']);
        self::assertNotContains('missing_allowed_files', $pairResult['evaluation_matrix'][0]['evaluations'][0]['reasons']);
    }
}
