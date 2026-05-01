<?php

namespace Tests\Feature\Ai\Skills;

use App\Services\Ai\Skills\SkillDiscoveryService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class SkillDiscoveryTest extends TestCase
{
    private string $root;
    private string $home;
    private string $workspace;
    private string $previousHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/atlas-skill-discovery-'.Str::random(10);
        $this->home = $this->root.'/home';
        $this->workspace = $this->root.'/workspace';
        $this->previousHome = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');

        File::ensureDirectoryExists($this->home);
        File::ensureDirectoryExists($this->workspace);
        File::ensureDirectoryExists($this->root.'/vault/_skills');
        $_SERVER['HOME'] = $this->home;
        putenv('HOME='.$this->home);
        config(['atlas.semantic_memory.vault_path' => $this->root.'/vault']);
    }

    protected function tearDown(): void
    {
        if ($this->previousHome !== '') {
            $_SERVER['HOME'] = $this->previousHome;
            putenv('HOME='.$this->previousHome);
        }

        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_ignores_workspace_skills_until_workspace_is_trusted(): void
    {
        $this->writeSkill($this->workspace.'/.agents/skills/workspace-skill', 'workspace-skill', 'Workspace local skill.');
        $this->writeSkill($this->home.'/.atlas/skills/user-skill', 'user-skill', 'User skill.');

        $service = app(SkillDiscoveryService::class);
        $names = collect($service->discoverAll($this->workspace))->pluck('name')->all();

        $this->assertContains('user-skill', $names);
        $this->assertNotContains('workspace-skill', $names);

        $service->trustWorkspace($this->workspace);
        $names = collect($service->discoverAll($this->workspace))->pluck('name')->all();

        $this->assertContains('workspace-skill', $names);
    }

    public function test_workspace_skill_cannot_override_core_skill_when_trusted(): void
    {
        $this->writeSkill($this->workspace.'/.atlas/skills/dev-quality-gate', 'dev-quality-gate', 'Workspace override description.');

        $service = app(SkillDiscoveryService::class);
        $service->trustWorkspace($this->workspace);
        $skill = collect($service->discoverAll($this->workspace))
            ->firstWhere('name', 'dev-quality-gate');

        $this->assertNotNull($skill);
        $this->assertSame('builtin', $skill->sourceTier);
        $this->assertNotSame('Workspace override description.', $skill->description);

        $diagnostic = collect($service->diagnostics())
            ->firstWhere('message', 'workspace_skill_conflicts_with_protected_skill');

        $this->assertNotNull($diagnostic);
        $this->assertSame('dev-quality-gate', $diagnostic['name']);
        $this->assertSame('workspace_atlas', $diagnostic['source_tier']);
        $this->assertSame('builtin', $diagnostic['kept_source_tier']);
    }

    public function test_trusted_workspace_skill_extends_atlas_when_name_is_unique(): void
    {
        $this->writeSkill($this->workspace.'/.atlas/skills/blackink-local', 'blackink-local', 'Blackink repo workflow.');

        $service = app(SkillDiscoveryService::class);
        $service->trustWorkspace($this->workspace);
        $skill = collect($service->discoverAll($this->workspace))
            ->firstWhere('name', 'blackink-local');

        $this->assertNotNull($skill);
        $this->assertSame('workspace_atlas', $skill->sourceTier);
        $this->assertSame('Blackink repo workflow.', $skill->description);
    }

    public function test_skills_command_lists_bundles_as_json(): void
    {
        $this->artisan('atlas:cli:skills', [
            'action' => 'list',
            '--workspace' => $this->workspace,
            '--json' => true,
        ])->assertExitCode(0);
    }

    private function writeSkill(string $directory, string $name, string $description): void
    {
        File::ensureDirectoryExists($directory);
        File::put($directory.'/SKILL.md', <<<MD
---
name: {$name}
description: {$description}
---

# {$name}

Procedure for {$name}.
MD);
    }
}
