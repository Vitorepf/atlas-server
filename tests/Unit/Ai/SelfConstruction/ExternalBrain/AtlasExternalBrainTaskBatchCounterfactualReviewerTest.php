<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskBatchCounterfactualReviewer;
use Tests\TestCase;

final class AtlasExternalBrainTaskBatchCounterfactualReviewerTest extends TestCase
{
    // ── AC1: high-leverage diverse beats larger template batch ───────────────

    public function test_high_leverage_diverse_batch_beats_larger_template_batch(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        $proposed = [
            ['objective' => 'Add CRUD A', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/A.php']],
            ['objective' => 'Add CRUD B', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/B.php']],
            ['objective' => 'Add CRUD C', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/C.php']],
            ['objective' => 'Add CRUD D', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/D.php']],
        ];
        $counterfactual = [
            ['objective' => 'Design compounding memory', 'type' => 'architecture', 'leverage' => 'high', 'allowed_files' => ['app/Mem.php']],
            ['objective' => 'Implement evidence ledger', 'type' => 'evolution', 'leverage' => 'high', 'allowed_files' => ['app/Ledger.php']],
        ];

        $r = $reviewer->review($proposed, $counterfactual);

        $this->assertGreaterThan($r['proposed_score'], $r['counterfactual_score']);
        $this->assertSame(AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_REPLACE, $r['decision']);
    }

    // ── AC2: keep/shrink/replace/split with evidence ────────────────────────

    public function test_strong_batch_recommended_keep(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        $batch = [
            ['objective' => 'Design core arch', 'type' => 'architecture', 'leverage' => 'high', 'allowed_files' => ['app/Arch.php']],
            ['objective' => 'Implement evidence', 'type' => 'evolution', 'leverage' => 'high', 'allowed_files' => ['app/Evid.php']],
        ];

        $r = $reviewer->review($batch, []);

        $this->assertSame(AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_KEEP, $r['decision']);
        $this->assertNotEmpty($r['evidence']);
    }

    public function test_duplicate_heavy_batch_recommended_shrink(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        $batch = [
            ['objective' => 'Task A', 'type' => 'bug_fix', 'leverage' => 'high', 'allowed_files' => ['app/X.php']],
            ['objective' => 'Task A', 'type' => 'bug_fix', 'leverage' => 'high', 'allowed_files' => ['app/X.php']],
            ['objective' => 'Task A', 'type' => 'bug_fix', 'leverage' => 'high', 'allowed_files' => ['app/X.php']],
        ];

        $r = $reviewer->review($batch, []);

        $this->assertSame(AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_SHRINK, $r['decision']);
    }

    // ── AC3: duplicate risk lowers score ─────────────────────────────────────

    public function test_duplicate_allowed_files_increase_risk(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        $batch = [
            ['objective' => 'Task A', 'type' => 'bug_fix', 'leverage' => 'high', 'allowed_files' => ['app/X.php']],
            ['objective' => 'Task B', 'type' => 'bug_fix', 'leverage' => 'high', 'allowed_files' => ['app/X.php']],
        ];

        $r = $reviewer->review($batch, []);

        $this->assertContains('duplicate_allowed_files', $r['risks']);
    }

    // ── AC4: smaller counterfactual can win ──────────────────────────────────

    public function test_smaller_counterfactual_with_higher_value_wins(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        $proposed = [
            ['objective' => 'Fix typo', 'type' => 'bug_fix', 'leverage' => 'low', 'allowed_files' => ['app/A.php']],
            ['objective' => 'Fix lint', 'type' => 'bug_fix', 'leverage' => 'low', 'allowed_files' => ['app/B.php']],
            ['objective' => 'Fix CS', 'type' => 'bug_fix', 'leverage' => 'low', 'allowed_files' => ['app/C.php']],
        ];
        $counterfactual = [
            ['objective' => 'Redesign event pipeline', 'type' => 'architecture', 'leverage' => 'high', 'allowed_files' => ['app/Pipe.php']],
        ];

        $r = $reviewer->review($proposed, $counterfactual);

        $this->assertGreaterThan($r['proposed_score'], $r['counterfactual_score']);
        $this->assertSame(AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_REPLACE, $r['decision']);
    }

    // ── output shape ─────────────────────────────────────────────────────────

    public function test_output_has_all_required_fields(): void
    {
        $r = (new AtlasExternalBrainTaskBatchCounterfactualReviewer)->review([], []);

        foreach (['schema', 'proposed_score', 'counterfactual_score', 'decision', 'evidence', 'risks', 'opportunity_cost', 'recommended_batch_delta'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
    }

    // ── new AC: smaller, safer, more diverse counterfactual forces replace/shrink ──

    public function test_smaller_safer_more_diverse_counterfactual_forces_replace_over_larger_proposed(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        // Proposed: more tasks (5), but duplicated targets and uniform low diversity.
        $proposed = [
            ['objective' => 'Task A', 'type' => 'bug_fix', 'leverage' => 'low', 'allowed_files' => ['app/X.php'], 'give_back_risk' => 0.5],
            ['objective' => 'Task B', 'type' => 'bug_fix', 'leverage' => 'low', 'allowed_files' => ['app/X.php'], 'give_back_risk' => 0.5],
            ['objective' => 'Task C', 'type' => 'bug_fix', 'leverage' => 'low', 'allowed_files' => ['app/X.php'], 'give_back_risk' => 0.5],
            ['objective' => 'Task D', 'type' => 'bug_fix', 'leverage' => 'low', 'allowed_files' => ['app/X.php'], 'give_back_risk' => 0.5],
            ['objective' => 'Task E', 'type' => 'bug_fix', 'leverage' => 'low', 'allowed_files' => ['app/X.php'], 'give_back_risk' => 0.5],
        ];
        // Counterfactual: only 2 tasks, but diverse, low give_back risk, high leverage.
        $counterfactual = [
            ['objective' => 'Design context router', 'type' => 'architecture', 'leverage' => 'high', 'allowed_files' => ['app/Router.php'], 'give_back_risk' => 0.0],
            ['objective' => 'Harden evidence ledger', 'type' => 'evolution', 'leverage' => 'high', 'allowed_files' => ['app/Ledger.php'], 'give_back_risk' => 0.0],
        ];

        $r = $reviewer->review($proposed, $counterfactual);

        $this->assertContains($r['decision'], [
            AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_REPLACE,
            AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_SHRINK,
        ]);
        $this->assertGreaterThan(0, count($proposed));
        $this->assertLessThan(count($proposed), count($counterfactual));
        $this->assertNotEmpty($r['recommended_batch_delta']);
    }

    // ── new AC: dependency-chain batch with high unlock value kept despite fewer tasks ──

    public function test_dependency_chain_batch_with_high_unlock_value_is_kept_despite_fewer_tasks(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        // Proposed: 2 tasks but unlocks a large amount of downstream work.
        $proposed = [
            ['objective' => 'Unblock context router contract', 'type' => 'architecture', 'leverage' => 'high', 'allowed_files' => ['app/Contract.php'], 'dependency_chain_unlock_value' => 2.5],
            ['objective' => 'Wire downstream consumers', 'type' => 'evolution', 'leverage' => 'high', 'allowed_files' => ['app/Consumer.php'], 'dependency_chain_unlock_value' => 2.0],
        ];
        // Counterfactual: 4 tasks, more of them, but shallow/no chain value.
        $counterfactual = [
            ['objective' => 'Tweak A', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/A.php']],
            ['objective' => 'Tweak B', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/B.php']],
            ['objective' => 'Tweak C', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/C.php']],
            ['objective' => 'Tweak D', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/D.php']],
        ];

        $r = $reviewer->review($proposed, $counterfactual);

        $this->assertSame(AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_KEEP, $r['decision']);
        $this->assertGreaterThan(count($proposed), count($counterfactual));
        $this->assertGreaterThanOrEqual($r['counterfactual_score'], $r['proposed_score']);
    }
}
