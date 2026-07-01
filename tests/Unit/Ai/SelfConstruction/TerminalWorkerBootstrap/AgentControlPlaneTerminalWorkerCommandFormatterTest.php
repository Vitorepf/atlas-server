<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TerminalWorkerBootstrap;

use App\Services\Ai\SelfConstruction\TerminalWorkerBootstrap\AgentControlPlaneTerminalWorkerCommandFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AgentControlPlaneTerminalWorkerCommandFormatterTest extends TestCase
{
    private AgentControlPlaneTerminalWorkerCommandFormatter $fmt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fmt = new AgentControlPlaneTerminalWorkerCommandFormatter;
    }

    // ── AC: actors and queue tags are shell-quoted safely ──────────────────────

    public function test_actor_and_queue_tag_values_are_shell_quoted_safely(): void
    {
        $cmd = $this->fmt->bootstrapCommand('actor;with|danger', 1, 1, ['tag&with$risk']);

        $this->assertStringContainsString("--actor='actor;with|danger'", $cmd);
        $this->assertStringContainsString("--queue-tag='tag&with\$risk'", $cmd);
    }

    public function test_safe_actor_and_tag_values_pass_through_verbatim(): void
    {
        $cmd = $this->fmt->bootstrapCommand('hermes-1', 1, 1, ['terminal-loop-hermes-1']);

        $this->assertStringContainsString('--actor=hermes-1', $cmd);
        $this->assertStringContainsString('--queue-tag=terminal-loop-hermes-1', $cmd);
    }

    // ── AC: shell-control characters are rejected ──────────────────────────────

    public function test_null_byte_in_command_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fmt->commandValue("actor\x00injected");
    }

    public function test_del_control_character_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fmt->commandValue("actor\x7f");
    }

    public function test_bootstrap_command_rejects_actor_containing_null_byte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fmt->bootstrapCommand("hermes\x00", 1, 1, []);
    }

    public function test_bootstrap_command_rejects_queue_tag_containing_null_byte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fmt->bootstrapCommand('hermes-1', 1, 1, ["bad\x00tag"]);
    }

    // ── AC: generated bootstrap commands keep PHP path and queue tags deterministic ──

    public function test_bootstrap_command_uses_default_php_binary_deterministically(): void
    {
        $cmd = $this->fmt->bootstrapCommand('hermes-1', 1, 1, []);

        $this->assertStringStartsWith('php artisan atlas:ai:self-construction', $cmd);
    }

    public function test_bootstrap_command_honors_injected_php_binary_path(): void
    {
        $fmt = new AgentControlPlaneTerminalWorkerCommandFormatter('/usr/bin/php8.3');
        $cmd = $fmt->bootstrapCommand('hermes-1', 1, 1, []);

        $this->assertStringStartsWith('/usr/bin/php8.3 artisan atlas:ai:self-construction', $cmd);
    }

    public function test_bootstrap_command_is_byte_identical_across_repeated_calls(): void
    {
        $a = $this->fmt->bootstrapCommand('hermes-1', 2, 4, ['terminal-loop-a', 'terminal-loop-b']);
        $b = $this->fmt->bootstrapCommand('hermes-1', 2, 4, ['terminal-loop-a', 'terminal-loop-b']);

        $this->assertSame($a, $b);
    }

    public function test_bootstrap_command_preserves_queue_tag_order(): void
    {
        $cmd = $this->fmt->bootstrapCommand('hermes-1', 1, 1, ['z-tag', 'a-tag']);

        $this->assertLessThan(
            strpos($cmd, '--queue-tag=a-tag'),
            strpos($cmd, '--queue-tag=z-tag'),
            'queue tags must appear in the order supplied, not re-sorted',
        );
    }
}
