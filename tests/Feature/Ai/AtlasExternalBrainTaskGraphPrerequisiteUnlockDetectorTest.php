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

    // ── AC2: critical_chain_score and blocked_depth ──

    public function test_prerequisite_candidate_has_critical_chain_score_and_blocked_depth(): void
    {
        $result = (new AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector)->detect([
            'tasks' => [
                ['task_id' => 'prereq-1', 'impact_class' => 'low', 'status' => 'queued', 'unlocks' => ['dep-hi', 'dep-lo']],
                ['task_id' => 'dep-hi', 'impact_class' => 'high', 'depends_on' => ['prereq-1', 'missing-x'], 'status' => 'queued'],
                ['task_id' => 'dep-lo', 'impact_class' => 'low', 'depends_on' => ['prereq-1'], 'status' => 'queued'],
            ],
        ]);

        $candidate = collect($result['prerequisite_candidates'])->firstWhere('task_id', 'prereq-1');
        $this->assertNotNull($candidate);

        // critical_chain_score = high_impact_count * 10 + total_dependents = 1*10 + 2 = 12
        $this->assertSame(12, $candidate['critical_chain_score']);
        // blocked_depth = count of dependents that have unfinished blockers
        // dep-hi depends_on 'missing-x' which is not done → blocked
        // dep-lo depends_on 'prereq-1' only, which is queued → blocked (prereq-1 is queued, not done)
        // Actually wait, dep-lo's only blocker is prereq-1 which is queued → hasUnfinishedBlocker=true
        $this->assertSame(2, $candidate['blocked_depth']);
    }

    // ── AC3: few high-impact beats many low-impact ──

    public function test_few_high_impact_dependents_outranks_many_low_impact(): void
    {
        $result = (new AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector)->detect([
            'tasks' => [
                ['task_id' => 'precise', 'impact_class' => 'low', 'status' => 'queued', 'unlocks' => ['hi-1', 'hi-2']],
                ['task_id' => 'broad', 'impact_class' => 'low', 'status' => 'queued', 'unlocks' => ['lo-1', 'lo-2', 'lo-3']],
                ['task_id' => 'hi-1', 'impact_class' => 'high', 'depends_on' => ['precise'], 'status' => 'queued'],
                ['task_id' => 'hi-2', 'impact_class' => 'high', 'depends_on' => ['precise'], 'status' => 'queued'],
                ['task_id' => 'lo-1', 'impact_class' => 'low', 'depends_on' => ['broad'], 'status' => 'queued'],
                ['task_id' => 'lo-2', 'impact_class' => 'low', 'depends_on' => ['broad'], 'status' => 'queued'],
                ['task_id' => 'lo-3', 'impact_class' => 'low', 'depends_on' => ['broad'], 'status' => 'queued'],
            ],
        ]);

        $this->assertSame('precise', $result['prerequisite_candidates'][0]['task_id']);
        $this->assertSame('broad', $result['prerequisite_candidates'][1]['task_id']);
    }

    // ── AC4: collision_risk from overlapping allowed_files ──

    public function test_collision_risk_reported_for_overlapping_allowed_files(): void
    {
        $result = (new AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector)->detect([
            'tasks' => [
                ['task_id' => 'A', 'status' => 'queued', 'unlocks' => ['dep-a'], 'allowed_files' => ['app/Shared.php']],
                ['task_id' => 'dep-a', 'depends_on' => ['A'], 'status' => 'queued'],
                ['task_id' => 'B', 'status' => 'queued', 'unlocks' => ['dep-b'], 'allowed_files' => ['app/Shared.php']],
                ['task_id' => 'dep-b', 'depends_on' => ['B'], 'status' => 'queued'],
            ],
        ]);

        $candidateA = collect($result['prerequisite_candidates'])->firstWhere('task_id', 'A');
        $candidateB = collect($result['prerequisite_candidates'])->firstWhere('task_id', 'B');

        $this->assertNotNull($candidateA);
        $this->assertNotNull($candidateB);

        // A and B share 'app/Shared.php' → each lists the other as collision_risk
        $this->assertContains('B', $candidateA['collision_risk']);
        $this->assertContains('A', $candidateB['collision_risk']);
        $this->assertSame(1, $candidateA['downstream_unlock_count']); // unlock value preserved
    }

    public function test_no_collision_risk_when_allowed_files_disjoint(): void
    {
        $result = (new AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector)->detect([
            'tasks' => [
                ['task_id' => 'A', 'status' => 'queued', 'unlocks' => ['dep-a'], 'allowed_files' => ['app/A.php']],
                ['task_id' => 'dep-a', 'depends_on' => ['A'], 'status' => 'queued'],
                ['task_id' => 'B', 'status' => 'queued', 'unlocks' => ['dep-b'], 'allowed_files' => ['app/B.php']],
                ['task_id' => 'dep-b', 'depends_on' => ['B'], 'status' => 'queued'],
            ],
        ]);

        $candidateA = collect($result['prerequisite_candidates'])->firstWhere('task_id', 'A');
        $this->assertSame([], $candidateA['collision_risk']);
    }
}
