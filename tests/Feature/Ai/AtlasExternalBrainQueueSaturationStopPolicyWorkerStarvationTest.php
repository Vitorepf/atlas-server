<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueueSaturationStopPolicy;
use Tests\TestCase;

final class AtlasExternalBrainQueueSaturationStopPolicyWorkerStarvationTest extends TestCase
{
    private function starvedWorkerFixture(array $overrides = []): array
    {
        return array_merge([
            'claimable_depth' => 3,
            'servable_now' => 10,
            'active_worker_count' => 6,
            'minimum_claimable_per_worker' => 2,
            'recoverable_backlog' => 0,
        ], $overrides);
    }

    public function test_worker_floor_starvation_with_no_recoverable_backlog_originates_more(): void
    {
        $result = (new AtlasExternalBrainQueueSaturationStopPolicy)->evaluate($this->starvedWorkerFixture());

        $this->assertSame(AtlasExternalBrainQueueSaturationStopPolicy::DECISION_ORIGINATE_MORE, $result['decision']);
    }

    public function test_worker_floor_starvation_with_recoverable_backlog_unblocks_first(): void
    {
        $result = (new AtlasExternalBrainQueueSaturationStopPolicy)->evaluate(
            $this->starvedWorkerFixture(['recoverable_backlog' => 4])
        );

        $this->assertSame(AtlasExternalBrainQueueSaturationStopPolicy::DECISION_UNBLOCK_FIRST, $result['decision']);
    }

    public function test_servable_now_meeting_worker_floor_is_not_starved(): void
    {
        $result = (new AtlasExternalBrainQueueSaturationStopPolicy)->evaluate(
            $this->starvedWorkerFixture(['servable_now' => 12])
        );

        $this->assertNotSame(AtlasExternalBrainQueueSaturationStopPolicy::DECISION_ORIGINATE_MORE, $result['decision']);
        $this->assertNotSame(AtlasExternalBrainQueueSaturationStopPolicy::DECISION_UNBLOCK_FIRST, $result['decision']);
    }

    public function test_falls_back_to_flat_burn_rate_floor_when_worker_inputs_absent(): void
    {
        $result = (new AtlasExternalBrainQueueSaturationStopPolicy)->evaluate([
            'claimable_depth' => 3,
            'servable_now' => 10,
            'recoverable_backlog' => 0,
        ]);

        $this->assertNotSame(AtlasExternalBrainQueueSaturationStopPolicy::DECISION_ORIGINATE_MORE, $result['decision']);
    }
}
