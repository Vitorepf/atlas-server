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
            'replacement_task_id' => 'task-replacement-1',
        ]));

        $this->assertSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RETIRE, $result['decision']);
        $this->assertSame('superseded_capability', $result['reason']);
    }

    public function test_old_duplicate_target_task_is_recommended_retire(): void
    {
        $result = $this->advisor()->advise($this->task([
            'age_days' => 60,
            'duplicate_target' => true,
            'replacement_task_id' => 'task-replacement-2',
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

    // ── AC: replacement proof gates retirement ─────────────────────────────────

    public function test_stale_duplicate_target_without_replacement_task_id_does_not_retire_blindly(): void
    {
        $result = $this->advisor()->advise($this->task([
            'age_days' => 60,
            'duplicate_target' => true,
        ]));

        $this->assertNotSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RETIRE, $result['decision']);
        $this->assertSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RESPEC, $result['decision']);
    }

    public function test_stale_superseded_capability_with_replacement_task_id_retires_with_remove_from_queue(): void
    {
        $result = $this->advisor()->advise($this->task([
            'age_days' => 45,
            'superseded_capability' => true,
            'replacement_task_id' => 'task-replacement-3',
        ]));

        $this->assertSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RETIRE, $result['decision']);
        $this->assertSame('remove_from_queue', $result['next_action']);
    }

    public function test_high_strategic_value_blocked_work_with_clear_repair_path_still_returns_respec(): void
    {
        $result = $this->advisor()->advise($this->task([
            'age_days' => 45,
            'status' => 'blocked',
            'repair_path_clear' => true,
            'strategic_value' => 'high',
        ]));

        $this->assertSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RESPEC, $result['decision']);
    }

    // ── AC3: stale packets with still-valid high leverage get respec_refresh ──

    public function test_stale_high_value_claimable_packet_gets_respec_refresh(): void
    {
        $result = $this->advisor()->advise($this->task([
            'age_days' => 40,
            'status' => 'claimable',
            'strategic_value' => 'high',
        ]));

        $this->assertSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RESPEC_REFRESH, $result['decision']);
        $this->assertSame('stale_still_high_leverage', $result['reason']);
        $this->assertSame('refresh_spec_against_current_codebase_before_serving', $result['next_action']);
    }

    public function test_stale_low_value_packet_does_not_get_respec_refresh(): void
    {
        $result = $this->advisor()->advise($this->task([
            'age_days' => 40,
            'status' => 'claimable',
            'strategic_value' => 'low',
        ]));

        $this->assertNotSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RESPEC_REFRESH, $result['decision']);
        $this->assertSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_KEEP, $result['decision']);
    }

    public function test_stale_high_value_blocked_with_clear_repair_path_keeps_full_respec_not_refresh(): void
    {
        // The more specific blocked+clear-repair-path branch must win over the generic refresh branch.
        $result = $this->advisor()->advise($this->task([
            'age_days' => 45,
            'status' => 'blocked',
            'repair_path_clear' => true,
            'strategic_value' => 'high',
        ]));

        $this->assertSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RESPEC, $result['decision']);
        $this->assertNotSame(AtlasExternalBrainStaleBacklogRetirementAdvisor::DECISION_RESPEC_REFRESH, $result['decision']);
    }
}
