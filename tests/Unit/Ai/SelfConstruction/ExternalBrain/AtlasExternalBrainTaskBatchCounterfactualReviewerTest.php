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

        foreach (['schema', 'proposed_score', 'counterfactual_score', 'decision', 'evidence', 'risks'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
    }
}
