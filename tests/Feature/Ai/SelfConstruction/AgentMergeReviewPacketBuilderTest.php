<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentMergeReviewPacketBuilder;
use Tests\TestCase;

final class AgentMergeReviewPacketBuilderTest extends TestCase
{
    public function test_constants_are_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_merge_review_packet.v1', AgentMergeReviewPacketBuilder::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_merge_review_packet', AgentMergeReviewPacketBuilder::MODE);
        $this->assertSame(['added', 'modified', 'deleted', 'renamed'], AgentMergeReviewPacketBuilder::CHANGE_KINDS);
    }

    public function test_envelope_top_level_marks_no_side_effects(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $result = $svc->build($this->minimalDiff(), $this->minimalArtifacts(), $this->minimalContext());
        $this->assertSame('agent_merge_review_packet_ready', $result['status']);
        $this->assertFalse($result['apply_patch_allowed']);
        $this->assertFalse($result['real_file_write_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    public function test_non_execution_guarantees_present(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $result = $svc->build($this->minimalDiff(), [], $this->minimalContext());
        $this->assertNotEmpty($result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_packet_does_not_apply_patch', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_packet_does_not_modify_real_files', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_packet_does_not_advance_completion_claim', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_packet_does_not_persist_claim', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_packet_does_not_write_ledger', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_packet_does_not_dispatch_agent', $result['non_execution_guarantees']);
    }

    public function test_packet_carries_context_metadata(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $result = $svc->build($this->minimalDiff(), [], $this->minimalContext());
        $this->assertSame('p1', $result['packet']['packet_id']);
        $this->assertSame('c1', $result['packet']['claim_id']);
        $this->assertSame('t1', $result['packet']['task_packet_id']);
        $this->assertSame('synthetic_diff_manifest', $result['packet']['source']);
        $this->assertSame('baseline-x', $result['packet']['base_revision']);
        $this->assertSame('head-y', $result['packet']['head_revision']);
    }

    public function test_packet_handles_missing_context_with_safe_defaults(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $result = $svc->build([], [], []);
        $this->assertSame('agent-merge-review-packet-unknown', $result['packet']['packet_id']);
        $this->assertSame('agent-merge-review-claim-unknown', $result['packet']['claim_id']);
        $this->assertSame('agent-merge-review-task-packet-unknown', $result['packet']['task_packet_id']);
        $this->assertSame('synthetic_diff_manifest', $result['packet']['source']);
        $this->assertSame('baseline-unknown', $result['packet']['base_revision']);
        $this->assertSame('head-unknown', $result['packet']['head_revision']);
    }

    public function test_files_are_normalized_and_sorted(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $diff = ['files' => [
            ['path' => 'b/two.php', 'change_kind' => 'modified', 'lines_added' => 1],
            ['path' => 'a/one.php', 'change_kind' => 'added', 'lines_added' => 5],
            ['path' => 'c/three.php', 'change_kind' => 'deleted', 'lines_deleted' => 9],
        ]];
        $result = $svc->build($diff, [], $this->minimalContext());
        $paths = array_column($result['packet']['files'], 'path');
        $this->assertSame(['a/one.php', 'b/two.php', 'c/three.php'], $paths);
    }

    public function test_invalid_change_kind_falls_back_to_modified(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $diff = ['files' => [
            ['path' => 'x.php', 'change_kind' => 'INVALID_KIND'],
        ]];
        $result = $svc->build($diff, [], $this->minimalContext());
        $this->assertSame('modified', $result['packet']['files'][0]['change_kind']);
    }

    public function test_negative_numbers_clamp_to_zero(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $diff = ['files' => [
            ['path' => 'x.php', 'change_kind' => 'modified', 'lines_added' => -10, 'lines_deleted' => -7, 'hunk_count' => -3],
        ]];
        $result = $svc->build($diff, [], $this->minimalContext());
        $this->assertSame(0, $result['packet']['files'][0]['lines_added']);
        $this->assertSame(0, $result['packet']['files'][0]['lines_deleted']);
        $this->assertSame(0, $result['packet']['files'][0]['hunk_count']);
    }

    public function test_non_string_path_entries_are_skipped(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $diff = ['files' => [
            null,
            ['path' => ''],
            'not-an-array',
            ['path' => 'valid.php', 'change_kind' => 'added', 'lines_added' => 1],
        ]];
        $result = $svc->build($diff, [], $this->minimalContext());
        $this->assertCount(1, $result['packet']['files']);
        $this->assertSame('valid.php', $result['packet']['files'][0]['path']);
    }

    public function test_file_stats_count_kinds_and_lines(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $diff = ['files' => [
            ['path' => 'a.php', 'change_kind' => 'added', 'lines_added' => 10, 'lines_deleted' => 0, 'hunk_count' => 2],
            ['path' => 'b.php', 'change_kind' => 'modified', 'lines_added' => 5, 'lines_deleted' => 3, 'hunk_count' => 1],
            ['path' => 'c.php', 'change_kind' => 'deleted', 'lines_added' => 0, 'lines_deleted' => 15, 'hunk_count' => 4],
            ['path' => 'd.php', 'change_kind' => 'renamed', 'lines_added' => 1, 'lines_deleted' => 1, 'hunk_count' => 1, 'previous_path' => 'd-old.php'],
        ]];
        $result = $svc->build($diff, [], $this->minimalContext());
        $stats = $result['packet']['file_stats'];
        $this->assertSame(4, $stats['file_count']);
        $this->assertSame(1, $stats['added_count']);
        $this->assertSame(1, $stats['modified_count']);
        $this->assertSame(1, $stats['deleted_count']);
        $this->assertSame(1, $stats['renamed_count']);
        $this->assertSame(16, $stats['lines_added_total']);
        $this->assertSame(19, $stats['lines_deleted_total']);
        $this->assertSame(8, $stats['hunk_count_total']);
        $this->assertSame(-3, $stats['net_lines']);
    }

    public function test_previous_path_preserved_for_rename(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $diff = ['files' => [
            ['path' => 'new.php', 'change_kind' => 'renamed', 'previous_path' => 'old.php'],
        ]];
        $result = $svc->build($diff, [], $this->minimalContext());
        $this->assertSame('old.php', $result['packet']['files'][0]['previous_path']);
    }

    public function test_empty_previous_path_normalizes_to_null(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $diff = ['files' => [
            ['path' => 'x.php', 'change_kind' => 'added', 'previous_path' => ''],
        ]];
        $result = $svc->build($diff, [], $this->minimalContext());
        $this->assertNull($result['packet']['files'][0]['previous_path']);
    }

    public function test_artifacts_are_normalized_and_sorted(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $artifacts = ['artifacts' => [
            ['kind' => 'lint', 'name' => 'phpstan', 'status' => 'passed'],
            ['kind' => 'test', 'name' => 'phpunit', 'status' => 'passed'],
            ['kind' => 'lint', 'name' => 'pint', 'status' => 'failed'],
        ]];
        $result = $svc->build($this->minimalDiff(), $artifacts, $this->minimalContext());
        $names = array_map(static fn ($a) => $a['kind'].'/'.$a['name'], $result['packet']['artifacts']);
        $this->assertSame(['lint/phpstan', 'lint/pint', 'test/phpunit'], $names);
    }

    public function test_artifact_stats_compute_pass_fail_summary(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $artifacts = ['artifacts' => [
            ['kind' => 'test', 'name' => 'phpunit', 'status' => 'passed'],
            ['kind' => 'test', 'name' => 'feature', 'status' => 'green'],
            ['kind' => 'lint', 'name' => 'pint', 'status' => 'failed'],
            ['kind' => 'sec', 'name' => 'audit', 'status' => 'unknown'],
        ]];
        $result = $svc->build($this->minimalDiff(), $artifacts, $this->minimalContext());
        $stats = $result['packet']['artifact_stats'];
        $this->assertSame(4, $stats['artifact_count']);
        $this->assertSame(2, $stats['passing_count']);
        $this->assertSame(2, $stats['failing_count']);
        $this->assertFalse($stats['all_passing']);
    }

    public function test_artifact_stats_with_no_artifacts_marks_all_passing_false(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $result = $svc->build($this->minimalDiff(), [], $this->minimalContext());
        $this->assertSame(0, $result['packet']['artifact_stats']['artifact_count']);
        $this->assertFalse($result['packet']['artifact_stats']['all_passing']);
    }

    public function test_packet_hash_is_stable_sha256(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $a = $svc->build($this->minimalDiff(), $this->minimalArtifacts(), $this->minimalContext());
        $b = $svc->build($this->minimalDiff(), $this->minimalArtifacts(), $this->minimalContext());
        $this->assertSame($a['packet_hash'], $b['packet_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['packet_hash']);
    }

    public function test_packet_hash_changes_when_files_change(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $a = $svc->build(['files' => [['path' => 'a.php', 'change_kind' => 'added', 'lines_added' => 1]]], [], $this->minimalContext());
        $b = $svc->build(['files' => [['path' => 'b.php', 'change_kind' => 'added', 'lines_added' => 1]]], [], $this->minimalContext());
        $this->assertNotSame($a['packet_hash'], $b['packet_hash']);
    }

    public function test_non_array_artifact_entries_are_ignored(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $artifacts = ['artifacts' => ['scalar', null, 42, ['kind' => 'test', 'name' => 'ok', 'status' => 'passed']]];
        $result = $svc->build($this->minimalDiff(), $artifacts, $this->minimalContext());
        $this->assertCount(1, $result['packet']['artifacts']);
    }

    public function test_explicit_generated_at_is_preserved(): void
    {
        $svc = new AgentMergeReviewPacketBuilder;
        $result = $svc->build($this->minimalDiff(), [], array_merge($this->minimalContext(), ['generated_at' => '2026-05-14T12:00:00+00:00']));
        $this->assertSame('2026-05-14T12:00:00+00:00', $result['generated_at']);
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalDiff(): array
    {
        return [
            'source' => 'synthetic_diff_manifest',
            'base_revision' => 'baseline-x',
            'head_revision' => 'head-y',
            'files' => [
                ['path' => 'app/Services/Ai/SelfConstruction/Sample.php', 'change_kind' => 'modified', 'lines_added' => 12, 'lines_deleted' => 4, 'hunk_count' => 3, 'content_hash' => str_repeat('a', 64)],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalArtifacts(): array
    {
        return [
            'artifacts' => [
                ['kind' => 'test', 'name' => 'phpunit', 'status' => 'passed', 'evidence_hash' => str_repeat('b', 64)],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalContext(): array
    {
        return [
            'packet_id' => 'p1',
            'claim_id' => 'c1',
            'task_packet_id' => 't1',
            'generated_at' => '2026-05-14T00:00:00+00:00',
        ];
    }
}
