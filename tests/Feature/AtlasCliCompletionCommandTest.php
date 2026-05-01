<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasCliCompletionCommandTest extends TestCase
{
    public function test_path_action_prints_existing_script_path(): void
    {
        $exit = Artisan::call('atlas:cli:completion', ['action' => 'path']);
        $output = trim(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertStringEndsWith('bin/atlas-completion.bash', $output);
        $this->assertFileExists($output);
    }

    public function test_bash_action_prints_completion_script_body(): void
    {
        $exit = Artisan::call('atlas:cli:completion', ['action' => 'bash']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('_atlas_complete', $output);
        $this->assertStringContainsString('complete -F _atlas_complete atlas', $output);
    }

    public function test_install_action_prints_shell_snippets(): void
    {
        $exit = Artisan::call('atlas:cli:completion', ['action' => 'install']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('# bash', $output);
        $this->assertStringContainsString('# zsh', $output);
        $this->assertStringContainsString('compinit', $output);
    }
}
