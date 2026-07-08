<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentMergeReviewPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentMergeReviewScopeVerifier;
use Tests\TestCase;

final class AgentMergeReviewScopeVerifierTest extends TestCase
{
    public function test_constants_are_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_merge_review_scope_verification.v1', AgentMergeReviewScopeVerifier::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_merge_review_scope_verification', AgentMergeReviewScopeVerifier::MODE);
        $this->assertArrayHasKey('atlas_desktop', AgentMergeReviewScopeVerifier::CROSS_AXIS_BLOCKERS);
        $this->assertArrayHasKey('routes_api', AgentMergeReviewScopeVerifier::CROSS_AXIS_BLOCKERS);
        $this->assertContains('vendor/', AgentMergeReviewScopeVerifier::UNSAFE_PATH_PREFIXES);
    }

    public function test_envelope_marks_no_side_effects(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([['path' => 'app/Services/Ai/SelfConstruction/Foo.php', 'change_kind' => 'modified']]);
        $result = $svc->verify($packet, ['allowed_files' => ['app/Services/Ai/SelfConstruction/']]);
        $this->assertFalse($result['apply_patch_allowed']);
        $this->assertFalse($result['real_file_write_allowed']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    public function test_non_execution_guarantees_present(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $result = $svc->verify($this->packetWithFiles([]), []);
        $this->assertContains('agent_merge_review_scope_verifier_does_not_edit_scope', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_scope_verifier_does_not_apply_patch', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_scope_verifier_does_not_modify_real_files', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_scope_verifier_does_not_advance_completion_claim', $result['non_execution_guarantees']);
        $this->assertContains('agent_merge_review_scope_verifier_does_not_write_ledger', $result['non_execution_guarantees']);
    }

    public function test_path_inside_allowed_prefix_marks_in_scope(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([['path' => 'app/Services/Ai/SelfConstruction/Foo.php']]);
        $result = $svc->verify($packet, ['allowed_files' => ['app/Services/Ai/SelfConstruction/']]);
        $this->assertSame('agent_merge_review_scope_verified', $result['status']);
        $this->assertSame(1, $result['verification']['in_scope_count']);
        $this->assertSame(0, $result['verification']['violation_count']);
        $this->assertTrue($result['verification']['all_in_scope']);
    }

    public function test_path_outside_allowed_marks_out_of_scope(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([['path' => 'app/Services/Ai/Other/Bar.php']]);
        $result = $svc->verify($packet, ['allowed_files' => ['app/Services/Ai/SelfConstruction/']]);
        $this->assertSame(1, $result['verification']['out_of_scope_count']);
        $this->assertSame('agent_merge_review_scope_violations_present', $result['status']);
        $this->assertFalse($result['verification']['all_in_scope']);
    }

    public function test_forbidden_path_is_flagged(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([['path' => 'config/secrets.php']]);
        $result = $svc->verify($packet, [
            'allowed_files' => ['app/Services/'],
            'forbidden_files' => ['config/secrets.php'],
        ]);
        $this->assertSame(1, $result['verification']['forbidden_violation_count']);
        $this->assertSame('agent_merge_review_scope_violations_present', $result['status']);
    }

    public function test_cross_axis_path_is_flagged(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([
            ['path' => 'app/Services/Ai/SelfImprovement/Boom.php'],
            ['path' => 'routes/api.php'],
            ['path' => 'atlas-desktop/src/App.tsx'],
        ]);
        $result = $svc->verify($packet, ['allowed_files' => ['app/Services/Ai/SelfConstruction/']]);
        $this->assertGreaterThanOrEqual(3, $result['verification']['cross_axis_violation_count']);
        $axes = array_column($result['verification']['cross_axis_violations'], 'axis');
        $this->assertContains('self_improvement', $axes);
        $this->assertContains('routes_api', $axes);
        $this->assertContains('atlas_desktop', $axes);
    }

    public function test_unsafe_prefix_paths_are_flagged(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([
            ['path' => '../escape.php'],
            ['path' => '.env'],
            ['path' => 'vendor/foo/bar.php'],
            ['path' => 'node_modules/x/y.js'],
        ]);
        $result = $svc->verify($packet, ['allowed_files' => ['app/']]);
        $this->assertGreaterThanOrEqual(4, $result['verification']['unsafe_path_violation_count']);
    }

    public function test_in_scope_via_scope_in_pattern(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([['path' => 'app/Services/Foo.php']]);
        $result = $svc->verify($packet, ['allowed_files' => [], 'scope_in' => ['app/Services/']]);
        $this->assertSame(1, $result['verification']['in_scope_count']);
    }

    public function test_scope_out_treats_path_as_forbidden(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([['path' => 'storage/secret.txt']]);
        $result = $svc->verify($packet, ['allowed_files' => ['storage/'], 'scope_out' => ['storage/secret.txt']]);
        $this->assertSame(1, $result['verification']['forbidden_violation_count']);
    }

    public function test_glob_star_matches_path(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([
            ['path' => 'app/Services/Foo.php'],
            ['path' => 'app/Services/Sub/Bar.php'],
        ]);
        $result = $svc->verify($packet, ['allowed_files' => ['app/Services/*.php']]);
        $this->assertSame(1, $result['verification']['in_scope_count']);
        $this->assertSame(1, $result['verification']['out_of_scope_count']);
    }

    public function test_glob_double_star_matches_deep_paths(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([
            ['path' => 'app/Services/Foo.php'],
            ['path' => 'app/Services/Sub/Bar.php'],
        ]);
        $result = $svc->verify($packet, ['allowed_files' => ['app/Services/**.php']]);
        $this->assertSame(2, $result['verification']['in_scope_count']);
    }

    public function test_violation_counts_aggregate(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([
            ['path' => 'routes/api.php'],
            ['path' => 'vendor/foo.php'],
            ['path' => 'app/Other.php'],
            ['path' => 'config/secrets.php'],
        ]);
        $result = $svc->verify($packet, [
            'allowed_files' => ['app/Services/'],
            'forbidden_files' => ['config/secrets.php'],
        ]);
        $this->assertGreaterThanOrEqual(4, $result['verification']['violation_count']);
        $this->assertFalse($result['verification']['all_in_scope']);
    }

    public function test_empty_files_marks_all_in_scope(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([]);
        $result = $svc->verify($packet, ['allowed_files' => ['app/']]);
        $this->assertTrue($result['verification']['all_in_scope']);
        $this->assertSame(0, $result['verification']['violation_count']);
    }

    public function test_verification_hash_is_stable_sha256(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([['path' => 'app/Foo.php']]);
        $a = $svc->verify($packet, ['allowed_files' => ['app/']]);
        $b = $svc->verify($packet, ['allowed_files' => ['app/']]);
        $this->assertSame($a['verification_hash'], $b['verification_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['verification_hash']);
    }

    public function test_duplicate_violations_are_deduplicated(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([
            ['path' => 'config/secret.php'],
            ['path' => 'config/secret.php'],
        ]);
        $result = $svc->verify($packet, [
            'allowed_files' => ['app/'],
            'forbidden_files' => ['config/secret.php'],
        ]);
        $this->assertCount(1, $result['verification']['forbidden_violations']);
    }

    public function test_empty_path_entries_are_ignored(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([['path' => 'app/X.php']]);
        $packet['packet']['files'][] = ['path' => ''];
        $result = $svc->verify($packet, ['allowed_files' => ['app/']]);
        $this->assertSame(1, $result['verification']['in_scope_count']);
    }

    public function test_in_scope_paths_are_unique(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([
            ['path' => 'app/X.php'],
            ['path' => 'app/X.php'],
        ]);
        $result = $svc->verify($packet, ['allowed_files' => ['app/']]);
        $this->assertSame(1, $result['verification']['in_scope_count']);
    }

    // ── AC1/AC2: scope_clean / scope_violation / conflict_with_active_worker ──

    public function test_decision_scope_clean_when_no_violations(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([['path' => 'app/Services/Ai/SelfConstruction/Foo.php']]);
        $result = $svc->verify($packet, ['allowed_files' => ['app/Services/Ai/SelfConstruction/']]);

        $this->assertSame(AgentMergeReviewScopeVerifier::DECISION_SCOPE_CLEAN, $result['decision']);
    }

    public function test_decision_scope_violation_when_out_of_scope(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([['path' => 'app/Services/Ai/Other/Bar.php']]);
        $result = $svc->verify($packet, ['allowed_files' => ['app/Services/Ai/SelfConstruction/']]);

        $this->assertSame(AgentMergeReviewScopeVerifier::DECISION_SCOPE_VIOLATION, $result['decision']);
    }

    public function test_decision_conflict_with_active_worker_when_path_matches_other_lease(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([['path' => 'app/Services/Ai/SelfConstruction/Foo.php']]);
        $result = $svc->verify($packet, [
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'],
            'own_lease_id' => 'lease-mine',
            'active_lease_scopes' => [
                ['lease_id' => 'lease-other', 'write_set' => ['app/Services/Ai/SelfConstruction/Foo.php']],
            ],
        ]);

        $this->assertSame(AgentMergeReviewScopeVerifier::DECISION_CONFLICT_WITH_ACTIVE_WORKER, $result['decision']);
        $this->assertSame(1, $result['verification']['active_worker_conflict_count']);
        $this->assertSame('app/Services/Ai/SelfConstruction/Foo.php', $result['verification']['active_worker_conflicts'][0]['path']);
        $this->assertSame('lease-other', $result['verification']['active_worker_conflicts'][0]['lease_id']);
    }

    public function test_own_lease_id_excluded_from_conflict_detection(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([['path' => 'app/Services/Ai/SelfConstruction/Foo.php']]);
        $result = $svc->verify($packet, [
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'],
            'own_lease_id' => 'lease-mine',
            'active_lease_scopes' => [
                ['lease_id' => 'lease-mine', 'write_set' => ['app/Services/Ai/SelfConstruction/Foo.php']],
            ],
        ]);

        $this->assertSame([], $result['verification']['active_worker_conflicts']);
        $this->assertSame(AgentMergeReviewScopeVerifier::DECISION_SCOPE_CLEAN, $result['decision']);
    }

    public function test_non_overlapping_lease_does_not_conflict(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([['path' => 'app/Services/Ai/SelfConstruction/Foo.php']]);
        $result = $svc->verify($packet, [
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'],
            'active_lease_scopes' => [
                ['lease_id' => 'lease-other', 'write_set' => ['app/Services/Ai/SelfConstruction/Unrelated.php']],
            ],
        ]);

        $this->assertSame([], $result['verification']['active_worker_conflicts']);
        $this->assertSame(AgentMergeReviewScopeVerifier::DECISION_SCOPE_CLEAN, $result['decision']);
    }

    public function test_active_worker_conflict_takes_priority_over_scope_violation(): void
    {
        $svc = new AgentMergeReviewScopeVerifier;
        $packet = $this->packetWithFiles([
            ['path' => 'app/Services/Ai/Other/Bar.php'],
            ['path' => 'app/Services/Ai/SelfConstruction/Foo.php'],
        ]);
        $result = $svc->verify($packet, [
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'],
            'active_lease_scopes' => [
                ['lease_id' => 'lease-other', 'write_set' => ['app/Services/Ai/SelfConstruction/Foo.php']],
            ],
        ]);

        $this->assertSame(AgentMergeReviewScopeVerifier::DECISION_CONFLICT_WITH_ACTIVE_WORKER, $result['decision']);
        $this->assertSame('agent_merge_review_scope_violations_present', $result['status']);
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return array<string, mixed>
     */
    private function packetWithFiles(array $files): array
    {
        $diff = ['source' => 'synthetic_diff_manifest', 'files' => $files];

        return (new AgentMergeReviewPacketBuilder)->build($diff, [], ['packet_id' => 'p', 'claim_id' => 'c', 'task_packet_id' => 't', 'generated_at' => '2026-05-14T00:00:00+00:00']);
    }
}
