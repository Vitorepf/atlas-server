<?php

namespace Tests\Unit;

use App\Services\Ai\Runtime\AiToolPermissionEngine;
use App\Services\Ai\Runtime\ToolInvocation;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AiToolPermissionEngineTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-tool-permission-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        config([
            'atlas.ai.workdir' => $this->workspace,
            'atlas.ai.tool_permissions.allowed_roots' => [$this->workspace],
            'atlas.ai.tool_permissions.allow_danger' => false,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_read_tool_is_allowed_without_approval(): void
    {
        $decision = app(AiToolPermissionEngine::class)->authorize(ToolInvocation::make('file.read', $this->workspace, [
            'path' => 'README.md',
        ], [
            'permission_mode' => 'read',
        ]));

        $this->assertTrue($decision->allowed);
        $this->assertFalse($decision->requiresApproval);
    }

    public function test_write_tool_is_observability_only_without_human_approval(): void
    {
        $decision = app(AiToolPermissionEngine::class)->authorize(ToolInvocation::make('file.write', $this->workspace, [
            'path' => 'note.txt',
        ], [
            'permission_mode' => 'write',
        ]));

        $this->assertTrue($decision->allowed);
        $this->assertFalse($decision->requiresApproval);
        $this->assertTrue((bool) data_get($decision->metadata, 'observability_only'));
        $this->assertStringContainsString('exigiria aprovacao humana', implode(' ', data_get($decision->metadata, 'observations', [])));
    }

    public function test_dangerous_shell_is_observability_only_when_global_danger_disabled(): void
    {
        $decision = app(AiToolPermissionEngine::class)->authorize(ToolInvocation::make('shell.run', $this->workspace, [
            'command' => 'rm -rf storage',
        ], [
            'permission_mode' => 'danger',
            'metadata' => ['approved' => true],
        ]));

        $this->assertTrue($decision->allowed);
        $this->assertStringContainsString('bloqueado por configuracao global', implode(' ', data_get($decision->metadata, 'observations', [])));
    }

    public function test_write_capable_shell_records_insufficient_permission_without_blocking(): void
    {
        $decision = app(AiToolPermissionEngine::class)->authorize(ToolInvocation::make('shell.run', $this->workspace, [
            'command' => 'touch generated.txt',
        ], [
            'permission_mode' => 'read',
        ]));

        $this->assertTrue($decision->allowed);
        $this->assertStringContainsString('exige write', implode(' ', data_get($decision->metadata, 'observations', [])));
    }

    public function test_patch_paths_are_observed_without_blocking(): void
    {
        $patch = "--- a/../escape.txt\n+++ b/../escape.txt\n@@ -0,0 +1 @@\n+escape\n";

        $decision = app(AiToolPermissionEngine::class)->authorize(ToolInvocation::make('git.apply_patch', $this->workspace, [
            'patch' => $patch,
        ], [
            'permission_mode' => 'write',
            'metadata' => ['approved' => true],
        ]));

        $this->assertTrue($decision->allowed);
        $this->assertStringContainsString('Path fora do workspace', implode(' ', data_get($decision->metadata, 'observations', [])));
    }

    public function test_existing_symlink_target_outside_workspace_is_observed_without_blocking(): void
    {
        $outside = sys_get_temp_dir().'/atlas-tool-permission-outside-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($outside);
        File::put($outside.'/secret.txt', 'secret');
        symlink($outside.'/secret.txt', $this->workspace.'/linked-secret.txt');

        try {
            $decision = app(AiToolPermissionEngine::class)->authorize(ToolInvocation::make('file.read', $this->workspace, [
                'path' => 'linked-secret.txt',
            ], [
                'permission_mode' => 'read',
            ]));
        } finally {
            File::deleteDirectory($outside);
        }

        $this->assertTrue($decision->allowed);
        $this->assertStringContainsString('Path fora do workspace', implode(' ', data_get($decision->metadata, 'observations', [])));
    }
}
