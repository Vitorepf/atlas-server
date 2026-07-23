<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainStaleBacklogRetirementAdvisor;
use Tests\TestCase;

final class AtlasExternalBrainStaleBacklogRetirementAdvisorTest extends TestCase
{
    private function advisor(): AtlasExternalBrainStaleBacklogRetirementAdvisor
    {
        return new AtlasExternalBrainStaleBacklogRetirementAdvisor;
    }

    public function test_old_superseded_task_is_retired_with_remove_from_queue(): void
    {
        $r = $this->advisor()->advise([
            'task_id' => 'a', 'age_days' => 40, 'status' => 'claimable', 'superseded_capability' => true,
        ]);

        self::assertSame('retire', $r['decision']);
        self::assertSame('remove_from_queue', $r['next_action']);
        self::assertSame('stale', $r['age_band']);
    }

    public function test_old_duplicate_task_is_retired_with_remove_from_queue(): void
    {
        $r = $this->advisor()->advise([
            'task_id' => 'b', 'age_days' => 45, 'status' => 'claimable', 'duplicate_target' => true,
        ]);

        self::assertSame('retire', $r['decision']);
        self::assertSame('remove_from_queue', $r['next_action']);
    }

    public function test_old_blocked_high_value_with_clear_repair_path_is_respeced_not_deleted(): void
    {
        $r = $this->advisor()->advise([
            'task_id' => 'c', 'age_days' => 60, 'status' => 'blocked',
            'repair_path_clear' => true, 'strategic_value' => 'high',
        ]);

        self::assertSame('respec', $r['decision']);
        self::assertNotSame('remove_from_queue', $r['next_action']);
    }

    public function test_fresh_high_value_claimable_task_is_kept(): void
    {
        $r = $this->advisor()->advise([
            'task_id' => 'd', 'age_days' => 2, 'status' => 'claimable', 'strategic_value' => 'high',
        ]);

        self::assertSame('keep', $r['decision']);
        self::assertSame('fresh', $r['age_band']);
    }

    public function test_age_band_is_deterministic_across_boundaries(): void
    {
        $fresh = $this->advisor()->advise(['task_id' => 'x', 'age_days' => 0]);
        $aging = $this->advisor()->advise(['task_id' => 'y', 'age_days' => 14]);
        $stale = $this->advisor()->advise(['task_id' => 'z', 'age_days' => 30]);

        self::assertSame('fresh', $fresh['age_band']);
        self::assertSame('aging', $aging['age_band']);
        self::assertSame('stale', $stale['age_band']);
    }

    public function test_weak_or_no_signal_task_is_not_accidentally_retired(): void
    {
        $r = $this->advisor()->advise(['task_id' => 'e', 'age_days' => 50, 'status' => 'claimable']);

        self::assertSame('keep', $r['decision']);
        self::assertSame('no_strong_retirement_signal', $r['reason']);
    }
}
