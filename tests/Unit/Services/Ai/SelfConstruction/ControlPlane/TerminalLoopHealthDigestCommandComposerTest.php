<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\TerminalLoopHealthDigestCommandComposer;
use Tests\TestCase;

/**
 * Focused contract test: proves the composer sanitizes actor/queue-tag tokens against
 * shell-injection-prone characters, omits blank queue tags instead of defaulting them,
 * always runs through the exact Homebrew PHP binary, and always exposes the command
 * catalogue the worker/operator terminal loop depends on.
 */
final class TerminalLoopHealthDigestCommandComposerTest extends TestCase
{
    private function composer(): TerminalLoopHealthDigestCommandComposer
    {
        return new TerminalLoopHealthDigestCommandComposer;
    }

    public function test_actor_with_shell_injection_prone_characters_is_sanitized(): void
    {
        $cmds = $this->composer()->commands('alice; rm -rf /', 5, 10, []);

        self::assertStringNotContainsString(';', $cmds['terminal_loop_health_digest']);
        $actorToken = explode(' ', explode('--actor=', $cmds['terminal_loop_health_digest'])[1])[0];
        self::assertStringNotContainsString(' ', $actorToken);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.:@\/-]+$/', $actorToken);
    }

    public function test_queue_tag_with_shell_injection_prone_characters_is_sanitized(): void
    {
        $cmds = $this->composer()->commands('alice', 5, 10, ['tag`whoami`']);

        self::assertStringNotContainsString('`', $cmds['terminal_loop_health_digest']);
    }

    public function test_blank_queue_tags_are_omitted_not_defaulted(): void
    {
        $withBlank = $this->composer()->commands('alice', 5, 10, ['', '  ', 'real-tag']);
        $withoutBlank = $this->composer()->commands('alice', 5, 10, ['real-tag']);

        self::assertSame($withoutBlank['terminal_loop_health_digest'], $withBlank['terminal_loop_health_digest']);
        self::assertSame(1, substr_count($withBlank['terminal_loop_health_digest'], '--queue-tag='));
    }

    public function test_all_blank_queue_tags_yield_empty_tag_args(): void
    {
        self::assertSame('', $this->composer()->queueTagArgs(['', '   ']));
    }

    public function test_every_command_runs_through_the_exact_homebrew_php_binary(): void
    {
        $cmds = $this->composer()->commands('alice', 5, 10, ['tag1']);

        foreach ($cmds as $key => $cmd) {
            self::assertStringStartsWith('/opt/homebrew/bin/php ', $cmd, "Command $key must start with the exact Homebrew PHP binary");
        }
    }

    public function test_health_replenish_sweep_queued_targets_lease_and_eligibility_commands_are_present(): void
    {
        $cmds = $this->composer()->commands('alice', 5, 10, []);

        self::assertArrayHasKey('terminal_loop_health_digest', $cmds);
        self::assertArrayHasKey('replenish_tasks', $cmds);
        self::assertArrayHasKey('sweep_malformed', $cmds);
        self::assertArrayHasKey('queued_targets', $cmds);
        self::assertArrayHasKey('inspect_or_recover_leases', $cmds);
        self::assertArrayHasKey('inspect_leases', $cmds);
        self::assertArrayHasKey('worker_task_eligibility_certification', $cmds);
    }
}
