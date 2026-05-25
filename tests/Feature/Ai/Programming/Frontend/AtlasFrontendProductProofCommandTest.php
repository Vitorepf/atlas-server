<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendProductProofCommandTest extends TestCase
{
    public function test_proof_command_emits_demo_catalog(): void
    {
        $exitCode = Artisan::call('atlas:frontend:proof', ['action' => 'catalog', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.product_proof_runtime.v1', $output);
        $this->assertStringContainsString('saas_dashboard_repair', $output);
        $this->assertStringContainsString('public_site_claim_requires_hosted_demo', $output);
    }

    public function test_proof_command_builds_static_bundle(): void
    {
        $outputPath = sys_get_temp_dir().'/atlas-frontend-proof-command-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:proof', [
            'action' => 'build',
            '--output' => $outputPath,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.product_proof_bundle.v1', $output);
        $this->assertFileExists($outputPath.'/index.html');
        $this->assertFileExists($outputPath.'/manifest.json');
    }
}
