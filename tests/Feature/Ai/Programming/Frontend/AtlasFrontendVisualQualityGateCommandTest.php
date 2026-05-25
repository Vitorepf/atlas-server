<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendVisualQualityGateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendVisualQualityGateCommandTest extends TestCase
{
    public function test_visual_quality_template_command_writes_report(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-visual-quality-command-template-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:visual-quality', [
            'action' => 'template',
            '--output' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendVisualQualityGateService::TEMPLATE_SCHEMA_VERSION, $output);
        $this->assertTrue(File::isFile($dir.'/visual-quality-report.json'));
    }

    public function test_visual_quality_inspect_command_blocks_missing_report(): void
    {
        $exitCode = Artisan::call('atlas:frontend:visual-quality', [
            'action' => 'inspect',
            '--report' => sys_get_temp_dir().'/missing-visual-quality-report.json',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendVisualQualityGateService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('visual_quality_report_missing', $output);
    }
}
