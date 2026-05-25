<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendPublicationCommandTest extends TestCase
{
    public function test_publish_verify_command_marks_local_bundle_ready(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publish-command-local-'.bin2hex(random_bytes(4));
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);

        $exitCode = Artisan::call('atlas:frontend:publish', [
            'action' => 'verify',
            '--bundle' => $bundle,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.publication_verifier.v1', $output);
        $this->assertStringContainsString('local_ready', $output);
    }

    public function test_publish_verify_strict_requires_public_receipt(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-publish-command-strict-'.bin2hex(random_bytes(4));
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);

        $exitCode = Artisan::call('atlas:frontend:publish', [
            'action' => 'verify',
            '--bundle' => $bundle,
            '--strict' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('local_ready', Artisan::output());
    }

    public function test_publish_receipt_template_command_writes_receipt(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-publish-command-template-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:publish', [
            'action' => 'receipt-template',
            '--output' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.publication_receipt_template.v1', $output);
        $this->assertTrue(File::isFile($dir.'/publication-receipt.json'));
    }
}
