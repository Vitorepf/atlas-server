<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentIntegrationJudgeService;
use Tests\TestCase;

/**
 * AP-798 · Multi-Agent Integration Judge contract tests.
 *
 * The judge is a pure rules engine: it composes lane outputs into a deterministic
 * verdict, never merges, never invokes a provider and never uses an LLM. These
 * tests pin the five statuses, the decision order and the no-side-effect and
 * determinism guarantees.
 */
class MultiAgentIntegrationJudgeServiceTest extends TestCase
{
    private function service(): MultiAgentIntegrationJudgeService
    {
        return app(MultiAgentIntegrationJudgeService::class);
    }

    /**
     * A fully clean run: everything in scope, validation green, evidence
     * complete, reviewer approves, diff shape matches, risk policy consistent.
     *
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function happyInput(array $over = []): array
    {
        $base = [
            'lane_plan' => [
                'task_id' => 'task-abc',
                'slice_id' => 'slice-1',
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
                'branch_ref' => 'atlas/cycle/foo',
                'base_ref' => 'main',
                'area_id' => 'agentic_engineering_os',
            ],
            'lane_results' => [
                ['lane' => 'implementer', 'agent_id' => 'a1', 'status' => 'completed', 'performed_actions' => ['edit_files', 'run_tests']],
                ['lane' => 'reviewer', 'agent_id' => 'a2', 'status' => 'completed', 'review' => ['decision' => 'approve', 'blockers' => [], 'rationale' => 'looks good']],
            ],
            'validation_result' => [
                'ran' => true,
                'passed' => true,
                'commands' => ['php artisan test --filter=Foo'],
                'results' => [['command' => 'php artisan test --filter=Foo', 'ok' => true, 'exit_code' => 0]],
            ],
            'diff_summary' => [
                'changed_files' => ['app/Services/Ai/Foo/Bar.php', 'tests/Unit/Ai/Foo/BarTest.php'],
                'diff_shape' => 'service_and_test',
                'insertions' => 40,
                'deletions' => 3,
            ],
            'evidence_refs' => [
                ['kind' => 'validation_log', 'ref' => 'log://1', 'hash' => 'sha256:a'],
                ['kind' => 'diff', 'ref' => 'diff://1'],
                ['kind' => 'receipt', 'ref' => 'rcpt://1'],
            ],
        ];

        // lane_plan overrides merge shallowly (keep unset keys); every other top
        // level key (lane_results, validation_result, diff_summary,
        // evidence_refs) replaces its subtree wholesale so an override of [] or a
        // shorter list truly replaces the base rather than recursively merging.
        if (isset($over['lane_plan']) && is_array($over['lane_plan'])) {
            $base['lane_plan'] = array_replace($base['lane_plan'], $over['lane_plan']);
            unset($over['lane_plan']);
        }

        return array_replace($base, $over);
    }

    public function test_accepted_happy_path_forwards_to_merge_governor_without_merging(): void
    {
        $r = $this->service()->judge($this->happyInput());

        $this->assertSame(MultiAgentIntegrationJudgeService::SCHEMA, $r['schema_version']);
        $this->assertSame('AP-798', $r['ap_contract']);
        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_ACCEPTED, $r['status']);
        $this->assertTrue($r['all_gates_passed']);
        $this->assertSame('all_gates_passed', $r['decision_reason']);

        $this->assertIsArray($r['merge_governor_handoff']);
        $this->assertTrue($r['merge_governor_handoff']['eligible']);
        $this->assertFalse($r['merge_governor_handoff']['merge_performed_here']);
        $this->assertSame('atlas/cycle/foo', $r['merge_governor_handoff']['branch_ref']);

        // Acceptance still does not merge.
        $this->assertFalse($r['claim_policy']['merge_performed']);
        $this->assertTrue($r['claim_policy']['forwards_to_merge_governor']);
    }

    public function test_all_seven_scoring_dimensions_are_reported(): void
    {
        $r = $this->service()->judge($this->happyInput());

        foreach ([
            'validation_passed',
            'evidence_complete',
            'scope_respected',
            'reviewer_approved',
            'no_forbidden_actions',
            'expected_diff_shape_matched',
            'risk_policy_satisfied',
        ] as $dim) {
            $this->assertArrayHasKey($dim, $r['scoring'], "missing scoring dimension {$dim}");
            $this->assertTrue($r['scoring'][$dim]['passed'], "dimension {$dim} should pass on happy path");
        }
    }

    public function test_validation_failure_with_repair_attempts_routes_to_repair_required(): void
    {
        $r = $this->service()->judge($this->happyInput([
            'validation_result' => ['passed' => false],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_REPAIR_REQUIRED, $r['status']);
        $this->assertSame('validation_failed_repair_allowed', $r['decision_reason']);
        $this->assertIsArray($r['repair_handoff']);
        $this->assertTrue($r['repair_handoff']['required']);
        $this->assertSame(2, $r['repair_handoff']['remaining_attempts']);
        $this->assertNull($r['merge_governor_handoff']);
    }

    public function test_validation_failure_without_repair_attempts_is_rejected(): void
    {
        $r = $this->service()->judge($this->happyInput([
            'validation_result' => ['passed' => false],
            'lane_plan' => ['repair_policy' => ['allowed' => true, 'max_attempts' => 1, 'attempts_used' => 1]],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_REJECTED, $r['status']);
        $this->assertSame('validation_failed_repair_exhausted', $r['decision_reason']);
    }

    public function test_missing_evidence_blocks_the_judgement(): void
    {
        $r = $this->service()->judge($this->happyInput([
            'evidence_refs' => [
                ['kind' => 'diff', 'ref' => 'diff://1'],
            ],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_BLOCKED_MISSING_EVIDENCE, $r['status']);
        $this->assertSame('evidence_obligations_missing', $r['decision_reason']);
        $this->assertContains('validation_log', $r['scoring']['evidence_complete']['missing_obligations']);
        $this->assertContains('receipt', $r['scoring']['evidence_complete']['missing_obligations']);
    }

    public function test_scope_violation_is_rejected(): void
    {
        $r = $this->service()->judge($this->happyInput([
            'diff_summary' => [
                'changed_files' => ['app/Services/Ai/Foo/Bar.php', 'app/Services/Ai/Other/Outside.php'],
            ],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_REJECTED, $r['status']);
        $this->assertSame('scope_violation', $r['decision_reason']);
        $this->assertContains('app/Services/Ai/Other/Outside.php', $r['scoring']['scope_respected']['out_of_scope_files']);
    }

    public function test_forbidden_file_touch_is_rejected(): void
    {
        $r = $this->service()->judge($this->happyInput([
            'diff_summary' => [
                'changed_files' => ['app/Services/Ai/Foo/Secret.php'],
            ],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_REJECTED, $r['status']);
        $this->assertSame('scope_violation', $r['decision_reason']);
        $this->assertContains('app/Services/Ai/Foo/Secret.php', $r['scoring']['scope_respected']['forbidden_files_touched']);
    }

    public function test_security_blocker_request_changes_requires_operator_review(): void
    {
        $r = $this->service()->judge($this->happyInput([
            'lane_results' => [
                ['lane' => 'implementer', 'agent_id' => 'a1', 'status' => 'completed', 'performed_actions' => ['edit_files']],
                ['lane' => 'reviewer', 'agent_id' => 'a2', 'status' => 'completed', 'review' => [
                    'decision' => 'request_changes',
                    'blockers' => [['kind' => 'security', 'severity' => 'high', 'detail' => 'possible SQL injection in query builder']],
                    'rationale' => 'needs human eyes',
                ]],
            ],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_OPERATOR_REVIEW_REQUIRED, $r['status']);
        $this->assertSame('security_safety_review_required', $r['decision_reason']);
        $this->assertIsArray($r['operator_review']);
        $this->assertTrue($r['operator_review']['required']);

        $findings = $r['reviewer_findings'];
        $this->assertNotEmpty($findings);
        $this->assertTrue($findings[0]['security_or_safety']);
    }

    public function test_critical_security_blocker_is_rejected(): void
    {
        $r = $this->service()->judge($this->happyInput([
            'lane_results' => [
                ['lane' => 'implementer', 'agent_id' => 'a1', 'status' => 'completed', 'performed_actions' => ['edit_files']],
                ['lane' => 'reviewer', 'agent_id' => 'a2', 'status' => 'completed', 'review' => [
                    'decision' => 'reject',
                    'blockers' => [['kind' => 'rce', 'severity' => 'critical', 'detail' => 'remote code execution']],
                ]],
            ],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_REJECTED, $r['status']);
        $this->assertSame('reviewer_security_reject', $r['decision_reason']);
    }

    public function test_forbidden_action_performed_is_rejected_before_repair(): void
    {
        // Validation also fails, but a performed forbidden action must win and
        // produce a hard reject — never a downgrade to repair.
        $r = $this->service()->judge($this->happyInput([
            'validation_result' => ['passed' => false],
            'lane_results' => [
                ['lane' => 'implementer', 'agent_id' => 'a1', 'status' => 'completed', 'performed_actions' => ['edit_files', 'merge']],
                ['lane' => 'reviewer', 'agent_id' => 'a2', 'status' => 'completed', 'review' => ['decision' => 'approve']],
            ],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_REJECTED, $r['status']);
        $this->assertSame('forbidden_action_performed', $r['decision_reason']);
        $this->assertNotEmpty($r['scoring']['no_forbidden_actions']['violations']);
    }

    public function test_missing_reviewer_requires_operator_review(): void
    {
        $r = $this->service()->judge($this->happyInput([
            'lane_results' => [
                ['lane' => 'implementer', 'agent_id' => 'a1', 'status' => 'completed', 'performed_actions' => ['edit_files']],
            ],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_OPERATOR_REVIEW_REQUIRED, $r['status']);
        $this->assertSame('reviewer_approval_missing', $r['decision_reason']);
    }

    public function test_high_risk_auto_merge_policy_requires_operator_review(): void
    {
        $r = $this->service()->judge($this->happyInput([
            'lane_plan' => [
                'risk_level' => 'high',
                'merge_policy' => 'auto_merge_eligible',
            ],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_OPERATOR_REVIEW_REQUIRED, $r['status']);
        $this->assertSame('risk_policy_requires_review', $r['decision_reason']);
        $this->assertFalse($r['scoring']['risk_policy_satisfied']['passed']);
    }

    public function test_diff_shape_mismatch_requires_operator_review(): void
    {
        $r = $this->service()->judge($this->happyInput([
            'diff_summary' => [
                'changed_files' => ['app/Services/Ai/Foo/Bar.php', 'tests/Unit/Ai/Foo/BarTest.php'],
                'diff_shape' => 'docs_only',
            ],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_OPERATOR_REVIEW_REQUIRED, $r['status']);
        $this->assertSame('diff_shape_mismatch', $r['decision_reason']);
    }

    public function test_empty_diff_blocks_with_no_changed_files(): void
    {
        $r = $this->service()->judge($this->happyInput([
            'diff_summary' => ['changed_files' => []],
        ]));

        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_BLOCKED_MISSING_EVIDENCE, $r['status']);
        $this->assertSame('no_changed_files_to_judge', $r['decision_reason']);
    }

    public function test_missing_lane_plan_blocks(): void
    {
        $r = $this->service()->judge([
            'lane_results' => [],
            'diff_summary' => ['changed_files' => ['app/X.php']],
        ]);

        $this->assertSame(MultiAgentIntegrationJudgeService::STATUS_BLOCKED_MISSING_EVIDENCE, $r['status']);
        $this->assertSame('lane_plan_required', $r['decision_reason']);
    }

    public function test_no_merge_or_provider_side_effects_are_ever_claimed(): void
    {
        foreach ([
            $this->service()->judge($this->happyInput()),
            $this->service()->judge($this->happyInput(['validation_result' => ['passed' => false]])),
            $this->service()->judge($this->happyInput(['diff_summary' => ['changed_files' => ['outside/x.php']]])),
        ] as $r) {
            $cp = $r['claim_policy'];
            $this->assertFalse($cp['merge_performed']);
            $this->assertFalse($cp['merges']);
            $this->assertFalse($cp['provider_invoked']);
            $this->assertFalse($cp['uses_llm']);
            $this->assertFalse($cp['mutates_repo']);
            $this->assertFalse($cp['creates_branch']);
            $this->assertTrue($cp['rules_engine']);
            // No git/merge result leaks into the judgement payload.
            $this->assertArrayNotHasKey('merge_result', $r);
            $this->assertArrayNotHasKey('new_head', $r);
        }
    }

    public function test_judgement_hash_and_id_are_deterministic(): void
    {
        $first = $this->service()->judge($this->happyInput());
        $second = $this->service()->judge($this->happyInput());

        $this->assertStringStartsWith('sha256:', $first['judgement_hash']);
        $this->assertStringStartsWith('maij_', $first['judgement_id']);
        $this->assertSame($first['judgement_hash'], $second['judgement_hash']);
        $this->assertSame($first['judgement_id'], $second['judgement_id']);
    }

    public function test_different_inputs_produce_different_hashes(): void
    {
        $accepted = $this->service()->judge($this->happyInput());
        $rejected = $this->service()->judge($this->happyInput([
            'diff_summary' => ['changed_files' => ['outside/x.php']],
        ]));

        $this->assertNotSame($accepted['judgement_hash'], $rejected['judgement_hash']);
    }
}
