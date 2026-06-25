<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\KnowledgeSync;

use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncPostMergePlan;
use Tests\TestCase;

final class AtlasKnowledgeSyncPostMergePlanTest extends TestCase
{
    private function artifactMap(array $overrides = []): array
    {
        return $overrides + [
            'project_ids' => ['atlas-server'],
            'docs_dirs' => ['docs/engineering-knowledge-base'],
            'code_index_targets' => ['app/Services/Ai'],
        ];
    }

    private function docsOk(): array
    {
        return ['conformant' => true, 'blockers' => []];
    }

    private function codeReady(): array
    {
        return ['ready' => true, 'blockers' => [], 'bypassed_docs_only' => false];
    }

    private function certifiedCandidate(): array
    {
        return ['certified' => true, 'candidate_id' => 'cand-42', 'evidence_refs' => ['mutop:k']];
    }

    public function test_full_plan_emits_docs_index_context_and_learning_actions(): void
    {
        $plan = (new AtlasKnowledgeSyncPostMergePlan)->plan(
            $this->artifactMap(),
            $this->docsOk(),
            $this->codeReady(),
            $this->certifiedCandidate(),
        );

        $this->assertFalse($plan['blocked']);
        $actions = array_column($plan['actions'], 'action');
        $this->assertContains(AtlasKnowledgeSyncPostMergePlan::ACTION_SYNC_DOCS, $actions);
        $this->assertContains(AtlasKnowledgeSyncPostMergePlan::ACTION_INDEX_CODE, $actions);
        $this->assertContains(AtlasKnowledgeSyncPostMergePlan::ACTION_REFRESH_CONTEXT_PACK, $actions);
        $this->assertContains(AtlasKnowledgeSyncPostMergePlan::ACTION_RECORD_LEARNING_CANDIDATE, $actions);
        $this->assertNotEmpty($plan['command_hints']);
    }

    public function test_empty_artifact_map_yields_no_action_plan(): void
    {
        $plan = (new AtlasKnowledgeSyncPostMergePlan)->plan(
            ['project_ids' => [], 'docs_dirs' => [], 'code_index_targets' => []],
            $this->docsOk(),
            $this->codeReady(),
            [],
        );

        $this->assertFalse($plan['blocked']);
        $this->assertSame([['action' => AtlasKnowledgeSyncPostMergePlan::ACTION_NO_OP]], $plan['actions']);
    }

    public function test_blocked_docs_drift_yields_blocked_plan_with_no_release_complete(): void
    {
        $plan = (new AtlasKnowledgeSyncPostMergePlan)->plan(
            $this->artifactMap(),
            ['conformant' => false, 'blockers' => ['readme_stale']],
            $this->codeReady(),
        );

        $this->assertTrue($plan['blocked']);
        $this->assertSame([], $plan['actions'], 'a blocked plan emits NO actions');
        $this->assertContains('docs_drift_not_conformant', $plan['blockers']);
        $this->assertContains('docs_drift:readme_stale', $plan['blockers']);
    }

    public function test_blocked_code_index_yields_blocked_plan(): void
    {
        $plan = (new AtlasKnowledgeSyncPostMergePlan)->plan(
            $this->artifactMap(),
            $this->docsOk(),
            ['ready' => false, 'blockers' => ['index_stale']],
        );

        $this->assertTrue($plan['blocked']);
        $this->assertContains('code_index_not_ready', $plan['blockers']);
        $this->assertContains('code_index:index_stale', $plan['blockers']);
    }

    public function test_multi_project_emits_one_context_refresh_per_project_in_order(): void
    {
        $plan = (new AtlasKnowledgeSyncPostMergePlan)->plan(
            $this->artifactMap(['project_ids' => ['atlas-server', 'atlas-desktop', 'atlas-app']]),
            $this->docsOk(),
            $this->codeReady(),
        );

        $contextActions = array_filter($plan['actions'], static fn (array $a): bool => $a['action'] === AtlasKnowledgeSyncPostMergePlan::ACTION_REFRESH_CONTEXT_PACK);
        $this->assertCount(3, $contextActions);
        $this->assertSame(['atlas-server', 'atlas-desktop', 'atlas-app'], array_values(array_column($contextActions, 'project_id')));
    }

    public function test_docs_only_bypassed_code_index_skips_index_action(): void
    {
        $plan = (new AtlasKnowledgeSyncPostMergePlan)->plan(
            $this->artifactMap(),
            $this->docsOk(),
            ['ready' => true, 'blockers' => [], 'bypassed_docs_only' => true],
            $this->certifiedCandidate(),
        );

        $actions = array_column($plan['actions'], 'action');
        $this->assertContains(AtlasKnowledgeSyncPostMergePlan::ACTION_SYNC_DOCS, $actions);
        $this->assertNotContains(AtlasKnowledgeSyncPostMergePlan::ACTION_INDEX_CODE, $actions);
    }

    public function test_planner_emits_command_hints_but_never_executes(): void
    {
        $plan = (new AtlasKnowledgeSyncPostMergePlan)->plan(
            $this->artifactMap(),
            $this->docsOk(),
            $this->codeReady(),
        );

        $this->assertIsArray($plan['command_hints']);
        foreach ($plan['command_hints'] as $hint) {
            $this->assertIsString($hint);
        }
    }
}
