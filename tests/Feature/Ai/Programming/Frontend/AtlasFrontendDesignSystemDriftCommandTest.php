<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignSystemDriftGateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendDesignSystemDriftCommandTest extends TestCase
{
    public function test_design_system_drift_template_command_writes_report(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-design-system-drift-command-template-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:design-system-drift', [
            'action' => 'template',
            '--output' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendDesignSystemDriftGateService::TEMPLATE_SCHEMA_VERSION, $output);
        $this->assertTrue(File::isFile($dir.'/design-system-drift-report.json'));
    }

    public function test_design_system_drift_inspect_command_blocks_missing_report(): void
    {
        $exitCode = Artisan::call('atlas:frontend:design-system-drift', [
            'action' => 'inspect',
            '--report' => sys_get_temp_dir().'/missing-design-system-drift-report.json',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendDesignSystemDriftGateService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('design_system_drift_report_missing', $output);
    }
}
