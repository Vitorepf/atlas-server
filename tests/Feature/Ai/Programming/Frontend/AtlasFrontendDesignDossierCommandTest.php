<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendDesignDossierCommandTest extends TestCase
{
    public function test_design_dossier_template_command_writes_docs(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-design-dossier-command-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

        $exitCode = Artisan::call('atlas:frontend:design-dossier', [
            'action' => 'template',
            '--workspace' => $workspace,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendDesignDossierService::TEMPLATE_SCHEMA_VERSION, $output);
        $this->assertTrue(File::isFile($workspace.'/docs/design/product-experience-brief.md'));
    }

    public function test_design_dossier_inspect_strict_blocks_missing_docs(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-design-dossier-command-missing-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

        $exitCode = Artisan::call('atlas:frontend:design-dossier', [
            'action' => 'inspect',
            '--workspace' => $workspace,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendDesignDossierService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('missing_design_doc_product_experience_brief', $output);
    }
}
