<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendEvidencePackCommandTest extends TestCase
{
    public function test_evidence_template_command_writes_manifest(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-evidence-command-template-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:evidence', [
            'action' => 'template',
            '--output' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.evidence_pack_template.v1', $output);
        $this->assertTrue(File::isFile($dir.'/evidence-pack.json'));
    }

    public function test_evidence_verify_command_blocks_missing_manifest(): void
    {
        $exitCode = Artisan::call('atlas:frontend:evidence', [
            'action' => 'verify',
            '--manifest' => sys_get_temp_dir().'/missing-evidence-pack.json',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendEvidencePackVerifierService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('manifest_missing', $output);
    }
}
