<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendGauntletService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendGauntletCommandTest extends TestCase
{
    public function test_gauntlet_command_blocks_missing_local_repo_context(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-gauntlet-command-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

        $exitCode = Artisan::call('atlas:frontend:gauntlet', [
            '--task' => 'Criar redesign premium SaaS novo',
            '--workspace' => $workspace,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendGauntletService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('fill_company_design_dossier_docs', $output);
        $this->assertStringContainsString('recommended_command_sequence', $output);
    }
}
