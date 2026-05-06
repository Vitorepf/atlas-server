<?php

namespace Tests\Unit\Ai\Scheduling;

use App\Services\Ai\Scheduling\AtlasSchedulerInput;
use Tests\TestCase;

class AtlasSchedulerInputTest extends TestCase
{
    public function test_normalizes_scheduler_due_task_limit(): void
    {
        $input = new AtlasSchedulerInput;

        $this->assertSame(AtlasSchedulerInput::DEFAULT_DUE_TASK_LIMIT, $input->dueTaskLimit(null));
        $this->assertSame(AtlasSchedulerInput::DEFAULT_DUE_TASK_LIMIT, $input->dueTaskLimit('bad'));
        $this->assertSame(1, $input->dueTaskLimit(-10));
        $this->assertSame(42, $input->dueTaskLimit('42'));
        $this->assertSame(AtlasSchedulerInput::MAX_DUE_TASK_LIMIT, $input->dueTaskLimit(9999));
    }
}
