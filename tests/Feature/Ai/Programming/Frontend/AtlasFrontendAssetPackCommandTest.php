<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendAssetPackService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendAssetPackCommandTest extends TestCase
{
    public function test_assets_template_command_writes_pack(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-assets-command-template-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:assets', [
            'action' => 'template',
            '--output' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendAssetPackService::TEMPLATE_SCHEMA_VERSION, $output);
        $this->assertTrue(File::isFile($dir.'/frontend-asset-pack.json'));
    }

    public function test_assets_inspect_command_blocks_missing_pack(): void
    {
        $exitCode = Artisan::call('atlas:frontend:assets', [
            'action' => 'inspect',
            '--pack' => sys_get_temp_dir().'/missing-frontend-asset-pack.json',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendAssetPackService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('asset_pack_missing', $output);
    }
}
