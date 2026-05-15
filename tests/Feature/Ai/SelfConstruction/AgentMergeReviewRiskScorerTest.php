<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentMergeReviewPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentMergeReviewRiskScorer;
use App\Services\Ai\SelfConstruction\AgentMergeReviewScopeVerifier;
use Tests\TestCase;

final class AgentMergeReviewRiskScorerTest extends TestCase
{
    public function test_constants_are_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_merge_review_risk_score.v1', AgentMergeReviewRiskScorer::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_merge_review_risk_score', AgentMergeReviewRiskScorer::MODE);
        $this->assertSame(['low', 'medium', 'high', 'critical'], AgentMergeReviewRiskScorer::RISK_BANDS);
    }

    public function test_envelope_marks_no_side_effects(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $result = $svc->score($this->packetWithFiles([['path' => 'a.php', 'change_kind' => 'modified', 'lines_added' => 1]]));
        $this->assertFalse($result['apply_patch_allowed']);
        $this->assertFalse($result['real_file_write_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    public function test_non_execution_guarantees_present(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $result = $svc->score($this->packetWithFiles([]));
        $this->assertContains('agent_merge_review_risk_scorer_does_not_apply_patch', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_risk_scorer_does_not_modify_real_files', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_risk_scorer_does_not_advance_completion_claim', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_risk_scorer_does_not_dispatch_agent', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_risk_scorer_does_not_write_ledger', $result['non_execution_guarantees']);
    }

    public function test_small_change_is_low_band(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([['path' => 'app/X.php', 'change_kind' => 'modified', 'lines_added' => 3, 'lines_deleted' => 2]]);
        $scope = $this->cleanScope($packet);
        $result = $svc->score($packet, $scope);
        $this->assertSame('low', $result['risk']['overall_band']);
        $this->assertFalse($result['risk']['critical']);
    }

    public function test_many_files_pushes_blast_radius_weight(): void
    {
        $files = [];
        for ($i = 0; $i < 25; $i++) {
            $files[] = ['path' => 'app/File'.$i.'.php', 'change_kind' => 'modified', 'lines_added' => 5];
        }
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles($files);
        $scope = $this->cleanScope($packet);
        $result = $svc->score($packet, $scope);
        $blastRadius = $this->factorWeight($result, 'blast_radius_file_count');
        $this->assertGreaterThanOrEqual(3, $blastRadius);
    }

    public function test_lots_of_lines_pushes_net_lines_weight(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([['path' => 'app/Big.php', 'change_kind' => 'modified', 'lines_added' => 2500, 'lines_deleted' => 0]]);
        $scope = $this->cleanScope($packet);
        $result = $svc->score($packet, $scope);
        $this->assertGreaterThanOrEqual(4, $this->factorWeight($result, 'blast_radius_net_lines'));
    }

    public function test_forbidden_violations_push_band_to_critical(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([
            ['path' => 'config/secret.php', 'change_kind' => 'modified', 'lines_added' => 1],
            ['path' => 'routes/api.php', 'change_kind' => 'modified', 'lines_added' => 1],
            ['path' => '../escape.php', 'change_kind' => 'modified', 'lines_added' => 1],
        ]);
        $scope = (new AgentMergeReviewScopeVerifier)->verify($packet, ['allowed_files' => ['app/'], 'forbidden_files' => ['config/secret.php']]);
        $result = $svc->score($packet, $scope);
        $this->assertSame('critical', $result['risk']['overall_band']);
        $this->assertContains('forbidden_path_violation_present', $result['risk']['blockers']);
        $this->assertTrue($result['risk']['critical']);
    }

    public function test_cross_axis_violations_register_blocker(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([['path' => 'routes/api.php', 'change_kind' => 'modified', 'lines_added' => 2]]);
        $scope = (new AgentMergeReviewScopeVerifier)->verify($packet, ['allowed_files' => ['app/']]);
        $result = $svc->score($packet, $scope);
        $this->assertContains('cross_axis_violation_present', $result['risk']['blockers']);
    }

    public function test_unsafe_path_violation_registers_blocker(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([['path' => '../escape.php', 'change_kind' => 'modified', 'lines_added' => 2]]);
        $scope = (new AgentMergeReviewScopeVerifier)->verify($packet, ['allowed_files' => ['app/']]);
        $result = $svc->score($packet, $scope);
        $this->assertContains('unsafe_path_prefix_present', $result['risk']['blockers']);
    }

    public function test_out_of_scope_count_registers_blocker(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([['path' => 'somewhere/Strange.php', 'change_kind' => 'modified', 'lines_added' => 1]]);
        $scope = (new AgentMergeReviewScopeVerifier)->verify($packet, ['allowed_files' => ['app/']]);
        $result = $svc->score($packet, $scope);
        $this->assertContains('out_of_scope_paths_present', $result['risk']['blockers']);
    }

    public function test_failing_artifact_pushes_band(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $diff = ['files' => [['path' => 'app/X.php', 'change_kind' => 'modified', 'lines_added' => 2]]];
        $artifacts = ['artifacts' => [['kind' => 'test', 'name' => 'phpunit', 'status' => 'failed']]];
        $packet = (new AgentMergeReviewPacketBuilder)->build($diff, $artifacts, $this->ctx());
        $scope = (new AgentMergeReviewScopeVerifier)->verify($packet, ['allowed_files' => ['app/']]);
        $result = $svc->score($packet, $scope);
        $this->assertContains('failing_artifact_present', $result['risk']['blockers']);
    }

    public function test_critical_band_status_string(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([
            ['path' => 'routes/api.php', 'change_kind' => 'deleted', 'lines_deleted' => 800],
            ['path' => 'atlas-desktop/src/App.tsx', 'change_kind' => 'modified', 'lines_added' => 600],
            ['path' => '../escape.php', 'change_kind' => 'modified', 'lines_added' => 5],
        ]);
        $scope = (new AgentMergeReviewScopeVerifier)->verify($packet, ['allowed_files' => ['app/']]);
        $result = $svc->score($packet, $scope);
        $this->assertSame('agent_merge_review_risk_critical', $result['status']);
        $this->assertSame('critical', $result['risk']['overall_band']);
    }

    public function test_low_band_status_string(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([['path' => 'app/X.php', 'change_kind' => 'modified', 'lines_added' => 1]]);
        $scope = $this->cleanScope($packet);
        $result = $svc->score($packet, $scope);
        $this->assertSame('agent_merge_review_risk_scored', $result['status']);
    }

    public function test_factor_count_matches_grid(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([['path' => 'app/X.php', 'change_kind' => 'modified', 'lines_added' => 1]]);
        $scope = $this->cleanScope($packet);
        $result = $svc->score($packet, $scope);
        $this->assertSame(10, $result['risk']['factor_count']);
        $this->assertCount(10, $result['risk']['factors']);
    }

    public function test_overall_score_sums_factor_weights(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([['path' => 'config/secret.php', 'change_kind' => 'modified', 'lines_added' => 2]]);
        $scope = (new AgentMergeReviewScopeVerifier)->verify($packet, ['allowed_files' => ['app/'], 'forbidden_files' => ['config/secret.php']]);
        $result = $svc->score($packet, $scope);
        $sum = array_sum(array_column($result['risk']['factors'], 'weight'));
        $this->assertSame($sum, $result['risk']['overall_score']);
    }

    public function test_blast_radius_struct_present(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([['path' => 'app/X.php', 'change_kind' => 'modified', 'lines_added' => 3, 'lines_deleted' => 2]]);
        $result = $svc->score($packet, $this->cleanScope($packet));
        $blast = $result['risk']['blast_radius'];
        $this->assertSame(1, $blast['file_count']);
        $this->assertSame(3, $blast['lines_added']);
        $this->assertSame(2, $blast['lines_deleted']);
        $this->assertSame(1, $blast['net_lines']);
    }

    public function test_no_artifacts_pushes_artifacts_missing_factor(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([['path' => 'app/X.php', 'change_kind' => 'modified', 'lines_added' => 1]]);
        $result = $svc->score($packet, $this->cleanScope($packet));
        $this->assertSame(1, $this->factorWeight($result, 'artifacts_missing'));
    }

    public function test_risk_hash_stable_sha256(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([['path' => 'app/X.php', 'change_kind' => 'modified', 'lines_added' => 1]]);
        $scope = $this->cleanScope($packet);
        $a = $svc->score($packet, $scope);
        $b = $svc->score($packet, $scope);
        $this->assertSame($a['risk_hash'], $b['risk_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['risk_hash']);
    }

    public function test_deletion_signal_weighted(): void
    {
        $svc = new AgentMergeReviewRiskScorer;
        $packet = $this->packetWithFiles([
            ['path' => 'app/Gone.php', 'change_kind' => 'deleted', 'lines_deleted' => 10],
            ['path' => 'app/AlsoGone.php', 'change_kind' => 'deleted', 'lines_deleted' => 5],
        ]);
        $result = $svc->score($packet, $this->cleanScope($packet));
        $this->assertGreaterThanOrEqual(2, $this->factorWeight($result, 'deletions_signal'));
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return array<string, mixed>
     */
    private function packetWithFiles(array $files): array
    {
        return (new AgentMergeReviewPacketBuilder)->build(['files' => $files], [], $this->ctx());
    }

    /**
     * @return array<string, mixed>
     */
    private function ctx(): array
    {
        return ['packet_id' => 'p', 'claim_id' => 'c', 'task_packet_id' => 't', 'generated_at' => '2026-05-14T00:00:00+00:00'];
    }

    /**
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    private function cleanScope(array $packet): array
    {
        $allowed = [];
        foreach ((array) data_get($packet, 'packet.files', []) as $file) {
            $allowed[] = (string) ($file['path'] ?? '');
        }

        return (new AgentMergeReviewScopeVerifier)->verify($packet, ['allowed_files' => $allowed]);
    }

    private function factorWeight(array $result, string $name): int
    {
        foreach ($result['risk']['factors'] as $factor) {
            if ($factor['name'] === $name) {
                return (int) $factor['weight'];
            }
        }

        return -1;
    }
}
