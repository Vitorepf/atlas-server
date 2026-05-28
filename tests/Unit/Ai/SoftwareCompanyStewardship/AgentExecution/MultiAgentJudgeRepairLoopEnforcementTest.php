<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentCycleCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentIntegrationJudgeService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentRepairPlannerService;
use Tests\TestCase;

/**
 * AP-803 — enforce the real judge + repair loop for multi-agent stewardship.
 *
 * The judge blocks bad work with one auditable decision enum and never patches;
 * repair runs only within scope and budget and only for repairable failures;
 * transient provider failures are never a permanent quarantine; a failed repair
 * never reads as success; and certification refuses a production pass without a
 * real judge accept, evidence and an in-budget repair. Product Mode exposes the
 * judge/repair state.
 */
final class MultiAgentJudgeRepairLoopEnforcementTest extends TestCase
{
    private function judge(): MultiAgentIntegrationJudgeService
    {
        return app(MultiAgentIntegrationJudgeService::class);
    }

    private function repair(): MultiAgentRepairPlannerService
    {
        return app(MultiAgentRepairPlannerService::class);
    }

    private function cert(): MultiAgentCycleCertificationService
    {
        return new MultiAgentCycleCertificationService;
    }

    // ---------- judge inputs ----------

    /**
     * A clean multi-agent result: in scope, validation green, evidence complete,
     * reviewer approves, diff shape matches, risk policy consistent.
     *
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function judgeInput(array $over = []): array
    {
        $base = [
            'lane_plan' => [
                'task_id' => 'task-803',
                'slice_id' => 'slice-803',
                'owner' => 'atlas_dev',
                'risk_level' => 'medium',
                'allowed_files' => ['app/Services/Ai/Foo/**', 'tests/Unit/Ai/Foo/**'],
                'forbidden_files' => ['app/Services/Ai/Foo/Secret.php'],
                'expected_diff_shape' => 'service_and_test',
                'validation_commands' => ['php artisan test --filter=Foo'],
                'evidence_obligations' => ['validation_log', 'diff', 'receipt'],
                'merge_policy' => 'review_required',
                'expected_lanes' => ['implementer', 'reviewer'],
                'repair_policy' => ['allowed' => true, 'max_attempts' => 2, 'attempts_used' => 0],
            ],
            'lane_results' => [
                ['lane' => 'implementer', 'status' => 'completed', 'performed_actions' => ['edit_files', 'run_tests']],
                ['lane' => 'reviewer', 'status' => 'completed', 'review' => ['decision' => 'approve', 'blockers' => []]],
            ],
            'validation_result' => ['ran' => true, 'passed' => true],
            'diff_summary' => [
                'changed_files' => ['app/Services/Ai/Foo/Bar.php', 'tests/Unit/Ai/Foo/BarTest.php'],
                'diff_shape' => 'service_and_test',
            ],
            'evidence_refs' => [
                ['kind' => 'validation_log'],
                ['kind' => 'diff'],
                ['kind' => 'receipt'],
            ],
        ];

        if (isset($over['lane_plan']) && is_array($over['lane_plan'])) {
            $base['lane_plan'] = array_replace($base['lane_plan'], $over['lane_plan']);
            unset($over['lane_plan']);
        }

        return array_replace($base, $over);
    }

    // ---------- 1. judge accepts a good result ----------

    public function test_judge_accepts_when_tests_evidence_and_scope_ok(): void
    {
        $r = $this->judge()->judge($this->judgeInput());

        $this->assertSame(MultiAgentIntegrationJudgeService::DECISION_ACCEPT, $r['decision']);
        $this->assertSame('accept', $r['decision_family']);
        $this->assertFalse($r['repair_eligible']);
        // The seven AP-803 score dimensions are reported and pass on a clean run.
        foreach (['scope', 'tests', 'evidence', 'safety', 'maintainability', 'value', 'merge_readiness'] as $dim) {
            $this->assertArrayHasKey($dim, $r['score'], "missing score dim {$dim}");
            $this->assertTrue($r['score'][$dim]['passed'], "score dim {$dim} should pass on a clean accept");
        }
    }

    // ---------- 2. judge blocks missing evidence ----------

    public function test_judge_blocks_missing_evidence(): void
    {
        $r = $this->judge()->judge($this->judgeInput([
            'evidence_refs' => [['kind' => 'diff']],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::DECISION_BLOCKED_MISSING_EVIDENCE, $r['decision']);
        $this->assertSame('blocked', $r['decision_family']);
        $this->assertFalse($r['score']['evidence']['passed']);
    }

    // ---------- 3. judge blocks scope violation ----------

    public function test_judge_blocks_scope_violation(): void
    {
        $r = $this->judge()->judge($this->judgeInput([
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Other/Outside.php']],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::DECISION_BLOCKED_SCOPE_VIOLATION, $r['decision']);
        $this->assertTrue($r['repair_plan_only']);
        $this->assertFalse($r['repair_eligible']);
        $this->assertFalse($r['score']['scope']['passed']);
    }

    // ---------- 4. judge repair_required triggers a repair plan ----------

    public function test_judge_repair_required_triggers_repair_plan(): void
    {
        $judgement = $this->judge()->judge($this->judgeInput([
            'validation_result' => ['ran' => true, 'passed' => false],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::DECISION_REPAIR_REQUIRED, $judgement['decision']);
        $this->assertTrue($judgement['repair_eligible']);
        $this->assertFalse($judgement['score']['tests']['passed']);

        // The repair planner turns that failure into an executable bounded repair.
        $plan = $this->repair()->plan([
            'validation_result' => ['passed' => false, 'failing_tests' => ['Tests\\Unit\\Ai\\Foo\\BarTest'], 'commands' => ['php artisan test --filter=Foo']],
            'executable_slice' => [
                'risk_level' => 'R2',
                'allowed_files' => ['app/Services/Ai/Foo/Bar.php'],
                'validation_commands' => ['php artisan test --filter=Foo'],
            ],
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Foo/Bar.php']],
            'retry_attempts_used' => 0,
        ]);

        $this->assertSame(MultiAgentRepairPlannerService::DECISION_REPAIR, $plan['repair_decision']);
        $this->assertTrue($plan['repair_allowed']);
        $this->assertFalse($plan['permanent_quarantine']);
        $this->assertNotNull($plan['repair_lane_input']);
    }

    // ---------- 5. repair does not run for a security block ----------

    public function test_repair_only_plans_for_security_block_never_executes(): void
    {
        // Judge side: a reviewer security blocker is a security decision, not a repair.
        $judgement = $this->judge()->judge($this->judgeInput([
            'lane_results' => [
                ['lane' => 'implementer', 'status' => 'completed', 'performed_actions' => ['edit_files']],
                ['lane' => 'reviewer', 'status' => 'completed', 'review' => [
                    'decision' => 'reject',
                    'blockers' => [['kind' => 'security', 'severity' => 'critical', 'detail' => 'hardcoded secret']],
                ]],
            ],
        ]));
        $this->assertSame(MultiAgentIntegrationJudgeService::DECISION_BLOCKED_SECURITY, $judgement['decision']);
        $this->assertFalse($judgement['repair_eligible']);
        $this->assertTrue($judgement['repair_plan_only']);

        // Repair planner side: a security classification never allows execution.
        $plan = $this->repair()->plan([
            'gate_failures' => [['gate' => 'security', 'kind' => 'security', 'reason' => 'hardcoded secret detected']],
            'lane_result' => ['status' => 'failed', 'secret_access' => true],
            'executable_slice' => ['risk_level' => 'R2', 'allowed_files' => ['app/Services/Ai/Foo/Bar.php'], 'validation_commands' => ['php artisan test']],
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Foo/Bar.php']],
        ]);

        $this->assertSame(MultiAgentRepairPlannerService::CLASS_SECURITY_BLOCKER, $plan['classification']);
        $this->assertSame(MultiAgentRepairPlannerService::DECISION_OPERATOR_REVIEW, $plan['repair_decision']);
        $this->assertFalse($plan['repair_allowed']);
        $this->assertNull($plan['repair_lane_input']);
    }

    // ---------- 6. timeout / rate limit is transient, not permanent quarantine ----------

    public function test_timeout_is_transient_not_permanent_quarantine(): void
    {
        $plan = $this->repair()->plan([
            'validation_result' => ['passed' => false],
            'lane_result' => ['status' => 'failed', 'timed_out' => true, 'error_codes' => ['timeout']],
            'executable_slice' => ['risk_level' => 'R2', 'allowed_files' => ['app/Services/Ai/Foo/Bar.php'], 'validation_commands' => ['php artisan test']],
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Foo/Bar.php']],
            'retry_attempts_used' => 0,
        ]);

        $this->assertSame(MultiAgentRepairPlannerService::CLASS_PROVIDER_TIMEOUT, $plan['classification']);
        $this->assertTrue($plan['transient']);
        $this->assertSame(MultiAgentRepairPlannerService::DECISION_TRANSIENT_RETRY, $plan['repair_decision']);
        $this->assertFalse($plan['permanent_quarantine']);

        $rate = $this->repair()->plan([
            'validation_result' => ['passed' => false],
            'lane_result' => ['status' => 'failed', 'rate_limited' => true, 'error_codes' => ['rate_limit']],
            'executable_slice' => ['risk_level' => 'R2', 'allowed_files' => ['app/Services/Ai/Foo/Bar.php'], 'validation_commands' => ['php artisan test']],
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Foo/Bar.php']],
        ]);
        $this->assertSame(MultiAgentRepairPlannerService::CLASS_RATE_LIMIT, $rate['classification']);
        $this->assertFalse($rate['permanent_quarantine']);
    }

    // ---------- 7. a failed / exhausted repair never becomes success ----------

    public function test_exhausted_repair_is_blocked_not_success(): void
    {
        $plan = $this->repair()->plan([
            'validation_result' => ['passed' => false, 'failing_tests' => ['T']],
            'lane_result' => ['status' => 'failed'],
            'executable_slice' => ['risk_level' => 'R2', 'allowed_files' => ['app/Services/Ai/Foo/Bar.php'], 'validation_commands' => ['php artisan test'], 'retry_policy' => ['count' => 2]],
            'diff_summary' => ['changed_files' => ['app/Services/Ai/Foo/Bar.php']],
            'retry_attempts_used' => 2,
        ]);

        $this->assertSame(MultiAgentRepairPlannerService::DECISION_BLOCKED_RETRY_EXHAUSTED, $plan['repair_decision']);
        $this->assertFalse($plan['repair_allowed']);
        $this->assertNull($plan['repair_lane_input']);
    }

    public function test_certification_blocks_when_repair_failed_or_over_budget(): void
    {
        $cycle = $this->completeCycle([
            'finding' => ['scope_profile' => 'factory_max', 'breadth' => 'narrow', 'kind' => 'test'],
            'focused_validation' => ['ran' => true, 'passed' => false],
            'lanes' => [
                'context_scout' => ['status' => 'completed'],
                'architect' => ['status' => 'completed'],
                'implementer' => ['status' => 'completed'],
                'reviewer' => ['status' => 'completed'],
                'judge' => ['status' => 'completed'],
                'repair' => ['status' => 'blocked_retry_exhausted', 'attempts' => 3, 'max_attempts' => 2],
            ],
        ]);

        $report = $this->cert()->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => $cycle,
            'capability_overrides' => $this->allCapabilities(),
        ]);

        $this->assertFalse($report['production_certified']);
        $this->assertContains('repair_failed_or_over_budget', $report['blockers']);
        $this->assertFalse($report['repair']['within_budget']);
    }

    // ---------- 8. certification blocks accept without evidence / without judge accept ----------

    public function test_certification_blocks_accept_without_evidence(): void
    {
        $cycle = $this->completeCycle();
        $cycle['substrate_facts']['evidence_refs_present'] = false;

        $report = $this->cert()->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => $cycle,
            'capability_overrides' => $this->allCapabilities(),
        ]);

        $this->assertFalse($report['production_certified']);
        $this->assertFalse($report['evidence_obligations']['all_present']);
    }

    public function test_certification_blocks_completion_without_judge_accept(): void
    {
        $cycle = $this->completeCycle([
            'judge_decision' => ['selected_candidate' => '', 'status' => 'rejected', 'rationale' => 'scope violation'],
        ]);

        $report = $this->cert()->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => $cycle,
            'capability_overrides' => $this->allCapabilities(),
        ]);

        $this->assertFalse($report['production_certified']);
        $this->assertFalse($report['judge_decision']['accepted']);
        $this->assertContains('completed_without_judge_accept', $report['blockers']);
    }

    // ---------- 9. Product Mode shows judge + repair state ----------

    public function test_product_mode_projection_shows_judge_and_repair_state(): void
    {
        $cycle = $this->completeCycle([
            'finding' => ['scope_profile' => 'factory_max', 'breadth' => 'narrow', 'kind' => 'test'],
            'focused_validation' => ['ran' => true, 'passed' => false],
            'lanes' => [
                'context_scout' => ['status' => 'completed'],
                'architect' => ['status' => 'completed'],
                'implementer' => ['status' => 'completed'],
                'reviewer' => ['status' => 'completed'],
                'judge' => ['status' => 'completed'],
                'repair' => ['status' => 'attempted'],
            ],
        ]);

        $report = $this->cert()->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => $cycle,
            'capability_overrides' => $this->allCapabilities(),
        ]);

        $projection = $report['product_mode_projection'];
        $this->assertArrayHasKey('judge_decision', $projection);
        $this->assertArrayHasKey('accepted', $projection['judge_decision']);
        $this->assertArrayHasKey('repair', $projection);
        $this->assertArrayHasKey('attempted', $projection['repair']);
        $this->assertArrayHasKey('within_budget', $projection['repair']);
        $this->assertArrayHasKey('final_blocker', $projection);
        $this->assertTrue($projection['repair']['attempted']);
        $this->assertTrue($projection['repair']['required']);
    }

    // ---------- cert fixtures ----------

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function completeCycle(array $overrides = []): array
    {
        return array_replace_recursive([
            'multi_agent' => true,
            'claims_complete' => true,
            'scope_profile' => 'factory_max',
            'finding' => ['scope_profile' => 'factory_max', 'breadth' => 'broad', 'kind' => 'runtime'],
            'substrate_facts' => [
                'provider_invoked' => true,
                'provider_authority' => 'atlas_decide',
                'provider_calls' => 2,
                'sandbox_kind' => 'local_git_worktree',
                'worktree_materialized' => true,
                'owner_runtime_chain' => ['AP-747', 'AP-756', 'AP-757', 'AP-749', 'AP-758', 'AP-759', 'AP-750'],
                'product_diff_exists' => true,
                'focused_validation_ran' => true,
                'inbox_item_emitted' => true,
                'evidence_refs_present' => true,
                'merge_governor_evaluated' => true,
            ],
            'slice_plan' => ['decomposition_status' => 'sliced', 'slices' => [['slice_id' => 's1'], ['slice_id' => 's2']]],
            'lanes' => [
                'context_scout' => ['status' => 'completed'],
                'architect' => ['status' => 'completed'],
                'implementer' => ['status' => 'completed'],
                'reviewer' => ['status' => 'completed'],
                'judge' => ['status' => 'completed'],
            ],
            'judge_decision' => ['selected_candidate' => 'implementer_diff_1', 'status' => 'accepted_for_merge_governor', 'rationale' => 'passes validation in scope'],
            'focused_validation' => ['ran' => true, 'passed' => true],
            'merge_governance' => ['status' => 'merged', 'main_before' => 'aaaaaa', 'main_after' => 'bbbbbb', 'main_advanced' => true],
        ], $overrides);
    }

    /**
     * @return array<string,bool>
     */
    private function allCapabilities(): array
    {
        return [
            'provider_port_session_store' => true,
            'finding_slice_planner' => true,
            'lane_orchestrator' => true,
            'integration_judge' => true,
            'repair_planner' => true,
            'ap793_substrate_facts' => true,
            'ap792_harness' => true,
        ];
    }
}
