<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentMergeReviewHumanApprovalPlanner;
use App\Services\Ai\SelfConstruction\AgentMergeReviewPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentMergeReviewRiskScorer;
use App\Services\Ai\SelfConstruction\AgentMergeReviewScopeVerifier;
use Tests\TestCase;

final class AgentMergeReviewHumanApprovalPlannerTest extends TestCase
{
    public function test_constants_are_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_merge_review_human_approval_plan.v1', AgentMergeReviewHumanApprovalPlanner::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_merge_review_human_approval_plan', AgentMergeReviewHumanApprovalPlanner::MODE);
        $this->assertSame(['single', 'double', 'quorum'], AgentMergeReviewHumanApprovalPlanner::APPROVAL_MODES);
        $this->assertArrayHasKey('low', AgentMergeReviewHumanApprovalPlanner::DEFAULT_APPROVERS);
        $this->assertArrayHasKey('critical', AgentMergeReviewHumanApprovalPlanner::DEFAULT_APPROVERS);
    }

    public function test_envelope_marks_no_side_effects(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertFalse($result['apply_patch_allowed']);
        $this->assertFalse($result['real_file_write_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['approval_granted']);
        $this->assertFalse($result['approval_persisted']);
    }

    public function test_non_execution_guarantees_present(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertContains('agent_merge_review_human_approval_planner_does_not_grant_approval', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_human_approval_planner_does_not_persist_state', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_human_approval_planner_does_not_apply_patch', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_human_approval_planner_does_not_advance_completion_claim', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_human_approval_planner_does_not_write_ledger', $result['non_execution_guarantees']);
    }

    public function test_low_band_uses_single_mode_with_one_approver(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertSame('single', $result['plan']['approval_mode']);
        $this->assertSame(['operator'], $result['plan']['required_approvers']);
        $this->assertSame(1, $result['plan']['required_approver_count']);
        $this->assertTrue($result['plan']['approval_eligible']);
    }

    public function test_medium_band_uses_double_mode(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $result = $svc->plan(...$this->scenario('medium'));
        $this->assertSame('double', $result['plan']['approval_mode']);
        $this->assertContains('operator', $result['plan']['required_approvers']);
        $this->assertContains('reviewer', $result['plan']['required_approvers']);
    }

    public function test_high_band_uses_double_mode_with_security(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $result = $svc->plan(...$this->scenario('high'));
        $this->assertSame('double', $result['plan']['approval_mode']);
        $this->assertContains('security', $result['plan']['required_approvers']);
    }

    public function test_critical_band_requires_quorum(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $result = $svc->plan(...$this->scenario('critical'));
        $this->assertSame('quorum', $result['plan']['approval_mode']);
        $this->assertContains('release_manager', $result['plan']['required_approvers']);
    }

    public function test_critical_band_is_not_approval_eligible_even_without_blockers(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $result = $svc->plan(...$this->scenario('critical'));
        $this->assertFalse($result['plan']['approval_eligible']);
    }

    public function test_blocking_conditions_collected_from_risk_blockers(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        [$packet, $scope, $risk] = $this->scenario('critical');
        $result = $svc->plan($packet, $scope, $risk);
        $names = array_column($result['plan']['blocking_conditions'], 'name');
        $this->assertContains('forbidden_path_violation_present', $names);
    }

    public function test_blocking_conditions_collected_from_scope_violations(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        [$packet, $scope, $risk] = $this->scenario('critical');
        $result = $svc->plan($packet, $scope, $risk);
        $names = array_column($result['plan']['blocking_conditions'], 'name');
        $this->assertContains('cross_axis_violation_present', $names);
        $this->assertContains('unsafe_path_present', $names);
    }

    public function test_blocking_conditions_dedup_by_name(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $result = $svc->plan(...$this->scenario('critical'));
        $names = array_column($result['plan']['blocking_conditions'], 'name');
        $this->assertSame(count(array_unique($names)), count($names));
    }

    public function test_status_blocked_when_blockers_present(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $result = $svc->plan(...$this->scenario('critical'));
        $this->assertSame('agent_merge_review_human_approval_blocked', $result['status']);
    }

    public function test_status_ready_when_no_blockers(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertSame('agent_merge_review_human_approval_plan_ready', $result['status']);
    }

    public function test_override_required_approvers(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        [$packet, $scope, $risk] = $this->scenario('low');
        $result = $svc->plan($packet, $scope, $risk, ['required_approvers' => ['alice', 'bob']]);
        $this->assertSame(['alice', 'bob'], $result['plan']['required_approvers']);
        $this->assertSame(2, $result['plan']['required_approver_count']);
    }

    public function test_justification_aligned_when_no_blockers(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertSame('human_approval_plan_aligned_with_risk_band', $result['plan']['justification']);
    }

    public function test_justification_blocked_when_blockers(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $result = $svc->plan(...$this->scenario('critical'));
        $this->assertSame('human_approval_blocked_until_conditions_cleared', $result['plan']['justification']);
    }

    public function test_plan_hash_stable_sha256(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        [$packet, $scope, $risk] = $this->scenario('low');
        $a = $svc->plan($packet, $scope, $risk);
        $b = $svc->plan($packet, $scope, $risk);
        $this->assertSame($a['plan_hash'], $b['plan_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['plan_hash']);
    }

    public function test_blocking_condition_count_matches_list_length(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $result = $svc->plan(...$this->scenario('critical'));
        $this->assertSame(count($result['plan']['blocking_conditions']), $result['plan']['blocking_condition_count']);
    }

    public function test_required_approvers_for_each_band_have_distinct_lengths(): void
    {
        $svc = new AgentMergeReviewHumanApprovalPlanner;
        $low = $svc->plan(...$this->scenario('low'));
        $crit = $svc->plan(...$this->scenario('critical'));
        $this->assertLessThan(count($crit['plan']['required_approvers']), count($low['plan']['required_approvers']));
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function scenario(string $band): array
    {
        $files = match ($band) {
            'low' => [['path' => 'app/Foo.php', 'change_kind' => 'modified', 'lines_added' => 1]],
            'medium' => array_map(static fn ($i) => ['path' => 'app/File'.$i.'.php', 'change_kind' => 'modified', 'lines_added' => 30], range(1, 8)),
            'high' => array_map(static fn ($i) => ['path' => 'app/File'.$i.'.php', 'change_kind' => 'modified', 'lines_added' => 80], range(1, 30)),
            'critical' => [
                ['path' => 'config/secret.php', 'change_kind' => 'modified', 'lines_added' => 1],
                ['path' => 'routes/api.php', 'change_kind' => 'modified', 'lines_added' => 1],
                ['path' => '../escape.php', 'change_kind' => 'modified', 'lines_added' => 1],
            ],
            default => [['path' => 'app/Foo.php', 'change_kind' => 'modified', 'lines_added' => 1]],
        };
        $forbidden = $band === 'critical' ? ['config/secret.php'] : [];
        $allowed = $band === 'critical' ? ['app/'] : array_map(static fn ($f) => (string) ($f['path'] ?? ''), $files);
        $packet = (new AgentMergeReviewPacketBuilder)->build(['files' => $files], [], ['packet_id' => 'p', 'claim_id' => 'c', 'task_packet_id' => 't', 'generated_at' => '2026-05-14T00:00:00+00:00']);
        $scope = (new AgentMergeReviewScopeVerifier)->verify($packet, ['allowed_files' => $allowed, 'forbidden_files' => $forbidden]);
        $risk = (new AgentMergeReviewRiskScorer)->score($packet, $scope);

        return [$packet, $scope, $risk];
    }
}
