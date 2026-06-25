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
}
