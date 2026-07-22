<?php

namespace Tests\Unit\Ai\Cognitive\WorkedExample;

use App\Services\Ai\Cognitive\WorkedExample\ProcessFadingScheduler;
use Tests\TestCase;

class ProcessFadingSchedulerTest extends TestCase
{
    public function test_scheduler_maps_dreyfus_stages_to_expected_visible_steps(): void
    {
        $scheduler = app(ProcessFadingScheduler::class);

        $this->assertSame([1, 2, 3, 4, 5], $scheduler->schedule(1)['visible_steps']);
        $this->assertSame([1, 3, 5], $scheduler->schedule(2)['visible_steps']);
        $this->assertSame([1, 5], $scheduler->schedule(3)['visible_steps']);
        $this->assertSame([], $scheduler->schedule(4)['visible_steps']);
        $this->assertSame([], $scheduler->schedule(5)['visible_steps']);
    }
}
