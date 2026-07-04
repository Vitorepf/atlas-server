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

    public function test_output_includes_new_ac1_fields(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;
        $r = $reviewer->review([
            ['objective' => 'X', 'type' => 'feature', 'leverage' => 'high'],
        ]);

        $this->assertArrayHasKey('chain_value_delta', $r);
        $this->assertArrayHasKey('opportunity_cost_breakdown', $r);
        $this->assertArrayHasKey('discarded_high_value_risks', $r);
        $this->assertArrayHasKey('recommended_batch_delta', $r);
    }

    public function test_chain_value_delta_is_positive_when_proposed_carries_more_downstream_unlock_value(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;
        $proposed = [
            ['objective' => 'A', 'type' => 'feature', 'leverage' => 'high', 'dependency_chain_unlock_value' => 2.0],
        ];
        $counterfactual = [
            ['objective' => 'B', 'type' => 'feature', 'leverage' => 'low'],
        ];

        $r = $reviewer->review($proposed, $counterfactual);

        $this->assertGreaterThan(0.0, $r['chain_value_delta']);
    }

    // ── new AC: worker_capacity_cost penalizes huge low-value batches ────────

    public function test_huge_batch_with_high_worker_capacity_cost_and_low_chain_value_is_split_or_replaced(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        $proposed = [
            ['objective' => 'Tweak A', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/A.php'], 'worker_capacity_cost' => 2.0],
            ['objective' => 'Tweak B', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/B.php'], 'worker_capacity_cost' => 2.0],
            ['objective' => 'Tweak C', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/C.php'], 'worker_capacity_cost' => 2.0],
            ['objective' => 'Tweak D', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/D.php'], 'worker_capacity_cost' => 2.0],
            ['objective' => 'Tweak E', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/E.php'], 'worker_capacity_cost' => 2.0],
            ['objective' => 'Tweak F', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/F.php'], 'worker_capacity_cost' => 2.0],
        ];
        $counterfactual = [
            ['objective' => 'Design context router', 'type' => 'architecture', 'leverage' => 'high', 'allowed_files' => ['app/Router.php'], 'worker_capacity_cost' => 0.5],
        ];

        $r = $reviewer->review($proposed, $counterfactual);

        $this->assertContains($r['decision'], [
            AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_REPLACE,
            AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_SPLIT,
        ]);
    }

    public function test_smaller_counterfactual_with_higher_dependency_chain_unlock_value_wins_over_worker_capacity_cost(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        $proposed = [
            ['objective' => 'Do busywork A', 'type' => 'bug_fix', 'leverage' => 'low', 'allowed_files' => ['app/A.php'], 'worker_capacity_cost' => 3.0],
            ['objective' => 'Do busywork B', 'type' => 'bug_fix', 'leverage' => 'low', 'allowed_files' => ['app/B.php'], 'worker_capacity_cost' => 3.0],
        ];
        $counterfactual = [
            ['objective' => 'Unblock the dependency chain', 'type' => 'architecture', 'leverage' => 'high', 'allowed_files' => ['app/Chain.php'], 'dependency_chain_unlock_value' => 3.0, 'worker_capacity_cost' => 0.0],
        ];

        $r = $reviewer->review($proposed, $counterfactual);

        $this->assertGreaterThan($r['proposed_score'], $r['counterfactual_score']);
        $this->assertSame(AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_REPLACE, $r['decision']);
    }

    public function test_low_worker_capacity_cost_high_leverage_batch_still_returns_keep(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        $batch = [
            ['objective' => 'Design core arch', 'type' => 'architecture', 'leverage' => 'high', 'allowed_files' => ['app/Arch.php'], 'worker_capacity_cost' => 0.2],
            ['objective' => 'Implement evidence', 'type' => 'evolution', 'leverage' => 'high', 'allowed_files' => ['app/Evid.php'], 'worker_capacity_cost' => 0.2],
        ];

        $r = $reviewer->review($batch, []);

        $this->assertSame(AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_KEEP, $r['decision']);
    }

    // ── AC4: review output includes selected_batch and rejection_reasons ─────

    public function test_output_has_selected_batch_and_rejection_reasons_keys(): void
    {
        $r = (new AtlasExternalBrainTaskBatchCounterfactualReviewer)->review([], []);

        $this->assertArrayHasKey('selected_batch', $r);
        $this->assertArrayHasKey('rejection_reasons', $r);
    }

    public function test_replace_decision_selects_counterfactual_and_names_proposed_rejection_reasons(): void
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

        $this->assertSame(AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_REPLACE, $r['decision']);
        $this->assertSame('counterfactual', $r['selected_batch']);
        $this->assertNotEmpty($r['rejection_reasons']);
        foreach ($r['rejection_reasons'] as $reason) {
            $this->assertStringStartsWith('proposed_rejected:', $reason);
        }
    }

    public function test_keep_decision_selects_proposed_with_empty_rejection_reasons_when_no_counterfactual(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        $batch = [
            ['objective' => 'Design core arch', 'type' => 'architecture', 'leverage' => 'high', 'allowed_files' => ['app/Arch.php']],
            ['objective' => 'Implement evidence', 'type' => 'evolution', 'leverage' => 'high', 'allowed_files' => ['app/Evid.php']],
        ];

        $r = $reviewer->review($batch, []);

        $this->assertSame(AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_KEEP, $r['decision']);
        $this->assertSame('proposed', $r['selected_batch']);
        $this->assertSame([], $r['rejection_reasons']);
    }

    public function test_keep_decision_with_weaker_counterfactual_names_counterfactual_rejection_reasons(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        $proposed = [
            ['objective' => 'Unblock context router contract', 'type' => 'architecture', 'leverage' => 'high', 'allowed_files' => ['app/Contract.php'], 'dependency_chain_unlock_value' => 2.5],
            ['objective' => 'Wire downstream consumers', 'type' => 'evolution', 'leverage' => 'high', 'allowed_files' => ['app/Consumer.php'], 'dependency_chain_unlock_value' => 2.0],
        ];
        $counterfactual = [
            ['objective' => 'Tweak A', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/A.php']],
            ['objective' => 'Tweak B', 'type' => 'template', 'leverage' => 'low', 'allowed_files' => ['app/B.php']],
        ];

        $r = $reviewer->review($proposed, $counterfactual);

        $this->assertSame(AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_KEEP, $r['decision']);
        $this->assertSame('proposed', $r['selected_batch']);
        $this->assertNotEmpty($r['rejection_reasons']);
        foreach ($r['rejection_reasons'] as $reason) {
            $this->assertStringStartsWith('counterfactual_rejected:', $reason);
        }
    }

    public function test_shrink_decision_selects_proposed(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        $batch = [
            ['objective' => 'Task A', 'type' => 'bug_fix', 'leverage' => 'high', 'allowed_files' => ['app/X.php']],
            ['objective' => 'Task A', 'type' => 'bug_fix', 'leverage' => 'high', 'allowed_files' => ['app/X.php']],
            ['objective' => 'Task A', 'type' => 'bug_fix', 'leverage' => 'high', 'allowed_files' => ['app/X.php']],
        ];

        $r = $reviewer->review($batch, []);

        $this->assertSame(AtlasExternalBrainTaskBatchCounterfactualReviewer::DECISION_SHRINK, $r['decision']);
        $this->assertSame('proposed', $r['selected_batch']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC4: opportunity_cost_breakdown + recommended_batch_delta
    // ═══════════════════════════════════════════════════════════════════════

    public function test_opportunity_cost_breakdown_has_expected_fields(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;
        $r = $reviewer->review([], []);

        $this->assertArrayHasKey('opportunity_cost_breakdown', $r);
        $breakdown = $r['opportunity_cost_breakdown'];
        $this->assertArrayHasKey('score_gap', $breakdown);
        $this->assertArrayHasKey('chain_value_gap', $breakdown);
        $this->assertArrayHasKey('high_leverage_gap', $breakdown);
    }

    public function test_recommended_batch_delta_present_on_all_decisions(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;

        // keep
        $keep = $reviewer->review([['objective' => 'X', 'type' => 'feature', 'leverage' => 'high']]);
        $this->assertArrayHasKey('recommended_batch_delta', $keep);

        // shrink
        $shrink = $reviewer->review([
            ['objective' => 'A', 'type' => 'bug_fix', 'leverage' => 'high', 'allowed_files' => ['app/X.php']],
            ['objective' => 'A', 'type' => 'bug_fix', 'leverage' => 'high', 'allowed_files' => ['app/X.php']],
            ['objective' => 'A', 'type' => 'bug_fix', 'leverage' => 'high', 'allowed_files' => ['app/X.php']],
        ]);
        $this->assertArrayHasKey('recommended_batch_delta', $shrink);
        $this->assertNotEmpty($shrink['recommended_batch_delta']);

        // replace
        $replace = $reviewer->review(
            [['objective' => 'Low value A', 'type' => 'template', 'leverage' => 'low']],
            [['objective' => 'High value B', 'type' => 'architecture', 'leverage' => 'high']],
        );
        $this->assertArrayHasKey('recommended_batch_delta', $replace);
        $this->assertContains('adopt_counterfactual_batch_instead', $replace['recommended_batch_delta']);
    }

    public function test_opportunity_cost_breakdown_reflects_score_gap(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;
        $proposed = [['objective' => 'Low A', 'type' => 'template', 'leverage' => 'low']];
        $counter  = [['objective' => 'High B', 'type' => 'architecture', 'leverage' => 'high']];

        $r = $reviewer->review($proposed, $counter);

        // counterfactual should score higher → score_gap > 0
        $this->assertGreaterThan(0.0, $r['opportunity_cost_breakdown']['score_gap']);
    }

    public function test_opportunity_cost_breakdown_zero_when_batches_equal(): void
    {
        $reviewer = new AtlasExternalBrainTaskBatchCounterfactualReviewer;
        $r = $reviewer->review([], []);

        $this->assertSame(0.0, $r['opportunity_cost_breakdown']['score_gap']);
        $this->assertSame(0.0, $r['opportunity_cost_breakdown']['chain_value_gap']);
        $this->assertSame(0, $r['opportunity_cost_breakdown']['high_leverage_gap']);
    }
}
