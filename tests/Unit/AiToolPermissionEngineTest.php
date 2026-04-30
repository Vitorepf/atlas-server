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

    public function test_write_tool_requires_human_approval(): void
    {
        $decision = app(AiToolPermissionEngine::class)->authorize(ToolInvocation::make('file.write', $this->workspace, [
            'path' => 'note.txt',
        ], [
            'permission_mode' => 'write',
        ]));

        $this->assertFalse($decision->allowed);
        $this->assertTrue($decision->requiresApproval);
        $this->assertStringContainsString('aprovacao humana', $decision->message());
    }

    public function test_dangerous_shell_is_blocked_when_global_danger_disabled(): void
    {
        $decision = app(AiToolPermissionEngine::class)->authorize(ToolInvocation::make('shell.run', $this->workspace, [
            'command' => 'rm -rf storage',
        ], [
            'permission_mode' => 'danger',
            'metadata' => ['approved' => true],
        ]));

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('bloqueado por configuracao global', $decision->message());
    }

    public function test_write_capable_shell_requires_write_permission(): void
    {
        $decision = app(AiToolPermissionEngine::class)->authorize(ToolInvocation::make('shell.run', $this->workspace, [
            'command' => 'touch generated.txt',
        ], [
            'permission_mode' => 'read',
        ]));

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('exige write', $decision->message());
    }

    public function test_patch_paths_are_validated_before_approval(): void
    {
        $patch = "--- a/../escape.txt\n+++ b/../escape.txt\n@@ -0,0 +1 @@\n+escape\n";

        $decision = app(AiToolPermissionEngine::class)->authorize(ToolInvocation::make('git.apply_patch', $this->workspace, [
            'patch' => $patch,
        ], [
            'permission_mode' => 'write',
            'metadata' => ['approved' => true],
        ]));

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('Path fora do workspace', $decision->message());
    }

    public function test_existing_symlink_target_outside_workspace_is_denied(): void
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

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('Path fora do workspace', $decision->message());
    }
}
