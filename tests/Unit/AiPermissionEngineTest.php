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

    public function test_write_mode_is_observability_only_for_unsandboxed_provider(): void
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

        $this->assertTrue($decision->allowed);
        $this->assertTrue($decision->metadata['observability_only']);
        $this->assertContains('Provider claude_cli executando sem sandbox de escrita controlado pelo Atlas.', $decision->metadata['observations']);
    }

    public function test_danger_mode_no_longer_requires_global_enablement_or_confirmation(): void
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
                ],
            ],
        ]), 'codex_cli');

        $this->assertTrue($decision->allowed);
        $this->assertSame('danger', $decision->mode);
        $this->assertTrue($decision->metadata['observability_only']);
        $this->assertSame([], $decision->denials);
    }

    public function test_gemini_write_mode_is_allowed_like_other_providers(): void
    {
        config([
            'atlas.ai.workdir' => base_path(),
            'atlas.ai.tool_permissions.allowed_roots' => [base_path()],
            'atlas.ai.tool_permissions.allow_unsandboxed_write' => true,
        ]);

        $decision = app(AiPermissionEngine::class)->authorizeJob(new AiJob([
            'payload' => [
                'workspace' => base_path(),
                'tool_permissions' => [
                    'mode' => 'write',
                    'allow_unsandboxed_provider' => true,
                ],
            ],
        ]), 'gemini_cli');

        $this->assertTrue($decision->allowed);
        $this->assertSame('write', $decision->mode);
        $this->assertSame('gemini_cli', $decision->metadata['provider']);
    }
}
