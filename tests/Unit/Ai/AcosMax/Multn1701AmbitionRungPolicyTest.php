<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AcosMax\AmbitionRungPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Multn1701AmbitionRungPolicyTest extends TestCase
{
    #[Test]
    public function saturated_task_rung_prefers_next_rung_candidate_with_equal_leverage(): void
    {
        $out = AmbitionRungPolicy::select([
            ['id' => 'task-a', 'rung' => 'task', 'leverage' => 10, 'yield' => 0.7],
            ['id' => 'slice-a', 'rung' => 'slice', 'leverage' => 10, 'yield' => 0.7],
        ], ['enabled' => true, 'current_rung' => 'task', 'reactive_saturated' => true]);

        $this->assertSame('slice-a', $out['selected_id']);
        $this->assertSame('rung_up_after_saturation', $out['basis']);
    }

    #[Test]
    public function disabled_policy_keeps_input_order_pick(): void
    {
        $out = AmbitionRungPolicy::select([
            ['id' => 'task-a', 'rung' => 'task', 'leverage' => 10],
            ['id' => 'obra-a', 'rung' => 'obra', 'leverage' => 10],
        ], ['enabled' => false, 'current_rung' => 'task', 'reactive_saturated' => true]);

        $this->assertSame('task-a', $out['selected_id']);
        $this->assertSame('flag_disabled', $out['basis']);
    }

    #[Test]
    public function rung_series_is_informational_not_score_input(): void
    {
        $out = AmbitionRungPolicy::select([
            ['id' => 'a', 'rung' => 'salto', 'leverage' => 1],
        ], ['enabled' => true, 'current_rung' => 'task', 'reactive_saturated' => false]);

        $this->assertSame(['salto' => 1], $out['rung_distribution']);
        $this->assertFalse($out['source']['rung_series_used_as_score']);
    }
}
