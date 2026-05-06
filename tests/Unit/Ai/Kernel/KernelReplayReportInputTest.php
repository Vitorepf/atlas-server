<?php

namespace Tests\Unit\Ai\Kernel;

use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Tests\TestCase;

class KernelReplayReportInputTest extends TestCase
{
    public function test_hours_normalizes_window_with_canonical_limits(): void
    {
        $input = new KernelReplayReportInput;

        $this->assertSame(24, $input->hours(null));
        $this->assertSame(24, $input->hours(['bad']));
        $this->assertSame(1, $input->hours(-5));
        $this->assertSame(720, $input->hours(9999));
        $this->assertSame(12, $input->hours('12'));
    }

    public function test_scalar_filters_trim_values_and_drop_empty_or_non_scalar_values(): void
    {
        $input = new KernelReplayReportInput;

        $this->assertSame(
            ['status' => 'rejected', 'emitter_stage' => 'atlas.test'],
            $input->scalarFilters([
                'status' => ' rejected ',
                'surface_id' => '',
                'flow' => ['bad'],
                'emitter_stage' => 'atlas.test',
            ], ['status', 'surface_id', 'flow', 'emitter_stage']),
        );
    }

    public function test_aliased_scalar_filters_use_first_non_empty_alias(): void
    {
        $input = new KernelReplayReportInput;

        $this->assertSame(
            ['surface_id' => 'atlas_cli_dev', 'tool_id' => 'phpstan'],
            $input->aliasedScalarFilters([
                'surface_id' => '',
                'surface' => ' atlas_cli_dev ',
                'tool' => 'phpstan',
            ], [
                'surface_id' => ['surface_id', 'surface'],
                'tool_id' => ['tool_id', 'tool'],
            ]),
        );
    }
}
