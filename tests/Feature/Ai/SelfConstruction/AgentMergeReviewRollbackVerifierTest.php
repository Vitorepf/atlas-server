<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentMergeReviewHumanApprovalPlanner;
use App\Services\Ai\SelfConstruction\AgentMergeReviewPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentMergeReviewPromotionDryRun;
use App\Services\Ai\SelfConstruction\AgentMergeReviewRiskScorer;
use App\Services\Ai\SelfConstruction\AgentMergeReviewRollbackVerifier;
use App\Services\Ai\SelfConstruction\AgentMergeReviewScopeVerifier;
use Tests\TestCase;

final class AgentMergeReviewRollbackVerifierTest extends TestCase
{
    public function test_constants_are_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_merge_review_rollback_verification.v1', AgentMergeReviewRollbackVerifier::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_merge_review_rollback_verification', AgentMergeReviewRollbackVerifier::MODE);
        $this->assertSame(['low', 'medium', 'high'], AgentMergeReviewRollbackVerifier::CONFIDENCE_BANDS);
    }

    public function test_envelope_marks_no_side_effects(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $result = $svc->verify($packet, $dryRun);
        $this->assertFalse($result['apply_patch_allowed']);
        $this->assertFalse($result['real_file_write_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertFalse($result['rollback_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    public function test_non_execution_guarantees_present(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $result = $svc->verify($packet, $dryRun);
        $this->assertContains('agent_merge_review_rollback_verifier_does_not_execute_rollback', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_rollback_verifier_does_not_restore_files', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_rollback_verifier_does_not_apply_patch', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_rollback_verifier_does_not_advance_completion_claim', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_rollback_verifier_does_not_dispatch_agent', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_rollback_verifier_does_not_write_ledger', $result['non_execution_guarantees']);
    }

    public function test_clean_scenario_verifies_all_steps_high_confidence(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $result = $svc->verify($packet, $dryRun);
        $this->assertSame('agent_merge_review_rollback_verified', $result['status']);
        $this->assertTrue($result['verification']['all_steps_reversible']);
        $this->assertSame(0, $result['verification']['unverified_step_count']);
        $this->assertSame('high', $result['verification']['confidence']);
    }

    public function test_missing_content_hash_drops_confidence_to_medium(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenarioWithoutHashes();
        $result = $svc->verify($packet, $dryRun);
        $this->assertSame('medium', $result['verification']['confidence']);
        $this->assertGreaterThan(0, $result['verification']['missing_hash_step_count']);
    }

    public function test_unknown_path_marks_unverified(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $dryRun['plan']['rollback_steps'][] = [
            'name' => 'rollback_simulation:modified',
            'path' => 'app/UnknownPath.php',
            'change_kind' => 'modified',
            'has_side_effect' => false,
            'side_effect_kind' => 'no',
        ];
        $result = $svc->verify($packet, $dryRun);
        $this->assertGreaterThan(0, $result['verification']['unverified_step_count']);
        $this->assertSame('agent_merge_review_rollback_verification_incomplete', $result['status']);
        $this->assertSame('low', $result['verification']['confidence']);
    }

    public function test_change_kind_mismatch_is_flagged(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $dryRun['plan']['rollback_steps'][0]['change_kind'] = 'deleted';
        $result = $svc->verify($packet, $dryRun);
        $reasons = array_column($result['verification']['unverified_steps'], 'reason');
        $this->assertContains('rollback_step_change_kind_mismatch', $reasons);
    }

    public function test_discard_worktree_step_marked_reversible(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $result = $svc->verify($packet, $dryRun);
        $discard = null;
        foreach ($result['verification']['verified_steps'] as $step) {
            if ($step['name'] === 'discard_isolated_worktree') {
                $discard = $step;
                break;
            }
        }
        $this->assertNotNull($discard);
        $this->assertSame('worktree_scope_only', $discard['reversibility']);
    }

    public function test_step_without_path_and_not_discard_is_unverified(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $dryRun['plan']['rollback_steps'][] = [
            'name' => 'rollback_simulation:weird',
            'change_kind' => 'modified',
            'has_side_effect' => false,
            'side_effect_kind' => 'no',
        ];
        $result = $svc->verify($packet, $dryRun);
        $reasons = array_column($result['verification']['unverified_steps'], 'reason');
        $this->assertContains('rollback_step_missing_path', $reasons);
    }

    public function test_rollback_coverage_ratio_is_in_unit_interval(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $result = $svc->verify($packet, $dryRun);
        $this->assertGreaterThanOrEqual(0.0, $result['verification']['rollback_coverage_ratio']);
        $this->assertLessThanOrEqual(1.0, $result['verification']['rollback_coverage_ratio']);
    }

    public function test_empty_files_marks_coverage_as_one(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        $packet = (new AgentMergeReviewPacketBuilder)->build(['files' => []], [], ['packet_id' => 'p']);
        $dryRun = (new AgentMergeReviewPromotionDryRun)->plan(
            $packet,
            (new AgentMergeReviewScopeVerifier)->verify($packet, []),
            (new AgentMergeReviewRiskScorer)->score($packet),
            (new AgentMergeReviewHumanApprovalPlanner)->plan($packet, [], [])
        );
        $result = $svc->verify($packet, $dryRun);
        $this->assertSame(1.0, $result['verification']['rollback_coverage_ratio']);
    }

    public function test_non_array_steps_are_skipped(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $dryRun['plan']['rollback_steps'][] = 'scalar';
        $dryRun['plan']['rollback_steps'][] = null;
        $result = $svc->verify($packet, $dryRun);
        $this->assertTrue($result['verification']['all_steps_reversible']);
    }

    public function test_verification_hash_stable(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $a = $svc->verify($packet, $dryRun);
        $b = $svc->verify($packet, $dryRun);
        $this->assertSame($a['verification_hash'], $b['verification_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['verification_hash']);
    }

    public function test_verified_step_count_matches_array(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $result = $svc->verify($packet, $dryRun);
        $this->assertSame(count($result['verification']['verified_steps']), $result['verification']['verified_step_count']);
    }

    public function test_unverified_step_count_matches_array(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $dryRun['plan']['rollback_steps'][] = [
            'name' => 'rollback_simulation:modified',
            'path' => 'app/UnknownPath.php',
            'change_kind' => 'modified',
            'has_side_effect' => false,
            'side_effect_kind' => 'no',
        ];
        $result = $svc->verify($packet, $dryRun);
        $this->assertSame(count($result['verification']['unverified_steps']), $result['verification']['unverified_step_count']);
    }

    public function test_missing_hash_steps_field_present(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenarioWithoutHashes();
        $result = $svc->verify($packet, $dryRun);
        $this->assertArrayHasKey('missing_hash_steps', $result['verification']);
        $this->assertSame(count($result['verification']['missing_hash_steps']), $result['verification']['missing_hash_step_count']);
    }

    public function test_reversibility_marks_with_hash_when_hash_present(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $result = $svc->verify($packet, $dryRun);
        $hashed = array_filter($result['verification']['verified_steps'], static fn ($s) => ($s['reversibility'] ?? '') === 'reversible_with_hash_check');
        $this->assertNotEmpty($hashed);
    }

    public function test_reversibility_marks_without_hash_when_hash_missing(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenarioWithoutHashes();
        $result = $svc->verify($packet, $dryRun);
        $unhashed = array_filter($result['verification']['verified_steps'], static fn ($s) => ($s['reversibility'] ?? '') === 'reversible_without_hash_check');
        $this->assertNotEmpty($unhashed);
    }

    public function test_status_string_when_incomplete(): void
    {
        $svc = new AgentMergeReviewRollbackVerifier;
        [$packet, $dryRun] = $this->scenario();
        $dryRun['plan']['rollback_steps'][] = ['name' => 'rollback_simulation:modified', 'path' => 'app/Unknown.php', 'change_kind' => 'modified'];
        $result = $svc->verify($packet, $dryRun);
        $this->assertSame('agent_merge_review_rollback_verification_incomplete', $result['status']);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function scenario(): array
    {
        $files = [
            ['path' => 'app/Foo.php', 'change_kind' => 'modified', 'lines_added' => 1, 'content_hash' => str_repeat('a', 64)],
            ['path' => 'app/Bar.php', 'change_kind' => 'added', 'lines_added' => 3, 'content_hash' => str_repeat('b', 64)],
        ];
        $packet = (new AgentMergeReviewPacketBuilder)->build(['files' => $files], [], ['packet_id' => 'p']);
        $scope = (new AgentMergeReviewScopeVerifier)->verify($packet, ['allowed_files' => ['app/']]);
        $risk = (new AgentMergeReviewRiskScorer)->score($packet, $scope);
        $approval = (new AgentMergeReviewHumanApprovalPlanner)->plan($packet, $scope, $risk);
        $dryRun = (new AgentMergeReviewPromotionDryRun)->plan($packet, $scope, $risk, $approval);

        return [$packet, $dryRun];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function scenarioWithoutHashes(): array
    {
        $files = [
            ['path' => 'app/Foo.php', 'change_kind' => 'modified', 'lines_added' => 1],
            ['path' => 'app/Bar.php', 'change_kind' => 'added', 'lines_added' => 3],
        ];
        $packet = (new AgentMergeReviewPacketBuilder)->build(['files' => $files], [], ['packet_id' => 'p']);
        $scope = (new AgentMergeReviewScopeVerifier)->verify($packet, ['allowed_files' => ['app/']]);
        $risk = (new AgentMergeReviewRiskScorer)->score($packet, $scope);
        $approval = (new AgentMergeReviewHumanApprovalPlanner)->plan($packet, $scope, $risk);
        $dryRun = (new AgentMergeReviewPromotionDryRun)->plan($packet, $scope, $risk, $approval);

        return [$packet, $dryRun];
    }
}
