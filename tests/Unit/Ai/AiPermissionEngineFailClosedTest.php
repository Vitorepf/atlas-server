<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Models\AiJob;
use App\Services\Ai\Governance\AiPermissionEngine;
use Tests\TestCase;

final class AiPermissionEngineFailClosedTest extends TestCase
{
    public function test_read_mode_allowed_without_workspace_cert(): void
    {
        config([
            'atlas.ai.workdir' => base_path(),
            'atlas.ai.tool_permissions.allowed_roots' => [base_path()],
        ]);

        $decision = app(AiPermissionEngine::class)->authorizeJob(new AiJob([
            'payload' => [
                'workspace' => base_path(),
                'tool_permissions' => [
                    'mode' => 'read',
                ],
            ],
        ]), 'claude_cli');

        $this->assertTrue($decision->allowed);
        $this->assertSame('read', $decision->mode);
    }

    public function test_write_mode_denied_without_workspace_cert(): void
    {
        config([
            'atlas.ai.workdir' => base_path(),
            'atlas.ai.tool_permissions.allowed_roots' => [base_path()],
        ]);

        $decision = app(AiPermissionEngine::class)->authorizeJob(new AiJob([
            'payload' => [
                'workspace' => base_path(),
                'tool_permissions' => [
                    'mode' => 'write',
                ],
            ],
        ]), 'codex_cli');

        $this->assertFalse($decision->allowed);
        $this->assertSame('write', $decision->mode);
        $this->assertSame('workspace_cert_unavailable', $decision->metadata['fail_closed_reason'] ?? null);
        $this->assertNotEmpty($decision->denials);
        $this->assertStringContainsStringIgnoringCase('workspace-cert', $decision->denials[0]);
        $this->assertArrayHasKey('remediation', $decision->metadata);
    }

    public function test_danger_mode_denied_when_cert_mode_is_only_write(): void
    {
        config([
            'atlas.ai.workdir' => base_path(),
            'atlas.ai.tool_permissions.allowed_roots' => [base_path()],
        ]);

        $decision = app(AiPermissionEngine::class)->authorizeJob(new AiJob([
            'payload' => [
                'workspace' => base_path(),
                'tool_permissions' => [
                    'mode' => 'danger',
                    'workspace_cert' => ['status' => 'available', 'mode' => 'write'],
                ],
            ],
        ]), 'codex_cli');

        $this->assertFalse($decision->allowed);
        $this->assertSame('danger', $decision->mode);
        $this->assertSame('workspace_cert_insufficient_mode', $decision->metadata['fail_closed_reason'] ?? null);
        $this->assertNotEmpty($decision->denials);
    }

    public function test_write_mode_allowed_with_available_write_cert(): void
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
                    'workspace_cert' => ['status' => 'available', 'mode' => 'write'],
                ],
            ],
        ]), 'codex_cli');

        $this->assertTrue($decision->allowed);
        $this->assertSame('write', $decision->mode);
        $this->assertArrayNotHasKey('fail_closed_reason', $decision->metadata);
    }

    public function test_danger_mode_allowed_with_available_danger_cert(): void
    {
        config([
            'atlas.ai.workdir' => base_path(),
            'atlas.ai.tool_permissions.allowed_roots' => [base_path()],
        ]);

        $decision = app(AiPermissionEngine::class)->authorizeJob(new AiJob([
            'payload' => [
                'workspace' => base_path(),
                'tool_permissions' => [
                    'mode' => 'danger',
                    'workspace_cert' => ['status' => 'available', 'mode' => 'danger'],
                ],
            ],
        ]), 'codex_cli');

        $this->assertTrue($decision->allowed);
        $this->assertSame('danger', $decision->mode);
        $this->assertArrayNotHasKey('fail_closed_reason', $decision->metadata);
    }

    public function test_cert_status_denied_is_treated_as_unavailable(): void
    {
        config([
            'atlas.ai.workdir' => base_path(),
            'atlas.ai.tool_permissions.allowed_roots' => [base_path()],
        ]);

        $decision = app(AiPermissionEngine::class)->authorizeJob(new AiJob([
            'payload' => [
                'workspace' => base_path(),
                'tool_permissions' => [
                    'mode' => 'write',
                    'workspace_cert' => ['status' => 'denied', 'mode' => 'write'],
                ],
            ],
        ]), 'codex_cli');

        $this->assertFalse($decision->allowed);
        $this->assertSame('workspace_cert_unavailable', $decision->metadata['fail_closed_reason'] ?? null);
    }
}
