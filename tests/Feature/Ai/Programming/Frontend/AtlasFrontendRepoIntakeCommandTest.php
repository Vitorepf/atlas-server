<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendRepoIntakeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendRepoIntakeCommandTest extends TestCase
{
    public function test_intake_command_emits_repo_intake_hash_and_fails_strict_when_blocked(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-intake-command-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

        $exitCode = Artisan::call('atlas:frontend:intake', [
            '--workspace' => $workspace,
            '--json' => true,
            '--strict' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(AtlasFrontendRepoIntakeService::SCHEMA_VERSION, $output);
        $this->assertStringContainsString('repo_intake_hash', $output);
        $this->assertStringContainsString('company_design_dossier_not_ready', $output);
    }
}
