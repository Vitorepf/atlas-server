<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Support\AtlasCliLimitInput;
use Tests\TestCase;

class AtlasCliLimitInputTest extends TestCase
{
    public function test_normalizes_shared_cli_limits(): void
    {
        $input = new AtlasCliLimitInput;

        $this->assertSame(AtlasCliLimitInput::DEFAULT_LIST_LIMIT, $input->standardLimit(null));
        $this->assertSame(AtlasCliLimitInput::DEFAULT_INBOX_LIMIT, $input->inboxLimit('bad'));
        $this->assertSame(AtlasCliLimitInput::MAX_STANDARD_LIMIT, $input->standardLimit(999));
        $this->assertSame(AtlasCliLimitInput::MAX_RELEASE_GATE_LIMIT, $input->releaseGateLimit(999));
        $this->assertSame(AtlasCliLimitInput::MAX_BENCHMARK_CALIBRATION_LIMIT, $input->benchmarkCalibrationLimit(99999));
        $this->assertSame(1, $input->standardLimit(-10));
    }
}
