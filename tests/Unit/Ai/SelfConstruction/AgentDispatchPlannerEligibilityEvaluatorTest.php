<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchPlannerEligibilityEvaluator;
use Tests\TestCase;

final class AgentDispatchPlannerEligibilityEvaluatorTest extends TestCase
{
    private AgentDispatchPlannerEligibilityEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new AgentDispatchPlannerEligibilityEvaluator;
    }

    private function validTask(array $overrides = []): array
    {
        return array_replace([
            'task_packet_id' => 'tp-1',
            'risk_level' => 'low',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['phpunit passes'],
            'dependency_state' => 'ready',
        ], $overrides);
    }

    private function validAgent(array $overrides = []): array
    {
        return array_replace([
            'agent_id' => 'agent-1',
            'status' => 'available',
            'capabilities' => ['php'],
            'max_parallel_tasks' => 3,
            'current_task_count' => 0,
        ], $overrides);
    }

    // ── AC: evaluate() returns eligible and ineligible pairs with blockers ──

    public function test_evaluate_returns_required_output_keys(): void
    {
        $result = $this->evaluator->evaluate([$this->validTask()], [$this->validAgent()]);

        foreach (['schema_version', 'mode', 'evaluation_matrix', 'eligible_pair_count', 'ineligible_pair_count', 'pair_total', 'eligibility_hash'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_evaluate_eligible_pair_for_matching_task_and_agent(): void
    {
        $result = $this->evaluator->evaluate([$this->validTask()], [$this->validAgent()]);

        $this->assertSame(1, $result['eligible_pair_count']);
        $this->assertSame(0, $result['ineligible_pair_count']);
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertTrue($eval['is_eligible']);
        $this->assertSame([], $eval['reasons']);
    }

    public function test_evaluate_ineligible_when_capability_mismatch(): void
    {
        $result = $this->evaluator->evaluate(
            [$this->validTask(['required_capabilities' => ['python']])],
            [$this->validAgent(['capabilities' => ['php']])],
        );

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertFalse($eval['is_eligible']);
        $this->assertContains('capability_mismatch', $eval['reasons']);
    }

    public function test_evaluate_ineligible_when_capacity_full(): void
    {
        $result = $this->evaluator->evaluate(
            [$this->validTask()],
            [$this->validAgent(['max_parallel_tasks' => 2, 'current_task_count' => 2])],
        );

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertFalse($eval['is_eligible']);
        $this->assertContains('capacity_full', $eval['reasons']);
    }

    public function test_evaluate_ineligible_when_agent_quarantined(): void
    {
        $result = $this->evaluator->evaluate(
            [$this->validTask()],
            [$this->validAgent(['agent_id' => 'quarantined-agent'])],
            ['quarantined_agents' => ['quarantined-agent']],
        );

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertFalse($eval['is_eligible']);
        $this->assertContains('agent_quarantined', $eval['reasons']);
    }

    public function test_evaluate_ineligible_when_agent_status_not_available(): void
    {
        $result = $this->evaluator->evaluate(
            [$this->validTask()],
            [$this->validAgent(['status' => 'offline'])],
        );

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertFalse($eval['is_eligible']);
    }

    public function test_evaluate_ineligible_for_high_risk_without_human_approval(): void
    {
        $result = $this->evaluator->evaluate(
            [$this->validTask(['risk_level' => 'high'])],
            [$this->validAgent(['capabilities' => ['php']])],
        );

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertFalse($eval['is_eligible']);
        $this->assertTrue(in_array('human_approval_required_for_risk:high', $eval['reasons'], true));
    }

    public function test_evaluate_eligible_for_high_risk_with_human_approval(): void
    {
        $result = $this->evaluator->evaluate(
            [$this->validTask(['risk_level' => 'high'])],
            [$this->validAgent(['capabilities' => ['php', 'human_approval']])],
        );

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertTrue($eval['is_eligible']);
    }

    public function test_evaluate_ineligible_when_evidence_required_but_agent_not_ready(): void
    {
        $result = $this->evaluator->evaluate(
            [$this->validTask(['evidence_required' => true])],
            [$this->validAgent(['evidence_required' => false])],
        );

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertFalse($eval['is_eligible']);
        $this->assertContains('evidence_required_but_agent_not_evidence_ready', $eval['reasons']);
    }

    // ── AC: evaluateTaskEligibility() ───────────────────────────────────────

    public function test_evaluate_task_eligibility_returns_eligible_for_valid_task(): void
    {
        $result = $this->evaluator->evaluateTaskEligibility($this->validTask());

        $this->assertSame('eligible', $result['status']);
        $this->assertSame([], $result['reason_codes']);
    }

    public function test_evaluate_task_eligibility_repair_required_for_missing_allowed_files(): void
    {
        $result = $this->evaluator->evaluateTaskEligibility($this->validTask(['allowed_files' => []]));

        $this->assertSame('repair_required', $result['status']);
        $this->assertContains('missing_allowed_files', $result['reason_codes']);
    }

    public function test_evaluate_task_eligibility_repair_required_for_missing_acceptance(): void
    {
        $result = $this->evaluator->evaluateTaskEligibility($this->validTask(['acceptance_criteria' => []]));

        $this->assertSame('repair_required', $result['status']);
        $this->assertContains('missing_acceptance_proof', $result['reason_codes']);
    }

    public function test_evaluate_task_eligibility_repair_required_for_blocked_dependency(): void
    {
        $result = $this->evaluator->evaluateTaskEligibility($this->validTask(['dependency_state' => 'blocked']));

        $this->assertSame('repair_required', $result['status']);
        $this->assertContains('dependency_blocked', $result['reason_codes']);
    }

    public function test_evaluate_task_eligibility_quarantine_for_high_give_back_risk(): void
    {
        $result = $this->evaluator->evaluateTaskEligibility($this->validTask(['give_back_risk' => 0.9]));

        $this->assertSame('quarantine_required', $result['status']);
        $this->assertContains('give_back_risk_above_quarantine_ceiling', $result['reason_codes']);
    }

    public function test_evaluate_task_eligibility_quarantine_for_stale_duplicate(): void
    {
        $result = $this->evaluator->evaluateTaskEligibility($this->validTask(['is_stale_duplicate' => true]));

        $this->assertSame('quarantine_required', $result['status']);
        $this->assertContains('stale_duplicate_detected', $result['reason_codes']);
    }

    public function test_evaluate_task_eligibility_quarantine_for_contradiction_risk(): void
    {
        $result = $this->evaluator->evaluateTaskEligibility($this->validTask(['has_contradiction_risk' => true]));

        $this->assertSame('quarantine_required', $result['status']);
    }

    // ── AC: agent capacity, risk tier, gate runner, evidence, lease state ───

    public function test_evaluate_pair_includes_free_slots(): void
    {
        $result = $this->evaluator->evaluate(
            [$this->validTask()],
            [$this->validAgent(['max_parallel_tasks' => 5, 'current_task_count' => 2])],
        );

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertSame(3, $eval['free_slots']);
    }

    public function test_evaluate_pair_includes_risk_level(): void
    {
        $result = $this->evaluator->evaluate(
            [$this->validTask(['risk_level' => 'medium'])],
            [$this->validAgent()],
        );

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertSame('medium', $eval['risk_level']);
    }

    public function test_evaluate_pair_includes_flags(): void
    {
        $result = $this->evaluator->evaluate([$this->validTask()], [$this->validAgent()]);

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertArrayHasKey('flags', $eval);
        $this->assertTrue($eval['flags']['capability_match']);
        $this->assertTrue($eval['flags']['capacity_available']);
        $this->assertTrue($eval['flags']['not_quarantined']);
    }

    // ── dry run agent restrictions ──────────────────────────────────────────

    public function test_evaluate_ineligible_when_dry_run_agent_on_non_dry_run_task(): void
    {
        $result = $this->evaluator->evaluate(
            [$this->validTask(['dry_run_only' => false])],
            [$this->validAgent(['kind' => 'dry_run_agent'])],
        );

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertFalse($eval['is_eligible']);
    }

    public function test_evaluate_eligible_when_dry_run_agent_on_dry_run_task(): void
    {
        $result = $this->evaluator->evaluate(
            [$this->validTask(['dry_run_only' => true])],
            [$this->validAgent(['kind' => 'dry_run_agent'])],
        );

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertTrue($eval['is_eligible']);
    }

    // ── workspace isolation ─────────────────────────────────────────────────

    public function test_evaluate_ineligible_when_workspace_required_but_unsupported(): void
    {
        $result = $this->evaluator->evaluate(
            [$this->validTask(['workspace_policy' => 'isolated'])],
            [$this->validAgent(['workspace_isolation_supported' => false])],
        );

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertFalse($eval['is_eligible']);
    }

    public function test_evaluate_eligible_when_workspace_required_and_supported(): void
    {
        $result = $this->evaluator->evaluate(
            [$this->validTask(['workspace_policy' => 'isolated'])],
            [$this->validAgent(['workspace_isolation_supported' => true, 'capabilities' => ['php', 'workspace_isolation']])],
        );

        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertTrue($eval['is_eligible']);
    }

    // ── runtime safety ──────────────────────────────────────────────────────

    public function test_runtime_flags_all_false(): void
    {
        $flags = $this->evaluator->runtimeFlags();

        foreach ($flags as $flag => $value) {
            $this->assertFalse($value, "{$flag} must be false");
        }
    }

    public function test_evaluate_never_allows_execution(): void
    {
        $result = $this->evaluator->evaluate([$this->validTask()], [$this->validAgent()]);

        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['claim_real_allowed']);
    }

    // ── determinism ─────────────────────────────────────────────────────────

    public function test_eligibility_hash_is_hex64(): void
    {
        $result = $this->evaluator->evaluate([$this->validTask()], [$this->validAgent()]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['eligibility_hash']);
    }

    public function test_evaluate_deterministic(): void
    {
        $tasks = [$this->validTask()];
        $agents = [$this->validAgent()];

        $a = $this->evaluator->evaluate($tasks, $agents);
        $b = $this->evaluator->evaluate($tasks, $agents);

        $this->assertSame($a['eligibility_hash'], $b['eligibility_hash']);
    }
}
