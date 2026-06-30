<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector;
use Tests\TestCase;

final class AtlasExternalBrainTaskGraphPrerequisiteUnlockDetectorTest extends TestCase
{
    public function test_prerequisite_with_high_impact_dependents_ranks_ahead_of_leaf_tasks(): void
    {
        $result = (new AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector)->detect([
            'tasks' => [
                ['task_id' => 'leaf-1', 'impact_class' => 'high', 'status' => 'queued'],
                ['task_id' => 'prereq-1', 'impact_class' => 'low', 'status' => 'queued', 'unlocks' => ['dep-1', 'dep-2']],
                ['task_id' => 'dep-1', 'depends_on' => ['prereq-1'], 'impact_class' => 'high', 'status' => 'queued'],
                ['task_id' => 'dep-2', 'depends_on' => ['prereq-1'], 'impact_class' => 'high', 'status' => 'queued'],
            ],
        ]);

        $this->assertSame('prereq-1', $result['implementation_order_hints'][0]);
        $this->assertSame(2, $result['downstream_unlock_counts']['prereq-1']);
        $this->assertSame(0, $result['downstream_unlock_counts']['leaf-1'] ?? 0);

        $candidate = collect($result['prerequisite_candidates'])->firstWhere('task_id', 'prereq-1');
        $this->assertNotNull($candidate);
        $this->assertSame(2, $candidate['high_impact_dependent_count']);
        $this->assertEqualsCanonicalizing(['dep-1', 'dep-2'], $candidate['dependents']);
    }

    public function test_depends_on_alone_without_explicit_unlocks_still_forms_the_edge(): void
    {
        $result = (new AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector)->detect([
            'tasks' => [
                ['task_id' => 'A', 'status' => 'queued'],
                ['task_id' => 'B', 'depends_on' => ['A'], 'status' => 'queued'],
            ],
        ]);

        $this->assertSame(1, $result['downstream_unlock_counts']['A']);
        $this->assertNotEmpty($result['prerequisite_candidates']);
        $this->assertSame('A', $result['prerequisite_candidates'][0]['task_id']);
    }

    public function test_blocked_dependents_lists_unfinished_blockers_only(): void
    {
        $result = (new AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector)->detect([
            'tasks' => [
                ['task_id' => 'A', 'status' => 'done'],
                ['task_id' => 'B', 'status' => 'queued'],
                ['task_id' => 'C', 'depends_on' => ['A', 'B'], 'status' => 'queued'],
            ],
        ]);

        $blocked = collect($result['blocked_dependents'])->firstWhere('task_id', 'C');
        $this->assertNotNull($blocked);
        $this->assertSame(['B'], $blocked['blocked_by'], 'done prerequisite A must not appear as a blocker');
    }

    public function test_done_tasks_are_excluded_from_prerequisite_candidates_and_order_hints(): void
    {
        $result = (new AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector)->detect([
            'tasks' => [
                ['task_id' => 'done-prereq', 'status' => 'done', 'unlocks' => ['dep-1']],
                ['task_id' => 'dep-1', 'depends_on' => ['done-prereq'], 'status' => 'queued'],
            ],
        ]);

        $this->assertNotContains('done-prereq', array_column($result['prerequisite_candidates'], 'task_id'));
        $this->assertNotContains('done-prereq', $result['implementation_order_hints']);
        $this->assertContains('dep-1', $result['implementation_order_hints']);
    }

    public function test_leaf_task_with_no_dependents_lands_after_all_prerequisites(): void
    {
        $result = (new AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector)->detect([
            'tasks' => [
                ['task_id' => 'leaf', 'status' => 'queued'],
                ['task_id' => 'prereq', 'unlocks' => ['dep'], 'status' => 'queued'],
                ['task_id' => 'dep', 'depends_on' => ['prereq'], 'status' => 'queued'],
            ],
        ]);

        $hints = $result['implementation_order_hints'];
        $this->assertLessThan(array_search('leaf', $hints, true), array_search('prereq', $hints, true));
    }
}
