<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\KnowledgeSync;

use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncRequiredArtifactMap;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasKnowledgeSyncRequiredArtifactMap: docs change ⇒ docs-health-check + engineering-knowledge-sync;
 * impl PHP change ⇒ code-intelligence-index; mixed change ⇒ all three; project_lane attached ⇒
 * project-lane-context-freshness; identical input ⇒ deterministic artifact ordering.
 */
final class AtlasKnowledgeSyncRequiredArtifactMapTest extends TestCase
{
    public function test_docs_only_change_requires_docs_health_and_knowledge_sync(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive([
            'changed_files' => ['docs/architecture.md'],
        ]);
        $ids = array_column($r['required_artifacts'], 'artifact_id');
        $this->assertContains('docs-health-check', $ids);
        $this->assertContains('engineering-knowledge-sync', $ids);
        $this->assertNotContains('code-intelligence-index', $ids);
    }

    public function test_code_only_change_requires_code_intelligence_index(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive([
            'changed_files' => ['app/Demo/Foo.php'],
        ]);
        $ids = array_column($r['required_artifacts'], 'artifact_id');
        $this->assertContains('code-intelligence-index', $ids);
        $this->assertNotContains('docs-health-check', $ids);
        $this->assertNotContains('engineering-knowledge-sync', $ids);
    }

    public function test_mixed_change_requires_docs_sync_and_code_artifacts(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive([
            'changed_files' => ['app/Demo/Foo.php', 'docs/architecture.md'],
        ]);
        $ids = array_column($r['required_artifacts'], 'artifact_id');
        $this->assertContains('docs-health-check', $ids);
        $this->assertContains('engineering-knowledge-sync', $ids);
        $this->assertContains('code-intelligence-index', $ids);
    }

    public function test_project_lane_attached_requires_lane_freshness(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive([
            'changed_files' => ['app/Foo.php'],
            'project_lane' => ['project_id' => 'demo-lane'],
        ]);
        $ids = array_column($r['required_artifacts'], 'artifact_id');
        $this->assertContains('project-lane-context-freshness:demo-lane', $ids);
    }

    public function test_release_notes_artifact_emitted_when_required(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive([
            'changed_files' => ['app/Foo.php'],
            'release_candidate' => ['requires_release_notes' => true],
        ]);
        $ids = array_column($r['required_artifacts'], 'artifact_id');
        $this->assertContains('release-notes-update', $ids);
    }

    public function test_artifacts_are_sorted_byte_stably_by_artifact_id(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive([
            'changed_files' => ['app/Foo.php', 'docs/x.md'],
            'project_lane' => ['project_id' => 'zeta-lane'],
            'release_candidate' => ['requires_release_notes' => true],
        ]);
        $ids = array_column($r['required_artifacts'], 'artifact_id');
        $copy = $ids;
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $ids);
    }

    public function test_no_changes_returns_empty_artifact_list(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive(['changed_files' => []]);
        $this->assertSame([], $r['required_artifacts']);
    }

    public function test_completion_and_promotion_events_require_all_five_baseline_artifacts(): void
    {
        $svc = new AtlasKnowledgeSyncRequiredArtifactMap;
        foreach (['completion', 'promotion'] as $event) {
            $r = $svc->derive(['changed_files' => [], 'event_type' => $event]);
            $ids = array_column($r['required_artifacts'], 'artifact_id');
            foreach (['code_index', 'docs', 'memory', 'receipt_chain', 'tests_or_gates'] as $required) {
                $this->assertContains($required, $ids, "{$event} must require {$required}");
            }
            $categories = array_column($r['required_artifacts'], 'category');
            foreach ($categories as $cat) {
                $this->assertSame(AtlasKnowledgeSyncRequiredArtifactMap::CATEGORY_ATLAS_NATIVE, $cat, 'baseline artifacts must all be atlas_native');
            }
        }
    }

    public function test_stale_or_missing_artifacts_return_blocked_with_named_missing(): void
    {
        $svc = new AtlasKnowledgeSyncRequiredArtifactMap;
        $r = $svc->derive(['changed_files' => [], 'event_type' => 'completion']);
        $artifacts = $r['required_artifacts'];

        // No fresh artifacts → all blocked
        $check = $svc->checkFreshness($artifacts, []);
        $this->assertTrue($check['blocked']);
        $this->assertNotEmpty($check['missing_artifacts']);

        // All fresh → not blocked
        $allIds = array_column($artifacts, 'artifact_id');
        $check2 = $svc->checkFreshness($artifacts, $allIds);
        $this->assertFalse($check2['blocked']);
        $this->assertSame([], $check2['missing_artifacts']);

        // Partial: only memory fresh → still blocked, missing the rest
        $check3 = $svc->checkFreshness($artifacts, ['memory']);
        $this->assertTrue($check3['blocked']);
        $this->assertContains('code_index', $check3['missing_artifacts']);
        $this->assertNotContains('memory', $check3['missing_artifacts']);
    }

    public function test_operator_visibility_artifacts_do_not_satisfy_atlas_native_finality(): void
    {
        $svc = new AtlasKnowledgeSyncRequiredArtifactMap;
        $r = $svc->derive([
            'changed_files' => ['app/Foo.php'],
            'event_type' => 'completion',
            'release_candidate' => ['requires_release_notes' => true],
        ]);

        $byId = array_column($r['required_artifacts'], 'category', 'artifact_id');
        $this->assertSame(AtlasKnowledgeSyncRequiredArtifactMap::CATEGORY_OPERATOR_VISIBILITY, $byId['release-notes-update']);
        $this->assertSame(AtlasKnowledgeSyncRequiredArtifactMap::CATEGORY_ATLAS_NATIVE, $byId['code_index']);

        // Providing only release-notes-update as fresh still leaves finality blocked
        $check = $svc->checkFreshness($r['required_artifacts'], ['release-notes-update']);
        $this->assertTrue($check['blocked'], 'operator_visibility artifact must not satisfy atlas_native finality gate');
        $this->assertContains('code_index', $check['missing_artifacts']);
    }

    public function test_artifact_map_hash_is_deterministic_across_input_key_order(): void
    {
        $svc = new AtlasKnowledgeSyncRequiredArtifactMap;
        $factsA = ['changed_files' => ['app/Foo.php', 'docs/bar.md'], 'event_type' => 'completion'];
        $factsB = ['event_type' => 'completion', 'changed_files' => ['docs/bar.md', 'app/Foo.php']];

        $rA = $svc->derive($factsA);
        $rB = $svc->derive($factsB);

        $this->assertSame(64, strlen($rA['map_hash']));
        $this->assertSame($rA['map_hash'], $rB['map_hash']);
    }

    // ── post_merge event ──────────────────────────────────────────────────────

    public function test_post_merge_includes_full_finality_baseline(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive(['changed_files' => [], 'event_type' => 'post_merge']);
        $ids = array_column($r['required_artifacts'], 'artifact_id');

        foreach (['code_index', 'docs', 'memory', 'receipt_chain', 'tests_or_gates'] as $required) {
            $this->assertContains($required, $ids, "post_merge must include finality baseline: {$required}");
        }
    }

    public function test_post_merge_also_emits_post_merge_receipt(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive(['changed_files' => [], 'event_type' => 'post_merge']);
        $this->assertContains('post_merge_receipt', array_column($r['required_artifacts'], 'artifact_id'));
    }

    public function test_post_merge_receipt_is_atlas_native(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive(['changed_files' => [], 'event_type' => 'post_merge']);
        $byId = array_column($r['required_artifacts'], 'category', 'artifact_id');
        $this->assertSame(AtlasKnowledgeSyncRequiredArtifactMap::CATEGORY_ATLAS_NATIVE, $byId['post_merge_receipt']);
    }

    public function test_check_freshness_blocks_when_post_merge_receipt_missing(): void
    {
        $svc = new AtlasKnowledgeSyncRequiredArtifactMap;
        $r = $svc->derive(['changed_files' => [], 'event_type' => 'post_merge']);
        $withoutReceipt = array_values(array_filter(
            array_column($r['required_artifacts'], 'artifact_id'),
            static fn (string $id): bool => $id !== 'post_merge_receipt'
        ));

        $check = $svc->checkFreshness($r['required_artifacts'], $withoutReceipt);
        $this->assertTrue($check['blocked']);
        $this->assertContains('post_merge_receipt', $check['missing_artifacts']);
    }

    public function test_operator_visibility_artifact_does_not_substitute_for_post_merge_receipt(): void
    {
        $svc = new AtlasKnowledgeSyncRequiredArtifactMap;
        $r = $svc->derive([
            'changed_files' => ['app/Foo.php'],
            'event_type' => 'post_merge',
            'release_candidate' => ['requires_release_notes' => true],
        ]);

        $check = $svc->checkFreshness($r['required_artifacts'], ['release-notes-update']);
        $this->assertTrue($check['blocked']);
        $this->assertContains('post_merge_receipt', $check['missing_artifacts']);
    }

    public function test_post_merge_artifact_list_is_sorted_deterministically(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive(['changed_files' => [], 'event_type' => 'post_merge']);
        $ids = array_column($r['required_artifacts'], 'artifact_id');
        $copy = $ids;
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $ids);
    }

    // ── AC1: capability_change_type, affected_docs, memory_need, code_index_need, project_lane ──

    public function test_architecture_change_requires_docs_code_index_and_memory(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive([
            'changed_files' => [],
            'capability_change_type' => 'architecture',
        ]);
        $ids = array_column($r['required_artifacts'], 'artifact_id');

        $this->assertContains('docs-health-check', $ids);
        $this->assertContains('engineering-knowledge-sync', $ids);
        $this->assertContains('code-intelligence-index', $ids);
        $this->assertContains('memory-update', $ids);
    }

    public function test_prompt_contract_change_requires_docs_and_memory_but_not_code_index(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive([
            'changed_files' => [],
            'capability_change_type' => 'prompt_contract',
        ]);
        $ids = array_column($r['required_artifacts'], 'artifact_id');

        $this->assertContains('docs-health-check', $ids);
        $this->assertContains('memory-update', $ids);
        $this->assertNotContains('code-intelligence-index', $ids);
    }

    public function test_refactor_change_requires_only_code_index(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive([
            'changed_files' => [],
            'capability_change_type' => 'refactor',
        ]);
        $ids = array_column($r['required_artifacts'], 'artifact_id');

        $this->assertContains('code-intelligence-index', $ids);
        $this->assertNotContains('docs-health-check', $ids);
        $this->assertNotContains('memory-update', $ids);
    }

    public function test_noop_change_type_requires_nothing_extra(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive([
            'changed_files' => [],
            'capability_change_type' => 'noop',
        ]);

        $this->assertSame([], $r['required_artifacts']);
    }

    public function test_affected_docs_without_changed_files_still_requires_docs_artifacts(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive([
            'changed_files' => [],
            'affected_docs' => ['docs/engineering-knowledge-base/foo.md'],
        ]);
        $ids = array_column($r['required_artifacts'], 'artifact_id');

        $this->assertContains('docs-health-check', $ids);
        $this->assertContains('engineering-knowledge-sync', $ids);
    }

    public function test_memory_need_flag_adds_memory_update_artifact(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive([
            'changed_files' => [],
            'memory_need' => true,
        ]);

        $this->assertContains('memory-update', array_column($r['required_artifacts'], 'artifact_id'));
    }

    public function test_code_index_need_flag_adds_code_intelligence_index_artifact(): void
    {
        $r = (new AtlasKnowledgeSyncRequiredArtifactMap)->derive([
            'changed_files' => [],
            'code_index_need' => true,
        ]);

        $this->assertContains('code-intelligence-index', array_column($r['required_artifacts'], 'artifact_id'));
    }

    // ── AC2: freshness checks report missing AND stale artifact ids with command hints ──

    public function test_check_freshness_reports_stale_artifacts_distinct_from_missing(): void
    {
        $svc = new AtlasKnowledgeSyncRequiredArtifactMap;
        $r = $svc->derive(['changed_files' => [], 'event_type' => 'completion']);
        $allIds = array_column($r['required_artifacts'], 'artifact_id');

        $check = $svc->checkFreshness($r['required_artifacts'], $allIds, ['memory']);

        $this->assertTrue($check['blocked']);
        $this->assertContains('memory', $check['stale_artifacts']);
        $this->assertNotContains('memory', $check['missing_artifacts']);
        $this->assertNotEmpty($check['command_hints']);
    }

    public function test_check_freshness_command_hints_cover_missing_and_stale(): void
    {
        $svc = new AtlasKnowledgeSyncRequiredArtifactMap;
        $r = $svc->derive(['changed_files' => [], 'event_type' => 'completion']);

        $check = $svc->checkFreshness($r['required_artifacts'], ['memory'], []);

        $this->assertNotEmpty($check['command_hints']);
        $this->assertContains('code_index', $check['missing_artifacts']);
    }

    public function test_no_stale_ids_yields_empty_stale_artifacts(): void
    {
        $svc = new AtlasKnowledgeSyncRequiredArtifactMap;
        $r = $svc->derive(['changed_files' => [], 'event_type' => 'completion']);
        $allIds = array_column($r['required_artifacts'], 'artifact_id');

        $check = $svc->checkFreshness($r['required_artifacts'], $allIds);

        $this->assertFalse($check['blocked']);
        $this->assertSame([], $check['stale_artifacts']);
    }
}
