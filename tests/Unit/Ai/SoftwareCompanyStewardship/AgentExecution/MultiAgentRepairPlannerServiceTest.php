<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentRepairPlannerService;
use Tests\TestCase;

/**
 * AP-799 · a failed multi-agent task execution must become a governed repair
 * decision: capture the failure capsule, classify why it failed, and only then
 * decide whether a bounded repair is allowed — never running a provider, never
 * merging and never permanently quarantining a transient failure.
 */
final class MultiAgentRepairPlannerServiceTest extends TestCase
{
    private function service(): MultiAgentRepairPlannerService
    {
        return new MultiAgentRepairPlannerService();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function slice(array $overrides = []): array
    {
        return array_merge([
            'slice_id' => 'slice_1',
            'owner' => 'atlas_dev',
            'risk_level' => 'R2',
            'objective' => 'Harden the router selection.',
            'allowed_files' => ['app/Services/Ai/Example.php', 'tests/Unit/Ai/ExampleTest.php'],
            'forbidden_files' => ['config/app.php'],
            'validation_commands' => ['php artisan test tests/Unit/Ai/ExampleTest.php'],
            'retry_policy' => ['count' => 2, 'transient_retries' => 2],
            'max_runtime_seconds' => 900,
            'evidence_obligations' => ['focused_test_pass'],
            'provider_fit' => 'cursor_cli:composer-2.5',
        ], $overrides);
    }

    public function test_retryable_validation_failure_produces_repair_plan(): void
    {
        $plan = $this->service()->plan([
            'validation_result' => [
                'passed' => false,
                'exit_code' => 1,
                'failing_tests' => ['ExampleTest::test_router_selects_provider'],
                'stderr_excerpt' => 'Failed asserting that null matches expected provider.',
                'failed_command' => 'php artisan test tests/Unit/Ai/ExampleTest.php',
            ],
            'gate_failures' => [],
            'lane_result' => ['lane' => 'implementer', 'provider' => 'cursor_cli', 'status' => 'failed'],
            'executable_slice' => $this->slice(),
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Example.php'], 'diff_hash' => 'sha256:diff1'],
        ]);

        $this->assertSame(MultiAgentRepairPlannerService::CLASS_RETRYABLE_VALIDATION, $plan['classification']);
        $this->assertSame(MultiAgentRepairPlannerService::DECISION_REPAIR, $plan['repair_decision']);
        $this->assertTrue($plan['repair_allowed']);
        $this->assertSame(MultiAgentRepairPlannerService::BRANCH_REPAIR, $plan['repair_branch_strategy']);
        $this->assertNotNull($plan['repair_lane_input']);
        $this->assertSame('repair_agent', $plan['repair_lane_input']['lane']);
        $this->assertSame('repair_branch_worktree_only', $plan['repair_lane_input']['write_authority']);
        $this->assertContains('app/Services/Ai/Example.php', $plan['repair_lane_input']['allowed_repair_files']);
        $this->assertContains('php artisan test tests/Unit/Ai/ExampleTest.php', $plan['repair_lane_input']['validation_commands']);
        $this->assertSame([], $plan['blockers']);
        $this->assertSame(MultiAgentRepairPlannerService::FAILURE_CAPSULE_SCHEMA, $plan['failure_capsule']['schema_version']);
        $this->assertSame(['ExampleTest::test_router_selects_provider'], $plan['failure_capsule']['failing_tests']);
    }

    public function test_scope_violation_is_non_retryable(): void
    {
        $plan = $this->service()->plan([
            'validation_result' => ['passed' => false, 'exit_code' => 1, 'stderr_excerpt' => 'tests failed'],
            'gate_failures' => [],
            'lane_result' => ['lane' => 'implementer', 'status' => 'failed'],
            'executable_slice' => $this->slice(),
            // Touched a file outside allowed_files (and config/app.php is forbidden).
            'diff_summary' => ['changed_files' => ['config/app.php', 'app/Services/Ai/Other.php']],
        ]);

        $this->assertSame(MultiAgentRepairPlannerService::CLASS_SCOPE_VIOLATION, $plan['classification']);
        $this->assertSame(MultiAgentRepairPlannerService::DECISION_NON_RETRYABLE, $plan['repair_decision']);
        $this->assertFalse($plan['repair_allowed']);
        $this->assertNull($plan['repair_lane_input']);
        $this->assertContains('scope_violation', $plan['blockers']);
        $this->assertSame(MultiAgentRepairPlannerService::BRANCH_NONE, $plan['repair_branch_strategy']);
    }

    public function test_provider_timeout_is_transient_without_permanent_quarantine(): void
    {
        $plan = $this->service()->plan([
            'validation_result' => ['passed' => false],
            'gate_failures' => [],
            'lane_result' => [
                'lane' => 'implementer',
                'provider' => 'cursor_cli',
                'timed_out' => true,
                'error_codes' => ['timeout'],
                'status' => 'failed',
            ],
            'executable_slice' => $this->slice(),
            'diff_summary' => ['changed_files' => []],
        ]);

        $this->assertSame(MultiAgentRepairPlannerService::CLASS_PROVIDER_TIMEOUT, $plan['classification']);
        $this->assertSame(MultiAgentRepairPlannerService::DECISION_TRANSIENT_RETRY, $plan['repair_decision']);
        $this->assertTrue($plan['transient']);
        $this->assertFalse($plan['permanent_quarantine']);
        $this->assertSame(MultiAgentRepairPlannerService::BRANCH_REUSE_CANDIDATE, $plan['repair_branch_strategy']);
        $this->assertTrue($plan['repair_allowed']);
    }

    public function test_rate_limit_is_transient_without_permanent_quarantine(): void
    {
        $plan = $this->service()->plan([
            'validation_result' => ['passed' => false],
            'gate_failures' => [],
            'lane_result' => ['rate_limited' => true, 'error_codes' => ['429'], 'status' => 'failed'],
            'executable_slice' => $this->slice(),
            'diff_summary' => ['changed_files' => []],
        ]);

        $this->assertSame(MultiAgentRepairPlannerService::CLASS_RATE_LIMIT, $plan['classification']);
        $this->assertFalse($plan['permanent_quarantine']);
        $this->assertTrue($plan['transient']);
    }

    public function test_security_blocker_routes_to_operator_review(): void
    {
        $plan = $this->service()->plan([
            'validation_result' => ['passed' => false, 'exit_code' => 1],
            'gate_failures' => [['gate' => 'security_gate', 'reason' => 'hardcoded secret detected', 'kind' => 'security']],
            'lane_result' => ['lane' => 'implementer', 'status' => 'blocked'],
            'executable_slice' => $this->slice(),
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Example.php']],
        ]);

        $this->assertSame(MultiAgentRepairPlannerService::CLASS_SECURITY_BLOCKER, $plan['classification']);
        $this->assertSame(MultiAgentRepairPlannerService::DECISION_OPERATOR_REVIEW, $plan['repair_decision']);
        $this->assertFalse($plan['repair_allowed']);
        $this->assertNull($plan['repair_lane_input']);
        $this->assertContains('security_or_destructive_blocker', $plan['blockers']);
    }

    public function test_missing_dependency_requires_operator(): void
    {
        $plan = $this->service()->plan([
            'validation_result' => [
                'passed' => false,
                'exit_code' => 1,
                'stderr_excerpt' => 'Error: Class "App\\Services\\Missing\\Thing" not found',
            ],
            'gate_failures' => [],
            'lane_result' => ['lane' => 'implementer', 'status' => 'failed'],
            'executable_slice' => $this->slice(),
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Example.php']],
        ]);

        $this->assertSame(MultiAgentRepairPlannerService::CLASS_MISSING_DEPENDENCY, $plan['classification']);
        $this->assertSame(MultiAgentRepairPlannerService::DECISION_OPERATOR_REVIEW, $plan['repair_decision']);
        $this->assertFalse($plan['repair_allowed']);
        $this->assertContains('missing_dependency_operator_required', $plan['blockers']);
    }

    public function test_retry_exhausted_is_blocked(): void
    {
        $plan = $this->service()->plan([
            'validation_result' => [
                'passed' => false,
                'exit_code' => 1,
                'failing_tests' => ['ExampleTest::test_x'],
                'stderr_excerpt' => 'still failing',
            ],
            'gate_failures' => [],
            'lane_result' => ['lane' => 'implementer', 'status' => 'failed'],
            'executable_slice' => $this->slice(['retry_policy' => ['count' => 2]]),
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Example.php']],
            'retry_attempts_used' => 2,
        ]);

        $this->assertSame(MultiAgentRepairPlannerService::DECISION_BLOCKED_RETRY_EXHAUSTED, $plan['repair_decision']);
        $this->assertFalse($plan['repair_allowed']);
        $this->assertNull($plan['repair_lane_input']);
        $this->assertSame(0, $plan['retry_budget']['remaining']);
        $this->assertContains('retry_budget_exhausted', $plan['blockers']);
    }

    public function test_repeated_exhausted_signature_is_blocked_even_with_nominal_budget(): void
    {
        $input = [
            'validation_result' => [
                'passed' => false,
                'exit_code' => 1,
                'failing_tests' => ['ExampleTest::test_x'],
                'stderr_excerpt' => 'same dead end',
                'failed_command' => 'php artisan test tests/Unit/Ai/ExampleTest.php',
            ],
            'gate_failures' => [],
            'lane_result' => ['lane' => 'implementer', 'status' => 'failed'],
            'executable_slice' => $this->slice(['retry_policy' => ['count' => 2]]),
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Example.php']],
            'retry_attempts_used' => 0,
        ];

        // First call computes the signature; feed it back as an exhausted prior.
        $first = $this->service()->plan($input);
        $signature = $first['failure_capsule']['failure_signature'];

        $input['prior_capsules'] = [['failure_signature' => $signature, 'attempts' => 2]];
        $blocked = $this->service()->plan($input);

        $this->assertSame(MultiAgentRepairPlannerService::DECISION_REPAIR, $first['repair_decision']);
        $this->assertSame(MultiAgentRepairPlannerService::DECISION_BLOCKED_RETRY_EXHAUSTED, $blocked['repair_decision']);
        $this->assertFalse($blocked['repair_allowed']);
    }

    public function test_high_risk_slice_never_auto_repairs(): void
    {
        $plan = $this->service()->plan([
            'validation_result' => ['passed' => false, 'exit_code' => 1, 'failing_tests' => ['t'], 'stderr_excerpt' => 'fail'],
            'gate_failures' => [],
            'lane_result' => ['status' => 'failed'],
            'executable_slice' => $this->slice(['risk_level' => 'R5']),
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Example.php']],
        ]);

        $this->assertSame(0, $plan['retry_budget']['max']);
        $this->assertSame(MultiAgentRepairPlannerService::DECISION_BLOCKED_RETRY_EXHAUSTED, $plan['repair_decision']);
        $this->assertFalse($plan['repair_allowed']);
    }

    public function test_missing_validation_command_blocks_repair(): void
    {
        $plan = $this->service()->plan([
            'validation_result' => ['passed' => false, 'exit_code' => 1, 'failing_tests' => ['t'], 'stderr_excerpt' => 'fail'],
            'gate_failures' => [],
            'lane_result' => ['status' => 'failed'],
            'executable_slice' => $this->slice(['validation_commands' => []]),
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Example.php']],
        ]);

        $this->assertSame(MultiAgentRepairPlannerService::CLASS_RETRYABLE_VALIDATION, $plan['classification']);
        $this->assertSame(MultiAgentRepairPlannerService::DECISION_OPERATOR_REVIEW, $plan['repair_decision']);
        $this->assertFalse($plan['repair_allowed']);
        $this->assertContains('validation_command_missing', $plan['blockers']);
    }

    public function test_deterministic_repair_plan_hash(): void
    {
        $input = [
            'validation_result' => [
                'passed' => false,
                'exit_code' => 1,
                'failing_tests' => ['ExampleTest::test_x'],
                'stderr_excerpt' => 'Failed asserting something.',
                'failed_command' => 'php artisan test tests/Unit/Ai/ExampleTest.php',
            ],
            'gate_failures' => [],
            'lane_result' => ['lane' => 'implementer', 'status' => 'failed'],
            'executable_slice' => $this->slice(),
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Example.php'], 'diff_hash' => 'sha256:diff1'],
        ];

        $a = $this->service()->plan($input);
        $b = $this->service()->plan($input);

        $this->assertSame($a['repair_plan_hash'], $b['repair_plan_hash']);
        $this->assertStringStartsWith('sha256:', $a['repair_plan_hash']);
        $this->assertSame($a['failure_capsule']['capsule_hash'], $b['failure_capsule']['capsule_hash']);
        $this->assertSame($a['failure_capsule']['failure_signature'], $b['failure_capsule']['failure_signature']);
    }

    public function test_no_provider_or_merge_side_effects(): void
    {
        $plan = $this->service()->plan([
            'validation_result' => ['passed' => false, 'exit_code' => 1, 'failing_tests' => ['t'], 'stderr_excerpt' => 'fail'],
            'gate_failures' => [],
            'lane_result' => ['status' => 'failed'],
            'executable_slice' => $this->slice(),
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Example.php']],
        ]);

        $policy = $plan['claim_policy'];
        $this->assertFalse($policy['providers_invoked']);
        $this->assertFalse($policy['executes_provider']);
        $this->assertFalse($policy['runs_command']);
        $this->assertFalse($policy['merge_performed']);
        $this->assertFalse($policy['external_push_performed']);
        $this->assertFalse($policy['secret_access']);
        $this->assertFalse($policy['writes_session_store']);

        // The planner is dependency-free: it cannot reach a provider/merge runtime.
        $ctor = (new \ReflectionClass(MultiAgentRepairPlannerService::class))->getConstructor();
        $this->assertTrue($ctor === null || $ctor->getNumberOfParameters() === 0);
    }

    public function test_no_failure_means_no_repair_needed(): void
    {
        $plan = $this->service()->plan([
            'validation_result' => ['passed' => true, 'exit_code' => 0],
            'gate_failures' => [],
            'lane_result' => ['status' => 'completed'],
            'executable_slice' => $this->slice(),
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Example.php']],
        ]);

        $this->assertNull($plan['classification']);
        $this->assertSame(MultiAgentRepairPlannerService::DECISION_NO_REPAIR_NEEDED, $plan['repair_decision']);
        $this->assertFalse($plan['repair_allowed']);
        $this->assertNull($plan['repair_lane_input']);
    }
}
