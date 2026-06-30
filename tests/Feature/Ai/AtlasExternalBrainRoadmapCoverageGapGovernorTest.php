<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRoadmapCoverageGapGovernor;
use Tests\TestCase;

final class AtlasExternalBrainRoadmapCoverageGapGovernorTest extends TestCase
{
    private function governor(): AtlasExternalBrainRoadmapCoverageGapGovernor
    {
        return new AtlasExternalBrainRoadmapCoverageGapGovernor;
    }

    public function test_accepts_roadmap_queued_completed_and_candidate_facts(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-a', 'priority' => 'high', 'target_coverage' => 2]],
            'queued_tasks' => [['gap_id' => 'gap-a', 'impact_class' => 'high']],
            'completed_capabilities' => [['gap_id' => 'gap-b']],
            'candidate_batches' => [['gap_id' => 'gap-a', 'impact_class' => 'high']],
        ]);

        foreach (['coverage_by_gap', 'overcovered_gaps', 'undercovered_high_priority_gaps', 'next_batch_should_target'] as $field) {
            $this->assertArrayHasKey($field, $result, "missing field: {$field}");
        }
    }

    public function test_undercovered_high_priority_gap_is_reported_as_next_batch_target(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-needs-work', 'priority' => 'high', 'target_coverage' => 3]],
            'queued_tasks' => [['gap_id' => 'gap-needs-work']],
        ]);

        $this->assertContains('gap-needs-work', $result['undercovered_high_priority_gaps']);
        $this->assertContains('gap-needs-work', $result['next_batch_should_target']);
        $this->assertSame(1, $result['coverage_by_gap']['gap-needs-work']['current_coverage']);
        $this->assertSame(2, $result['coverage_by_gap']['gap-needs-work']['coverage_gap']);
    }

    public function test_low_priority_undercovered_gap_is_not_a_next_batch_target(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-low', 'priority' => 'low', 'target_coverage' => 3]],
            'queued_tasks' => [],
        ]);

        $this->assertNotContains('gap-low', $result['next_batch_should_target']);
    }

    public function test_fully_covered_gap_is_overcovered(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-done', 'priority' => 'high', 'target_coverage' => 2]],
            'queued_tasks' => [
                ['gap_id' => 'gap-done'],
                ['gap_id' => 'gap-done'],
            ],
        ]);

        $this->assertContains('gap-done', $result['overcovered_gaps']);
        $this->assertNotContains('gap-done', $result['undercovered_high_priority_gaps']);
    }

    public function test_candidate_for_overcovered_gap_is_blocked_by_default(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-full', 'priority' => 'high', 'target_coverage' => 1]],
            'queued_tasks' => [['gap_id' => 'gap-full']],
            'candidate_batches' => [['gap_id' => 'gap-full']],
        ]);

        $this->assertSame('blocked', $result['candidate_batch_decisions'][0]['decision']);
        $this->assertSame('gap_already_overcovered', $result['candidate_batch_decisions'][0]['reason']);
    }

    public function test_prerequisite_unlock_candidate_is_allowed_even_when_overcovered(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-full', 'priority' => 'high', 'target_coverage' => 1]],
            'queued_tasks' => [['gap_id' => 'gap-full']],
            'candidate_batches' => [['gap_id' => 'gap-full', 'is_prerequisite_unlock' => true]],
        ]);

        $this->assertSame('allowed', $result['candidate_batch_decisions'][0]['decision']);
    }

    public function test_high_value_repair_candidate_is_allowed_even_when_overcovered(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-full', 'priority' => 'high', 'target_coverage' => 1]],
            'queued_tasks' => [['gap_id' => 'gap-full']],
            'candidate_batches' => [['gap_id' => 'gap-full', 'is_high_value_repair' => true]],
        ]);

        $this->assertSame('allowed', $result['candidate_batch_decisions'][0]['decision']);
    }

    public function test_candidate_for_non_overcovered_gap_is_allowed(): void
    {
        $result = $this->governor()->govern([
            'roadmap_gaps' => [['gap_id' => 'gap-open', 'priority' => 'high', 'target_coverage' => 3]],
            'queued_tasks' => [],
            'candidate_batches' => [['gap_id' => 'gap-open']],
        ]);

        $this->assertSame('allowed', $result['candidate_batch_decisions'][0]['decision']);
        $this->assertFalse($result['mutates_queue']);
    }
}
