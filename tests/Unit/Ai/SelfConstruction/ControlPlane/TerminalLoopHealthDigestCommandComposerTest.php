<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\TerminalLoopHealthDigestCommandComposer;
use Tests\TestCase;

class TerminalLoopHealthDigestCommandComposerTest extends TestCase
{
    private function composer(): TerminalLoopHealthDigestCommandComposer
    {
        return new TerminalLoopHealthDigestCommandComposer;
    }

    public function test_safe_command_token_keeps_safe_chars(): void
    {
        self::assertSame('alice', $this->composer()->safeCommandToken('alice', 'operator'));
    }

    public function test_safe_command_token_replaces_unsafe_with_dash(): void
    {
        self::assertSame('al-ce', $this->composer()->safeCommandToken('al ce', 'operator'));
        self::assertSame('al-ce', $this->composer()->safeCommandToken('al;ce', 'operator'));
    }

    public function test_safe_command_token_trims_dashes(): void
    {
        self::assertSame('alice', $this->composer()->safeCommandToken('--alice--', 'operator'));
    }

    public function test_safe_command_token_returns_default_when_empty(): void
    {
        self::assertSame('operator', $this->composer()->safeCommandToken('', 'operator'));
        self::assertSame('operator', $this->composer()->safeCommandToken('!!!', 'operator'));
    }

    public function test_safe_command_token_preserves_path_chars(): void
    {
        self::assertSame('app/Foo.php', $this->composer()->safeCommandToken('app/Foo.php', 'default'));
        self::assertSame('user@example.com', $this->composer()->safeCommandToken('user@example.com', 'default'));
    }

    public function test_queue_tag_args_empty(): void
    {
        self::assertSame('', $this->composer()->queueTagArgs([]));
    }

    public function test_queue_tag_args_single(): void
    {
        self::assertSame(' --queue-tag=foo', $this->composer()->queueTagArgs(['foo']));
    }

    public function test_queue_tag_args_multiple(): void
    {
        self::assertSame(' --queue-tag=foo --queue-tag=bar', $this->composer()->queueTagArgs(['foo', 'bar']));
    }

    public function test_queue_tag_args_sanitizes_tags(): void
    {
        self::assertSame(' --queue-tag=al-ce', $this->composer()->queueTagArgs(['al ce']));
    }

    public function test_commands_returns_all_keys(): void
    {
        $cmds = $this->composer()->commands('alice', 5, 10, []);

        $expected = [
            'preview_bootstrap', 'execute_bootstrap', 'replenish_tasks',
            'inspect_or_recover_leases', 'inspect_queue', 'inspect_leases',
            'terminal_loop_health_digest', 'worker_task_eligibility_certification',
            'multi_agent_certification',
        ];

        foreach ($expected as $key) {
            self::assertArrayHasKey($key, $cmds);
        }
    }

    public function test_commands_includes_actor(): void
    {
        $cmds = $this->composer()->commands('alice', 5, 10, []);
        self::assertStringContainsString('--actor=alice', $cmds['preview_bootstrap']);
    }

    public function test_commands_sanitizes_actor(): void
    {
        $cmds = $this->composer()->commands('al ce; rm', 5, 10, []);
        self::assertStringContainsString('--actor=al-ce', $cmds['preview_bootstrap']);
        self::assertStringNotContainsString(';', $cmds['preview_bootstrap']);
    }

    public function test_commands_includes_queue_tags(): void
    {
        $cmds = $this->composer()->commands('alice', 5, 10, ['tag1']);
        self::assertStringContainsString('--queue-tag=tag1', $cmds['preview_bootstrap']);
    }

    public function test_commands_multi_agent_uses_hardcoded_params(): void
    {
        $cmds = $this->composer()->commands('alice', 5, 10, []);
        self::assertStringContainsString('--agent-count=6', $cmds['multi_agent_certification']);
        self::assertStringContainsString('--cycles=2', $cmds['multi_agent_certification']);
    }

    public function test_commands_all_include_json(): void
    {
        $cmds = $this->composer()->commands('alice', 5, 10, []);
        foreach ($cmds as $key => $cmd) {
            self::assertStringEndsWith('--json', $cmd, "Command $key must end with --json");
        }
    }
}