<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasCliLauncherTest extends TestCase
{
    public function test_launcher_preserves_caller_workspace_for_project_commands(): void
    {
        $script = File::get(base_path('bin/atlas'));

        $this->assertStringContainsString('CALLER_PWD="${PWD}"', $script);
        $this->assertStringContainsString('exec_artisan_with_workspace atlas:cli:dev "$@"', $script);
        $this->assertStringContainsString('exec_chat_prompt_with_workspace --stream --no-skill-prompt -- "$@"', $script);
        $this->assertStringContainsString('--image|--provider|--agent|--thread|--workspace|--mode|--permission|--timeout|--dev-plan|--skill)', $script);
        $this->assertStringContainsString('--clipboard-image', $script);
        $this->assertStringContainsString('--no-auto-image', $script);
        $this->assertStringContainsString('exec_artisan_with_workspace atlas:cli:doctor "$@"', $script);
    }
}
