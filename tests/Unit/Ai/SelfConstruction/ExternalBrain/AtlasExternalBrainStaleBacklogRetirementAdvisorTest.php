<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainStaleBacklogRetirementAdvisor;
use Tests\TestCase;

final class AtlasExternalBrainStaleBacklogRetirementAdvisorTest extends TestCase
{
    private function advisor(): AtlasExternalBrainStaleBacklogRetirementAdvisor
    {
        return new AtlasExternalBrainStaleBacklogRetirementAdvisor;
    }

    private function task(array $overrides = []): array
    {
        return array_merge([
            'task_id' => 'tp-1',
            'family' => 'self_construction',
            'age_days' => 5,
            'status' => 'claimable',
            'give_back_count' => 0,
            'superseded_capability' => false,
            'duplicate_target' => false,
            'repair_path_clear' => false,
            'strategic_value' => 'low',
        ], $overrides);
    }

    public function test_old_duplicate_task_with_superseded_capability_is_recommended_retire(): void
    {
        $result = $this->advisor()->advise($this->task([
            'age_days' => 45,
            'superseded_capability' => true,
        ]));

        $this->assertSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RETIRE, $result['decision']);
        $this->assertSame('superseded_capability', $result['reason']);
    }

    public function test_old_duplicate_target_task_is_recommended_retire(): void
    {
        $result = $this->advisor()->advise($this->task([
            'age_days' => 60,
            'duplicate_target' => true,
        ]));

        $this->assertSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RETIRE, $result['decision']);
        $this->assertSame('duplicate_target', $result['reason']);
    }

    public function test_old_blocked_task_with_clear_repair_path_and_high_value_is_respec_not_retire(): void
    {
        $result = $this->advisor()->advise($this->task([
            'age_days' => 50,
            'status' => 'blocked',
            'repair_path_clear' => true,
            'strategic_value' => 'high',
        ]));

        $this->assertSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RESPEC, $result['decision']);
        $this->assertNotSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RETIRE, $result['decision']);
    }

    public function test_fresh_high_value_claimable_task_is_recommended_keep(): void
    {
        $result = $this->advisor()->advise($this->task([
            'age_days' => 2,
            'status' => 'claimable',
            'strategic_value' => 'high',
        ]));

        $this->assertSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_KEEP, $result['decision']);
    }

    public function test_age_band_classification(): void
    {
        $this->assertSame('fresh', $this->advisor()->advise($this->task(['age_days' => 3]))['age_band']);
        $this->assertSame('aging', $this->advisor()->advise($this->task(['age_days' => 20]))['age_band']);
        $this->assertSame('stale', $this->advisor()->advise($this->task(['age_days' => 40]))['age_band']);
    }

    public function test_old_blocked_task_without_repair_path_does_not_get_respec(): void
    {
        $result = $this->advisor()->advise($this->task([
            'age_days' => 50,
            'status' => 'blocked',
            'repair_path_clear' => false,
            'strategic_value' => 'high',
        ]));

        $this->assertNotSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RESPEC, $result['decision']);
    }

    public function test_output_includes_all_six_required_fields(): void
    {
        $result = $this->advisor()->advise($this->task());

        $this->assertArrayHasKey('task_id', $result);
        $this->assertArrayHasKey('family', $result);
        $this->assertArrayHasKey('age_band', $result);
        $this->assertArrayHasKey('decision', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertArrayHasKey('next_action', $result);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $advisor = $this->advisor();
        $task = $this->task(['age_days' => 45, 'superseded_capability' => true]);

        $this->assertSame($advisor->advise($task), $advisor->advise($task));
    }
}
