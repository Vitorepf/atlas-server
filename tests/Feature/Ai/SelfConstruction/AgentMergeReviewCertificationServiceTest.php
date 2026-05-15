<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentMergeReviewCertificationService;
use Tests\TestCase;

final class AgentMergeReviewCertificationServiceTest extends TestCase
{
    public function test_constants_are_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_merge_review_certification.v1', AgentMergeReviewCertificationService::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_merge_review_certification', AgentMergeReviewCertificationService::MODE);
    }

    public function test_clean_scenario_returns_available_status(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        $this->assertSame('available', $result['status']);
        $this->assertTrue($result['invariants_all_true']);
        $this->assertSame(0, $result['violation_count']);
    }

    public function test_envelope_marks_no_side_effects(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        $this->assertFalse($result['execution_allowed']);
        $this->assertFalse($result['promotion_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertFalse($result['apply_patch_allowed']);
        $this->assertFalse($result['real_file_write_allowed']);
        $this->assertFalse($result['rollback_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
    }

    public function test_runtime_safety_block_all_false(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        $rs = $result['runtime_safety'];
        $this->assertTrue($rs['runtime_safety_all_false']);
        $this->assertFalse($rs['apply_patch_allowed']);
        $this->assertFalse($rs['real_file_write_allowed']);
        $this->assertFalse($rs['completion_claim_allowed']);
        $this->assertFalse($rs['promotion_allowed']);
        $this->assertFalse($rs['rollback_execution_allowed']);
        $this->assertFalse($rs['dispatch_allowed']);
        $this->assertFalse($rs['provider_call_allowed']);
        $this->assertFalse($rs['token_spend_allowed']);
        $this->assertFalse($rs['ledger_write_allowed']);
        $this->assertFalse($rs['self_programming_allowed']);
    }

    public function test_non_execution_guarantees_present(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        $this->assertContains('agent_merge_review_certification_does_not_apply_patch', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_certification_does_not_modify_real_files', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_certification_does_not_grant_approval', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_certification_does_not_persist_state', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_certification_does_not_advance_completion_claim', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_certification_does_not_dispatch_agent', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_certification_does_not_write_ledger', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_certification_does_not_enable_self_programming', $result['non_execution_guarantees']);
    }

    public function test_invariants_grid_has_at_least_27_entries(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        $this->assertGreaterThanOrEqual(27, count($result['invariants']));
    }

    public function test_invariants_runtime_safety_block_present(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        $names = array_column($result['invariants'], 'name');
        $this->assertContains('runtime_safety:no_patch_apply', $names);
        $this->assertContains('runtime_safety:no_real_file_write', $names);
        $this->assertContains('runtime_safety:no_completion_claim', $names);
        $this->assertContains('runtime_safety:no_promotion_execution', $names);
        $this->assertContains('runtime_safety:no_dispatch_real', $names);
        $this->assertContains('runtime_safety:no_token_spend', $names);
        $this->assertContains('runtime_safety:no_self_programming', $names);
        $this->assertContains('runtime_safety:no_ledger_write', $names);
    }

    public function test_invariants_envelope_block_present(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        $names = array_column($result['invariants'], 'name');
        $this->assertContains('packet_envelope_marks_no_patch_apply', $names);
        $this->assertContains('packet_envelope_marks_no_real_file_write', $names);
        $this->assertContains('scope_envelope_marks_no_patch_apply', $names);
        $this->assertContains('risk_envelope_marks_no_patch_apply', $names);
        $this->assertContains('approval_envelope_marks_no_grant', $names);
        $this->assertContains('dry_run_envelope_marks_no_promotion', $names);
        $this->assertContains('rollback_envelope_marks_no_execution', $names);
    }

    public function test_invariants_hash_block_present(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        $names = array_column($result['invariants'], 'name');
        $this->assertContains('packet_has_hash', $names);
        $this->assertContains('scope_has_hash', $names);
        $this->assertContains('risk_has_hash', $names);
        $this->assertContains('approval_has_hash', $names);
        $this->assertContains('dry_run_has_hash', $names);
        $this->assertContains('rollback_has_hash', $names);
    }

    public function test_all_invariants_ok_in_clean_scenario(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        foreach ($result['invariants'] as $invariant) {
            $this->assertTrue($invariant['ok'], 'invariant '.$invariant['name'].' must hold');
        }
    }

    public function test_inputs_carry_all_seven_layers(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        $this->assertArrayHasKey('packet', $result['inputs']);
        $this->assertArrayHasKey('scope_verification', $result['inputs']);
        $this->assertArrayHasKey('risk_score', $result['inputs']);
        $this->assertArrayHasKey('approval_plan', $result['inputs']);
        $this->assertArrayHasKey('promotion_dry_run', $result['inputs']);
        $this->assertArrayHasKey('rollback_verification', $result['inputs']);
    }

    public function test_certification_hash_stable_sha256(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $a = $svc->certify(...$this->cleanArgs());
        $b = $svc->certify(...$this->cleanArgs());
        $this->assertSame($a['certification_hash'], $b['certification_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['certification_hash']);
    }

    public function test_critical_scenario_blocks_approval(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->criticalArgs());
        $this->assertFalse($result['inputs']['approval_plan']['plan']['approval_eligible']);
        $this->assertFalse($result['promotion_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
    }

    public function test_critical_scenario_keeps_promotion_blocked(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->criticalArgs());
        $this->assertFalse($result['inputs']['promotion_dry_run']['promotion_allowed']);
        $this->assertSame('agent_merge_review_promotion_dry_run_blocked', $result['inputs']['promotion_dry_run']['status']);
    }

    public function test_human_summary_mentions_promotion_false_and_completion_false(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        $this->assertStringContainsString('promotion_allowed=false', $result['human_summary']);
        $this->assertStringContainsString('completion_claim_allowed=false', $result['human_summary']);
    }

    public function test_human_summary_reports_critical_risk(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->criticalArgs());
        $this->assertStringContainsString('Risk band: critical', $result['human_summary']);
    }

    public function test_next_action_keeps_layer_read_only_in_clean_scenario(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        $this->assertSame('keep_merge_review_layer_read_only_until_runtime_pilot_promotes', $result['next_action']);
    }

    public function test_next_action_signals_blockers_in_critical(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->criticalArgs());
        $this->assertSame('clear_blocking_conditions_then_seek_human_approval', $result['next_action']);
    }

    public function test_generated_at_is_iso8601_string(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $result['generated_at']);
    }

    public function test_violation_count_matches_invariant_failures(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        $bad = array_filter($result['invariants'], static fn ($inv) => ($inv['ok'] ?? true) === false);
        $this->assertSame(count($bad), $result['violation_count']);
    }

    public function test_invariants_observation_strings_filled(): void
    {
        $svc = new AgentMergeReviewCertificationService;
        $result = $svc->certify(...$this->cleanArgs());
        foreach ($result['invariants'] as $invariant) {
            $this->assertNotSame('', $invariant['observation']);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    private function cleanArgs(): array
    {
        return [
            [
                'source' => 'synthetic_diff_manifest',
                'base_revision' => 'baseline-x',
                'head_revision' => 'head-y',
                'files' => [
                    ['path' => 'app/Services/Ai/SelfConstruction/Sample.php', 'change_kind' => 'modified', 'lines_added' => 12, 'lines_deleted' => 4, 'hunk_count' => 3, 'content_hash' => str_repeat('a', 64)],
                    ['path' => 'app/Services/Ai/SelfConstruction/Another.php', 'change_kind' => 'added', 'lines_added' => 25, 'lines_deleted' => 0, 'hunk_count' => 1, 'content_hash' => str_repeat('b', 64)],
                ],
            ],
            ['artifacts' => [
                ['kind' => 'test', 'name' => 'phpunit', 'status' => 'passed', 'evidence_hash' => str_repeat('c', 64)],
                ['kind' => 'lint', 'name' => 'phpstan', 'status' => 'passed', 'evidence_hash' => str_repeat('d', 64)],
            ]],
            ['allowed_files' => ['app/Services/Ai/SelfConstruction/']],
            ['packet_id' => 'p-clean', 'claim_id' => 'c-clean', 'task_packet_id' => 't-clean', 'generated_at' => '2026-05-14T00:00:00+00:00'],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    private function criticalArgs(): array
    {
        return [
            [
                'source' => 'synthetic_diff_manifest',
                'files' => [
                    ['path' => 'config/secret.php', 'change_kind' => 'modified', 'lines_added' => 1, 'content_hash' => str_repeat('a', 64)],
                    ['path' => 'routes/api.php', 'change_kind' => 'modified', 'lines_added' => 1, 'content_hash' => str_repeat('b', 64)],
                    ['path' => '../escape.php', 'change_kind' => 'modified', 'lines_added' => 1, 'content_hash' => str_repeat('c', 64)],
                ],
            ],
            ['artifacts' => [
                ['kind' => 'test', 'name' => 'phpunit', 'status' => 'failed'],
            ]],
            ['allowed_files' => ['app/'], 'forbidden_files' => ['config/secret.php']],
            ['packet_id' => 'p-critical', 'claim_id' => 'c-critical', 'task_packet_id' => 't-critical', 'generated_at' => '2026-05-14T00:00:00+00:00'],
        ];
    }
}
