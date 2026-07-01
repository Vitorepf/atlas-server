<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRoadmapCoverageGapGovernor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainRoadmapCoverageGapGovernorTest extends TestCase
{
    private function governor(): AtlasExternalBrainRoadmapCoverageGapGovernor
    {
        return new AtlasExternalBrainRoadmapCoverageGapGovernor;
    }

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $result = $this->governor()->govern([]);

        foreach (['schema_version', 'coverage_by_gap', 'overcovered_gaps', 'undercovered_high_priority_gaps',
                  'next_batch_should_target', 'candidate_batch_decisions', 'gap_candidates', 'stale_roadmap_gaps'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainRoadmapCoverageGapGovernor::SCHEMA, $result['schema_version']);
    }

    // ── AC2: implemented + queued capabilities satisfy coverage, no duplicates ──

    public function test_completed_capability_counts_toward_coverage_without_creating_duplicate_task(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-a', 'priority' => 'high', 'target_coverage' => 2]],
            'completed_capabilities' => [['gap_id' => 'gap-a'], ['gap_id' => 'gap-a']],
        ]);

        $this->assertContains('gap-a', $result['overcovered_gaps']);
        $this->assertNotContains('gap-a', $result['undercovered_high_priority_gaps']);
        $this->assertSame([], $result['gap_candidates']);
    }

    public function test_queued_plus_completed_together_satisfy_coverage(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-b', 'priority' => 'high', 'target_coverage' => 2]],
            'queued_tasks' => [['gap_id' => 'gap-b']],
            'completed_capabilities' => [['gap_id' => 'gap-b']],
        ]);

        $this->assertContains('gap-b', $result['overcovered_gaps']);
    }

    public function test_candidate_batch_for_already_covered_gap_is_blocked_not_duplicated(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-c', 'priority' => 'high', 'target_coverage' => 1]],
            'completed_capabilities' => [['gap_id' => 'gap-c']],
            'candidate_batches' => [['gap_id' => 'gap-c']],
        ]);

        $this->assertSame('blocked', $result['candidate_batch_decisions'][0]['decision']);
        $this->assertSame('gap_already_overcovered', $result['candidate_batch_decisions'][0]['reason']);
    }

    // ── AC3: missing high-leverage areas emit gap_candidates with reason + task family ──

    public function test_undercovered_high_priority_gap_emits_gap_candidate_with_reason_and_suggested_task_family(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [[
                'gap_id' => 'gap-missing-leverage',
                'priority' => 'high',
                'target_coverage' => 3,
                'task_family' => 'compounding_flywheel',
            ]],
        ]);

        $this->assertCount(1, $result['gap_candidates']);
        $candidate = $result['gap_candidates'][0];
        $this->assertSame('gap-missing-leverage', $candidate['gap_id']);
        $this->assertNotEmpty($candidate['reason']);
        $this->assertSame('compounding_flywheel', $candidate['suggested_task_family']);
    }

    public function test_gap_candidate_derives_suggested_task_family_when_none_declared(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-no-family', 'priority' => 'high', 'target_coverage' => 3]],
        ]);

        $this->assertSame('roadmap_gap_no_family', $result['gap_candidates'][0]['suggested_task_family']);
    }

    public function test_low_priority_gap_does_not_emit_gap_candidate(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-low', 'priority' => 'low', 'target_coverage' => 3]],
        ]);

        $this->assertSame([], $result['gap_candidates']);
    }

    public function test_overcovered_gap_does_not_emit_gap_candidate(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-full', 'priority' => 'high', 'target_coverage' => 1]],
            'queued_tasks' => [['gap_id' => 'gap-full']],
        ]);

        $this->assertSame([], $result['gap_candidates']);
    }

    // ── AC4: stale roadmap items are marked stale instead of blindly enqueued ──

    public function test_stale_undercovered_gap_is_marked_stale_instead_of_enqueued(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [[
                'gap_id' => 'gap-ancient',
                'priority' => 'high',
                'target_coverage' => 3,
                'last_reviewed_days_ago' => 400,
            ]],
        ]);

        $this->assertNotContains('gap-ancient', $result['undercovered_high_priority_gaps']);
        $this->assertNotContains('gap-ancient', $result['next_batch_should_target']);
        $this->assertSame([], $result['gap_candidates']);
        $this->assertCount(1, $result['stale_roadmap_gaps']);
        $this->assertSame('gap-ancient', $result['stale_roadmap_gaps'][0]['gap_id']);
        $this->assertSame('stale_requires_revalidation_before_enqueue', $result['stale_roadmap_gaps'][0]['reason']);
        $this->assertSame(400, $result['stale_roadmap_gaps'][0]['last_reviewed_days_ago']);
    }

    public function test_recently_reviewed_gap_is_not_stale(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [[
                'gap_id' => 'gap-fresh',
                'priority' => 'high',
                'target_coverage' => 3,
                'last_reviewed_days_ago' => 10,
            ]],
        ]);

        $this->assertSame([], $result['stale_roadmap_gaps']);
        $this->assertContains('gap-fresh', $result['undercovered_high_priority_gaps']);
    }

    public function test_stale_gap_does_not_get_task_fabric_replenishment_even_with_low_worker_floor(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [[
                'gap_id' => 'gap-ancient',
                'priority' => 'high',
                'target_coverage' => 3,
                'last_reviewed_days_ago' => 400,
                'allowed_files' => ['app/Services/Foo.php'],
                'acceptance_criteria' => ['php artisan test tests/Unit/FooTest.php'],
            ]],
            'claimable_per_active_worker' => 1.0,
        ]);

        $this->assertSame([], $result['task_fabric_replenishment_actions']);
        $this->assertCount(1, $result['stale_roadmap_gaps']);
    }

    public function test_overcovered_gap_at_stale_review_age_is_reported_as_overcovered_not_stale(): void
    {
        // Overcovered short-circuits before the staleness check runs.
        $result = $this->governor()->govern([
            'roadmap_gaps' => [[
                'gap_id' => 'gap-full-and-old',
                'priority' => 'high',
                'target_coverage' => 1,
                'last_reviewed_days_ago' => 400,
            ]],
            'queued_tasks' => [['gap_id' => 'gap-full-and-old']],
        ]);

        $this->assertContains('gap-full-and-old', $result['overcovered_gaps']);
        $this->assertSame([], $result['stale_roadmap_gaps']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'roadmap_gaps' => [['gap_id' => 'gap-a', 'priority' => 'high', 'target_coverage' => 3]],
            'queued_tasks' => [['gap_id' => 'gap-a']],
        ];

        $this->assertSame(
            json_encode($this->governor()->govern($facts)),
            json_encode($this->governor()->govern($facts)),
        );
    }
}
