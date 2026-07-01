<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainQueuePressureCommandTest extends TestCase
{
    public function test_fast_proven_drain_overrides_sufficient_depth_zero_batch(): void
    {
        Artisan::call('atlas:external-brain:queue-pressure', [
            '--queue-depth' => 20,
            '--servable-now' => 20,
            '--active-leases' => 3,
            '--recent-successes' => 10,
            '--recent-give-backs' => 0,
            '--median-task-minutes' => 10,
            '--claimable-per-active-worker' => 6,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('increase_origination', $payload['drain_forecast']['recommended_originator_pace']);
        $this->assertSame(0, $payload['adaptive_batch']['recommended_batch_size']);
        $this->assertSame(2, $payload['recommended_batch_size']);
    }
}
