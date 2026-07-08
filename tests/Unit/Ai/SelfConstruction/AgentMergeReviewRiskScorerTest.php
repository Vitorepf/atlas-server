<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentMergeReviewRiskScorer;
use Tests\TestCase;

final class AgentMergeReviewRiskScorerTest extends TestCase
{
    private function score(array $packet = [], array $scopeVerification = []): array
    {
        return (new AgentMergeReviewRiskScorer)->score($packet, $scopeVerification);
    }

    // ── Constants ──────────────────────────────────────────────────────

    public function test_constants_are_canonical(): void
    {
        $this->assertSame(
            'atlas.self_construction.agent_merge_review_risk_score.v1',
            AgentMergeReviewRiskScorer::SCHEMA_VERSION,
        );
        $this->assertSame(
            'read_only_agent_merge_review_risk_score',
            AgentMergeReviewRiskScorer::MODE,
        );
        $this->assertSame(['low', 'medium', 'high', 'critical'], AgentMergeReviewRiskScorer::RISK_BANDS);
    }

    public function test_envelope_marks_no_side_effects(): void
    {
        $r = $this->score();

        $this->assertFalse($r['apply_patch_allowed']);
        $this->assertFalse($r['real_file_write_allowed']);
        $this->assertFalse($r['completion_claim_allowed']);
        $this->assertFalse($r['dispatch_allowed']);
        $this->assertFalse($r['ledger_write_allowed']);
    }

    public function test_non_execution_guarantees_present(): void
    {
        $r = $this->score();

        $this->assertNotEmpty($r['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_risk_scorer_does_not_apply_patch', $r['non_execution_guarantees']);
    }

    // ── Blast radius ───────────────────────────────────────────────────

    public function test_small_change_is_low_band(): void
    {
        $r = $this->score([
            'packet' => ['file_stats' => ['file_count' => 1, 'net_lines' => 5]],
        ]);

        $this->assertSame('low', $r['risk']['overall_band']);
        $this->assertSame('approve', $r['decision']);
    }

    public function test_many_files_pushes_blast_radius_weight(): void
    {
        $r = $this->score([
            'packet' => ['file_stats' => ['file_count' => 60, 'net_lines' => 10]],
        ]);

        // 60 files = weight 4 for blast_radius_file_count
        $weights = array_column($r['risk_factors'], 'weight');
        $this->assertContains(4, $weights);
    }

    public function test_lots_of_lines_pushes_net_lines_weight(): void
    {
        $r = $this->score([
            'packet' => ['file_stats' => ['file_count' => 1, 'lines_added_total' => 1500, 'lines_deleted_total' => 1500]],
        ]);

        $weights = array_column($r['risk_factors'], 'weight');
        $this->assertContains(4, $weights);
    }

    public function test_forbidden_violations_push_band_to_critical(): void
    {
        $r = $this->score([], [
            'verification' => ['forbidden_violation_count' => 5],
        ]);

        $this->assertSame('critical', $r['risk']['overall_band']);
        $this->assertSame('reject_merge', $r['decision']);
    }

    // ── Violation blockers ─────────────────────────────────────────────

    public function test_cross_axis_violations_register_blocker(): void
    {
        $r = $this->score([], [
            'verification' => ['cross_axis_violation_count' => 2],
        ]);

        $this->assertContains('cross_axis_violation_present', $r['risk']['blockers']);
    }

    public function test_unsafe_path_violation_registers_blocker(): void
    {
        $r = $this->score([], [
            'verification' => ['unsafe_path_violation_count' => 1],
        ]);

        $this->assertContains('unsafe_path_prefix_present', $r['risk']['blockers']);
    }

    public function test_out_of_scope_count_registers_blocker(): void
    {
        $r = $this->score([], [
            'verification' => ['out_of_scope_count' => 3],
        ]);

        $this->assertContains('out_of_scope_paths_present', $r['risk']['blockers']);
    }

    // ── Failing artifacts ──────────────────────────────────────────────

    public function test_failing_artifact_pushes_band(): void
    {
        $r = $this->score([
            'packet' => ['artifact_stats' => ['failing_count' => 3]],
        ]);

        $this->assertGreaterThanOrEqual(3, $r['risk_score']);
    }

    public function test_critical_band_status_string(): void
    {
        $r = $this->score([], [
            'verification' => ['forbidden_violation_count' => 5],
        ]);

        $this->assertSame('agent_merge_review_risk_critical', $r['status']);
    }

    public function test_low_band_status_string(): void
    {
        $r = $this->score([
            'packet' => ['file_stats' => ['file_count' => 1, 'net_lines' => 5]],
        ]);

        $this->assertSame('agent_merge_review_risk_scored', $r['status']);
    }

    // ── Structure ──────────────────────────────────────────────────────

    public function test_factor_count_matches_grid(): void
    {
        $r = $this->score([
            'packet' => ['file_stats' => ['file_count' => 1, 'net_lines' => 5]],
        ]);

        // 10 fixed factors + 0 optional (no weak evidence, no missing tests, no hard rollback)
        $this->assertCount(10, $r['risk_factors']);
        $this->assertSame(10, $r['risk']['factor_count']);
    }

    public function test_overall_score_sums_factor_weights(): void
    {
        $r = $this->score([
            'packet' => ['file_stats' => ['file_count' => 1, 'net_lines' => 5]],
        ]);

        $expected = array_sum(array_column($r['risk_factors'], 'weight'));
        $this->assertSame($expected, $r['risk_score']);
        $this->assertSame($expected, $r['risk']['overall_score']);
    }

    public function test_blast_radius_struct_present(): void
    {
        $r = $this->score([
            'packet' => ['file_stats' => ['file_count' => 5, 'lines_added_total' => 100, 'lines_deleted_total' => 20, 'net_lines' => 80, 'deleted_count' => 1, 'renamed_count' => 0]],
        ]);

        $this->assertSame(5, $r['risk']['blast_radius']['file_count']);
        $this->assertSame(100, $r['risk']['blast_radius']['lines_added']);
        $this->assertSame(20, $r['risk']['blast_radius']['lines_deleted']);
    }

    // ── Special factors ────────────────────────────────────────────────

    public function test_no_artifacts_pushes_artifacts_missing_factor(): void
    {
        $r = $this->score([
            'packet' => ['artifact_stats' => ['artifact_count' => 0]],
        ]);

        $names = array_column($r['risk_factors'], 'name');
        $this->assertContains('artifacts_missing', $names);
    }

    public function test_risk_hash_stable_sha256(): void
    {
        $a = $this->score(['packet' => ['file_stats' => ['file_count' => 1]]]);
        $b = $this->score(['packet' => ['file_stats' => ['file_count' => 1]]]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['risk_hash']);
        $this->assertSame($a['risk_hash'], $b['risk_hash']);
    }

    public function test_deletion_signal_weighted(): void
    {
        $r = $this->score([
            'packet' => ['file_stats' => ['file_count' => 1, 'net_lines' => 0, 'deleted_count' => 3]],
        ]);

        $names = array_column($r['risk_factors'], 'name');
        $this->assertContains('deletions_signal', $names);
    }

    // ── Decision scenarios ─────────────────────────────────────────────

    public function test_clean_low_risk_packet_is_approved(): void
    {
        $r = $this->score([
            'packet' => ['file_stats' => ['file_count' => 1, 'net_lines' => 5]],
        ]);

        $this->assertSame('approve', $r['decision']);
        $this->assertSame('none_safe_to_merge', $r['required_next_check']);
    }

    public function test_blocker_present_rejects_merge(): void
    {
        $r = $this->score([], [
            'verification' => ['forbidden_violation_count' => 1],
        ]);

        $this->assertSame('reject_merge', $r['decision']);
        $this->assertStringContainsString('do_not_merge', $r['required_next_check']);
    }

    public function test_weak_evidence_requires_reproof(): void
    {
        $r = $this->score([
            'packet' => [
                'file_stats' => ['file_count' => 1, 'net_lines' => 5],
                'evidence_quality' => 0.3,
            ],
        ]);

        $this->assertSame('require_reproof', $r['decision']);
        $names = array_column($r['risk_factors'], 'name');
        $this->assertContains('weak_evidence', $names);
    }

    public function test_missing_tests_when_explicitly_reported_requires_reproof(): void
    {
        $r = $this->score([
            'packet' => [
                'file_stats' => ['file_count' => 2, 'net_lines' => 20, 'test_files_touched_count' => 0],
                'implementation_files_touched' => 2,
            ],
        ]);

        $this->assertSame('require_reproof', $r['decision']);
        $names = array_column($r['risk_factors'], 'name');
        $this->assertContains('missing_tests', $names);
    }

    public function test_hard_rollback_requires_reproof(): void
    {
        $r = $this->score([
            'packet' => [
                'file_stats' => ['file_count' => 1, 'net_lines' => 5],
                'rollback_difficulty' => 0.9,
            ],
        ]);

        $this->assertSame('require_reproof', $r['decision']);
        $names = array_column($r['risk_factors'], 'name');
        $this->assertContains('rollback_difficulty', $names);
    }

    public function test_scope_drift_rejects_merge_via_blocker(): void
    {
        $r = $this->score([], [
            'verification' => ['out_of_scope_count' => 2],
        ]);

        $this->assertSame('reject_merge', $r['decision']);
        $this->assertContains('out_of_scope_paths_present', $r['risk']['blockers']);
    }
}
