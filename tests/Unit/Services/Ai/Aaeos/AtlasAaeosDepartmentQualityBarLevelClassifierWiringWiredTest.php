<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasAaeosDepartmentQualityBarLevelClassifierWiringWiredTest extends TestCase
{
    public function test_department_status_command_exposes_quality_bar_level_classification(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:aeos:department-status', ['--json' => true]);
        $out = $kernel->output();

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);

        $this->assertSame(
            'atlas.aaeos.quality_bar_level_classification_batch.v1',
            $decoded['quality_bar_level_classification']['schema_version'],
        );

        $departments = $decoded['quality_bar_level_classification']['departments'];
        $this->assertNotEmpty($departments);

        foreach ($departments as $id => $result) {
            $this->assertSame($id, $result['department_id']);
            $this->assertSame('atlas.aaeos.quality_bar_level.v1', $result['schema_version']);
            $this->assertArrayHasKey('achieved_level', $result);
            $this->assertArrayHasKey('promotion_blocked', $result);
        }
    }
}
