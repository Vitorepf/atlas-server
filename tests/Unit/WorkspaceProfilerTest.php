<?php

namespace Tests\Unit;

use App\Services\Ai\Runtime\WorkspaceProfiler;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WorkspaceProfilerTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-workspace-profiler-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        File::put($this->workspace.'/package.json', json_encode([
            'scripts' => [
                'test' => 'vitest run',
                'lint' => 'eslint .',
            ],
            'dependencies' => [
                'expo' => '^1.0',
                'react-native' => '^1.0',
            ],
        ], JSON_PRETTY_PRINT));
        File::put($this->workspace.'/README.md', 'Atlas');
        config([
            'atlas.ai.runtime.profile_cache_ttl_seconds' => 0,
            'atlas.ai.runtime.profile_max_files' => 50,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_profiles_stack_scripts_tests_and_files(): void
    {
        $profile = app(WorkspaceProfiler::class)->profile($this->workspace, refresh: true);

        $this->assertContains('node', $profile->stack);
        $this->assertContains('expo', $profile->stack);
        $this->assertSame('npm', $profile->packageManager);
        $this->assertSame('vitest run', $profile->scripts['test']);
        $this->assertContains('npm run test', $profile->testCommands);
        $this->assertContains('README.md', $profile->importantFiles);
    }

    public function test_laravel_workspace_prefers_artisan_test_before_composer_wrapper(): void
    {
        File::put($this->workspace.'/artisan', '#!/usr/bin/env php');
        File::put($this->workspace.'/composer.json', json_encode([
            'scripts' => [
                'test' => '@php artisan test',
            ],
        ], JSON_PRETTY_PRINT));

        $profile = app(WorkspaceProfiler::class)->profile($this->workspace, refresh: true);

        $this->assertSame('php artisan test', $profile->testCommands[0]);
        $this->assertContains('composer test', $profile->testCommands);
    }
}
