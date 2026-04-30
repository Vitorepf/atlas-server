<?php

namespace Tests\Unit;

use App\Models\AiJob;
use App\Services\Ai\AiPermissionEngine;
use Tests\TestCase;

class AiPermissionEngineTest extends TestCase
{
    public function test_codex_write_mode_resolves_workspace_and_sandbox(): void
    {
        config([
            'atlas.ai.workdir' => base_path(),
            'atlas.ai.tool_permissions.allowed_roots' => [base_path()],
            'atlas.ai.tool_permissions.codex_sandboxes.write' => 'workspace-write',
        ]);

        $decision = app(AiPermissionEngine::class)->authorizeJob(new AiJob([
            'payload' => [
                'workspace' => base_path(),
                'tool_permissions' => [
                    'mode' => 'write',
                ],
            ],
        ]), 'codex_cli');

        $this->assertTrue($decision->allowed);
        $this->assertSame('write', $decision->mode);
        $this->assertSame(realpath(base_path()), $decision->workspace);
        $this->assertSame('workspace-write', $decision->codexSandbox);
        $this->assertContains('write_workspace', $decision->capabilities);
    }

    public function test_write_mode_denies_unsandboxed_provider_by_default(): void
    {
        config([
            'atlas.ai.workdir' => base_path(),
            'atlas.ai.tool_permissions.allowed_roots' => [base_path()],
            'atlas.ai.tool_permissions.allow_unsandboxed_write' => false,
        ]);

        $decision = app(AiPermissionEngine::class)->authorizeJob(new AiJob([
            'payload' => [
                'workspace' => base_path(),
                'tool_permissions' => [
                    'mode' => 'write',
                ],
            ],
        ]), 'claude_cli');

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('nao possui sandbox', $decision->denialMessage());
    }

    public function test_danger_mode_requires_global_enablement(): void
    {
        config([
            'atlas.ai.workdir' => base_path(),
            'atlas.ai.tool_permissions.allowed_roots' => [base_path()],
            'atlas.ai.tool_permissions.allow_danger' => false,
        ]);

        $decision = app(AiPermissionEngine::class)->authorizeJob(new AiJob([
            'payload' => [
                'workspace' => base_path(),
                'tool_permissions' => [
                    'mode' => 'danger',
                    'confirmed' => true,
                ],
            ],
        ]), 'codex_cli');

        $this->assertFalse($decision->allowed);
        $this->assertStringContainsString('bloqueado por configuracao global', $decision->denialMessage());
    }
}
