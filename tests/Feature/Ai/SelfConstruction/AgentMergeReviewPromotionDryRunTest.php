<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentMergeReviewHumanApprovalPlanner;
use App\Services\Ai\SelfConstruction\AgentMergeReviewPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentMergeReviewPromotionDryRun;
use App\Services\Ai\SelfConstruction\AgentMergeReviewRiskScorer;
use App\Services\Ai\SelfConstruction\AgentMergeReviewScopeVerifier;
use Tests\TestCase;

final class AgentMergeReviewPromotionDryRunTest extends TestCase
{
    public function test_constants_are_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_merge_review_promotion_dry_run.v1', AgentMergeReviewPromotionDryRun::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_merge_review_promotion_dry_run', AgentMergeReviewPromotionDryRun::MODE);
    }

    public function test_envelope_marks_promotion_always_false(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertFalse($result['promotion_allowed']);
        $this->assertFalse($result['apply_patch_allowed']);
        $this->assertFalse($result['real_file_write_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    public function test_envelope_marks_promotion_false_even_for_critical(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('critical'));
        $this->assertFalse($result['promotion_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
    }

    public function test_non_execution_guarantees_present(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertContains('agent_merge_review_promotion_dry_run_does_not_apply_patch', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_promotion_dry_run_does_not_modify_real_files', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_promotion_dry_run_does_not_persist_promotion', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_promotion_dry_run_does_not_advance_completion_claim', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_promotion_dry_run_does_not_dispatch_agent', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_promotion_dry_run_does_not_write_ledger', $result['non_execution_guarantees']);
    }

    public function test_plan_status_planned_when_no_blockers(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertSame('agent_merge_review_promotion_dry_run_planned', $result['status']);
        $this->assertFalse($result['plan']['critical_blockers_present']);
    }

    public function test_plan_status_blocked_when_critical(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('critical'));
        $this->assertSame('agent_merge_review_promotion_dry_run_blocked', $result['status']);
        $this->assertTrue($result['plan']['critical_blockers_present']);
    }

    public function test_baseline_step_is_first_step(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertSame('verify_baseline_certification', $result['plan']['steps'][0]['name']);
    }

    public function test_steps_have_no_side_effects(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        foreach ($result['plan']['steps'] as $step) {
            $this->assertFalse($step['has_side_effect'], 'step '.$step['name'].' must declare has_side_effect=false');
            $this->assertSame('no', $step['side_effect_kind']);
        }
    }

    public function test_each_file_produces_simulate_step(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        [$packet] = $this->scenario('low');
        $result = $svc->plan(...$this->scenario('low'));
        $simulateSteps = array_filter($result['plan']['steps'], static fn ($s) => str_starts_with($s['name'], 'simulate_change:'));
        $this->assertCount(count(data_get($packet, 'packet.files', [])), $simulateSteps);
    }

    public function test_skip_real_apply_is_last_step(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        $steps = $result['plan']['steps'];
        $this->assertSame('skip_real_apply', $steps[count($steps) - 1]['name']);
    }

    public function test_expected_effects_all_projection_only(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        foreach ($result['plan']['expected_effects'] as $effect) {
            $this->assertSame('projection_only', $effect['observed_change_kind']);
        }
    }

    public function test_rollback_steps_present_for_each_file_plus_discard(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        [$packet] = $this->scenario('low');
        $expected = count((array) data_get($packet, 'packet.files', [])) + 1;
        $this->assertSame($expected, $result['plan']['rollback_step_count']);
    }

    public function test_rollback_step_marks_no_side_effect(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        foreach ($result['plan']['rollback_steps'] as $step) {
            $this->assertFalse($step['has_side_effect']);
        }
    }

    public function test_post_merge_gates_list_not_empty(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertGreaterThanOrEqual(4, $result['plan']['post_merge_gate_count']);
        $this->assertContains('chain_integrity_status_must_stay_available', $result['plan']['post_merge_gates']);
        $this->assertContains('deterministic_replay_status_must_stay_available', $result['plan']['post_merge_gates']);
        $this->assertContains('macro_sprint_promotion_gate_remains_blocked_until_runtime', $result['plan']['post_merge_gates']);
    }

    public function test_dry_run_hash_stable(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $a = $svc->plan(...$this->scenario('low'));
        $b = $svc->plan(...$this->scenario('low'));
        $this->assertSame($a['dry_run_hash'], $b['dry_run_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['dry_run_hash']);
    }

    public function test_plan_carries_packet_id(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertSame('p', $result['plan']['packet_id']);
    }

    public function test_plan_carries_risk_band(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertSame('low', $result['plan']['risk_band']);
    }

    public function test_plan_carries_approval_eligibility(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertTrue($result['plan']['approval_eligible']);
        $blocked = $svc->plan(...$this->scenario('critical'));
        $this->assertFalse($blocked['plan']['approval_eligible']);
    }

    public function test_step_count_matches_steps_array(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        $this->assertSame(count($result['plan']['steps']), $result['plan']['step_count']);
    }

    public function test_dispatch_step_absent_from_plan(): void
    {
        $svc = new AgentMergeReviewPromotionDryRun;
        $result = $svc->plan(...$this->scenario('low'));
        $names = array_column($result['plan']['steps'], 'name');
        foreach ($names as $name) {
            $this->assertStringNotContainsString('dispatch_agent', $name);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    private function scenario(string $band): array
    {
        $files = match ($band) {
            'low' => [
                ['path' => 'app/Foo.php', 'change_kind' => 'modified', 'lines_added' => 1, 'content_hash' => str_repeat('a', 64)],
                ['path' => 'app/Bar.php', 'change_kind' => 'added', 'lines_added' => 3, 'content_hash' => str_repeat('b', 64)],
            ],
            'critical' => [
                ['path' => 'config/secret.php', 'change_kind' => 'modified', 'lines_added' => 1, 'content_hash' => str_repeat('c', 64)],
                ['path' => 'routes/api.php', 'change_kind' => 'modified', 'lines_added' => 1, 'content_hash' => str_repeat('d', 64)],
                ['path' => '../escape.php', 'change_kind' => 'modified', 'lines_added' => 1, 'content_hash' => str_repeat('e', 64)],
            ],
            default => [['path' => 'app/Foo.php', 'change_kind' => 'modified', 'lines_added' => 1]],
        };
        $forbidden = $band === 'critical' ? ['config/secret.php'] : [];
        $allowed = $band === 'critical' ? ['app/'] : array_map(static fn ($f) => (string) ($f['path'] ?? ''), $files);
        $packet = (new AgentMergeReviewPacketBuilder)->build(['files' => $files], [], ['packet_id' => 'p', 'claim_id' => 'c', 'task_packet_id' => 't', 'generated_at' => '2026-05-14T00:00:00+00:00']);
        $scope = (new AgentMergeReviewScopeVerifier)->verify($packet, ['allowed_files' => $allowed, 'forbidden_files' => $forbidden]);
        $risk = (new AgentMergeReviewRiskScorer)->score($packet, $scope);
        $approval = (new AgentMergeReviewHumanApprovalPlanner)->plan($packet, $scope, $risk);

        return [$packet, $scope, $risk, $approval];
    }
}
