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
        $this->assertStringContainsString('benchmark bench engineering harness', $output);
        $this->assertStringContainsString('--from-recent-runs=', $output);
        $this->assertStringContainsString('--gate-profile=', $output);
        $this->assertStringContainsString('--docker-service=', $output);
        $this->assertStringContainsString('--docker-cache=', $output);
        $this->assertStringContainsString('--docker-network=', $output);
        $this->assertStringContainsString('--docker-artifact-path=', $output);
        $this->assertStringContainsString('--provider-runtime=', $output);
        $this->assertStringContainsString('--attempt', $output);
        $this->assertStringContainsString('sonnet opus opus-4.7 claude-opus-4-7 haiku spark mini codex-premium codex-5.5 gpt-5.5', $output);
        $this->assertStringContainsString('--model-policy=', $output);
        $this->assertStringContainsString('best-quality fastest cheapest', $output);
        $this->assertStringContainsString('cleanup docker-cleanup', $output);
        $this->assertStringContainsString('quality-scan quality scan', $output);
        $this->assertStringContainsString('preview diff write inspect status adopt', $output);
        $this->assertStringContainsString('--yes', $output);
        $this->assertStringContainsString('--provider-projection', $output);
        $this->assertStringContainsString('--provider-projection-target', $output);
        $this->assertStringContainsString('skip status review apply write adopt', $output);
        $this->assertStringContainsString('--provider-projection-yes', $output);
        $this->assertStringContainsString('visual-smoke visual', $output);
        $this->assertStringContainsString('visual-driver driver playwright visual-runtime', $output);
        $this->assertStringContainsString('visual-baseline baseline', $output);
        $this->assertStringContainsString('harnessability', $output);
        $this->assertStringContainsString('--cache-retention-days=', $output);
        $this->assertStringContainsString('--visual-e2e=', $output);
        $this->assertStringContainsString('--quality-scan', $output);
        $this->assertStringContainsString('--quality-profile', $output);
        $this->assertStringContainsString('--quality-changed-only', $output);
        $this->assertStringContainsString('--screenshot-baseline', $output);
        $this->assertStringContainsString('--screenshot-driver', $output);
        $this->assertStringContainsString('--changed-only', $output);
        $this->assertStringContainsString('auto fast standard release deep', $output);
        $this->assertStringContainsString('auto workspace atlas off', $output);
        $this->assertStringContainsString('--skip-browser-install', $output);
        $this->assertStringContainsString('playwright @playwright/test', $output);
        $this->assertStringContainsString('calibrate calibration cal', $output);
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
