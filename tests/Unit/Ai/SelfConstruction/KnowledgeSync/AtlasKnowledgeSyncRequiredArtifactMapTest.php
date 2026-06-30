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
}
