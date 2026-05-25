<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendCompanyDesignProfileCommandTest extends TestCase
{
    public function test_company_profile_template_command_writes_profile(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-company-profile-command-template-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:company-profile', [
            'action' => 'template',
            '--output' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.company_design_profile_template.v1', $output);
        $this->assertTrue(File::isFile($dir.'/company-design-profile.json'));
    }

    public function test_company_profile_inspect_command_blocks_missing_profile(): void
    {
        $exitCode = Artisan::call('atlas:frontend:company-profile', [
            'action' => 'inspect',
            '--profile' => sys_get_temp_dir().'/missing-company-profile.json',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('atlas.frontend.company_design_profile.v1', $output);
        $this->assertStringContainsString('profile_missing', $output);
    }
}
