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
        $this->assertStringContainsString('--image|--ai|--provider|--model|--agent|--thread|--workspace|--mode|--permission|--timeout|--dev-plan|--skill)', $script);
        $this->assertStringContainsString('--clipboard-image', $script);
        $this->assertStringContainsString('--no-auto-image', $script);
        $this->assertStringContainsString('exec_artisan_with_workspace atlas:cli:doctor "$@"', $script);
        $this->assertStringContainsString('benchmark|bench)', $script);
        $this->assertStringContainsString('exec_artisan_with_workspace atlas:engineering:benchmark:seed "$@"', $script);
        $this->assertStringContainsString('exec_artisan_with_workspace atlas:engineering:benchmark:calibrate "$@"', $script);
        $this->assertStringContainsString('exec_artisan_with_workspace atlas:engineering:benchmark:claude-fair "$@"', $script);
        $this->assertStringContainsString("      run)\n        shift || true\n        exec_artisan_with_workspace atlas:engineering:benchmark \"\$@\"", $script);
        $this->assertStringContainsString('exec "$PHP_BIN" artisan atlas:engineering:docker-cleanup "$@"', $script);
        $this->assertStringContainsString('exec_artisan_with_workspace atlas:engineering:quality-scan "$@"', $script);
        $this->assertStringContainsString('exec_artisan_with_workspace atlas:engineering:visual-smoke "$@"', $script);
        $this->assertStringContainsString('exec "$PHP_BIN" artisan atlas:engineering:visual-driver "$@"', $script);
        $this->assertStringContainsString('exec_artisan_with_workspace atlas:engineering:visual-baseline "$@"', $script);
        $this->assertStringContainsString('exec_artisan_with_workspace atlas:engineering:benchmark "$@"', $script);
        $this->assertStringContainsString('exec_artisan_with_workspace atlas:engineering:run "$@"', $script);
    }
}
