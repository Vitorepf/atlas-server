<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendSkillPackService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendSkillPackCommandTest extends TestCase
{
    public function test_skill_pack_command_exports_skill_markdown_and_reference_files(): void
    {
        $output = sys_get_temp_dir().'/atlas-frontend-skill-pack-command-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:skill-pack', [
            'action' => 'export',
            '--output' => $output,
            '--json' => true,
            '--strict' => true,
        ]);
        $text = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendSkillPackService::SCHEMA_VERSION, $text);
        $this->assertStringContainsString('provider_safe_atlas_frontend_operating_skill', $text);
        $this->assertStringContainsString('runtime_guardrails', $text);
        $this->assertStringContainsString('world_best_claim_allowed', $text);
        $this->assertFileExists($output.'/SKILL.md');
        $this->assertFileExists($output.'/manifest.json');
        $this->assertFileExists($output.'/reference/claim-policy.md');
    }

    public function test_skill_pack_command_fails_invalid_action(): void
    {
        $exitCode = Artisan::call('atlas:frontend:skill-pack', [
            'action' => 'invalid',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('invalid_action', Artisan::output());
    }

    public function test_skill_pack_command_installs_into_workspace(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-skill-pack-command-install-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

        $exitCode = Artisan::call('atlas:frontend:skill-pack', [
            'action' => 'install',
            '--workspace' => $workspace,
            '--json' => true,
            '--strict' => true,
        ]);
        $text = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendSkillPackService::INSTALL_SCHEMA_VERSION, $text);
        $this->assertStringContainsString('installed', $text);
        $this->assertFileExists($workspace.'/.atlas/skills/atlas-frontend/SKILL.md');
        $this->assertFileExists($workspace.'/.atlas/skills/atlas-frontend/install-receipt.json');
    }

    public function test_skill_pack_command_strict_fails_missing_workspace_install(): void
    {
        $exitCode = Artisan::call('atlas:frontend:skill-pack', [
            'action' => 'install',
            '--workspace' => sys_get_temp_dir().'/atlas-frontend-skill-pack-command-missing-'.bin2hex(random_bytes(4)),
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('workspace_not_found', Artisan::output());
    }
}
