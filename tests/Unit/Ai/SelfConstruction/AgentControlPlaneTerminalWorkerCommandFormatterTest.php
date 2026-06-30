<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\TerminalWorkerBootstrap\AgentControlPlaneTerminalWorkerCommandFormatter;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive command / string-formatting concern extracted from
 * AgentControlPlaneTerminalWorkerBootstrapService into AgentControlPlaneTerminalWorkerCommandFormatter.
 *
 * Five methods migrated verbatim:
 *  - bootstrapCommand: full `atlas:ai:self-construction` CLI invocation (actor + flags + per-tag
 *    --queue-tag args + --json).
 *  - commandValue: shell-safe value emission (regex-pass values verbatim; everything else via
 *    escapeshellarg).
 *  - queueTagArgs: render the `--queue-tag=...` argument suffix for an existing command line
 *    (empty string when list is empty so the caller can always concatenate safely).
 *  - recommendedQueueTag: derive `terminal-loop-<slug>` queue tag from an actor string.
 *  - stringList: trim + non-empty filter + array_values on any list of mixed values.
 *
 * Pure / stateless / zero Laravel surface — pure PHPUnit suffices.
 */
final class AgentControlPlaneTerminalWorkerCommandFormatterTest extends TestCase
{
    private AgentControlPlaneTerminalWorkerCommandFormatter $fmt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fmt = new AgentControlPlaneTerminalWorkerCommandFormatter;
    }

    // --- commandValue ---------------------------------------------------

    public function test_command_value_passes_safe_chars_through_verbatim(): void
    {
        foreach (['hermes-1', 'a:b', 'a.b', 'a_b', 'a@b', 'a/b', 'terminal-loop-codex'] as $v) {
            $this->assertSame($v, $this->fmt->commandValue($v), "commandValue('$v') must emit verbatim");
        }
    }

    public function test_command_value_escapeshellargs_unsafe_chars(): void
    {
        // Any character outside the allow-list MUST be wrapped via escapeshellarg — protects the
        // shell from injection via a malicious actor / tag value.
        $this->assertSame("'a b'", $this->fmt->commandValue('a b'), 'space');
        $this->assertSame("'a;b'", $this->fmt->commandValue('a;b'), 'semicolon');
        $this->assertSame("'a&b'", $this->fmt->commandValue('a&b'), 'ampersand');
        $this->assertSame("'a\$b'", $this->fmt->commandValue('a$b'), 'dollar');
        $this->assertSame("'a|b'", $this->fmt->commandValue('a|b'), 'pipe');
        $this->assertSame("'a`b'", $this->fmt->commandValue('a`b'), 'backtick');
        $this->assertSame("'a\nb'", $this->fmt->commandValue("a\nb"), 'newline');
        $this->assertSame("'a\\b'", $this->fmt->commandValue('a\\b'), 'backslash');
    }

    public function test_command_value_handles_empty_string_via_escapeshellarg(): void
    {
        // Empty string does NOT match the allow-list (requires at least one char) — escapeshellarg
        // returns "''" so the value round-trips as an empty argument when the shell parses it.
        $this->assertSame("''", $this->fmt->commandValue(''));
    }

    // --- bootstrapCommand -------------------------------------------------

    public function test_bootstrap_command_assembles_full_cli_invocation_with_no_tags(): void
    {
        $cmd = $this->fmt->bootstrapCommand('hermes-1', 3, 7, []);

        $this->assertSame(
            'php artisan atlas:ai:self-construction '
            .'--agent-control-plane-terminal-worker-bootstrap-status '
            .'--actor=hermes-1 '
            .'--target-min-claimable-tasks=3 '
            .'--max-new-tasks=7 '
            .'--json',
            $cmd,
        );
    }

    public function test_bootstrap_command_emits_one_queue_tag_arg_per_tag(): void
    {
        $cmd = $this->fmt->bootstrapCommand('hermes-1', 1, 5, ['terminal-loop-foo', 'terminal-loop-bar']);

        $this->assertStringContainsString('--queue-tag=terminal-loop-foo ', $cmd);
        $this->assertStringContainsString('--queue-tag=terminal-loop-bar', $cmd);
        // --json is always last (single space separator, no trailing per-tag space).
        $this->assertStringEndsWith('--json', $cmd);
    }

    public function test_bootstrap_command_escapes_unsafe_actor_or_tag_values(): void
    {
        $cmd = $this->fmt->bootstrapCommand('actor with space', 0, 0, ['unsafe;tag']);

        // Both unsafe values must be escaped.
        $this->assertStringContainsString("--actor='actor with space'", $cmd);
        $this->assertStringContainsString("--queue-tag='unsafe;tag'", $cmd);
    }

    public function test_bootstrap_command_uses_integers_verbatim_for_count_flags(): void
    {
        // targetMin / maxNew are ints — they go through string interpolation, NOT commandValue.
        // Negative integers round-trip verbatim (the spawner decides their meaning upstream).
        $cmd = $this->fmt->bootstrapCommand('hermes-1', -1, 0, []);

        $this->assertStringContainsString('--target-min-claimable-tasks=-1', $cmd);
        $this->assertStringContainsString('--max-new-tasks=0', $cmd);
    }

    // --- queueTagArgs -----------------------------------------------------

    public function test_queue_tag_args_returns_empty_string_for_empty_list(): void
    {
        $this->assertSame('', $this->fmt->queueTagArgs([]));
    }

    public function test_queue_tag_args_emits_leading_space_and_one_arg_per_tag(): void
    {
        $out = $this->fmt->queueTagArgs(['a', 'b']);

        // The leading space lets the caller concatenate onto an existing command line.
        $this->assertSame(' --queue-tag=a --queue-tag=b', $out);
    }

    public function test_queue_tag_args_escapes_unsafe_values(): void
    {
        $out = $this->fmt->queueTagArgs(['safe', 'unsafe;value']);

        $this->assertStringContainsString("--queue-tag='unsafe;value'", $out);
        $this->assertStringContainsString('--queue-tag=safe ', $out);
    }

    // --- recommendedQueueTag ---------------------------------------------

    public function test_recommended_queue_tag_lowercases_and_slugs_actor(): void
    {
        $this->assertSame('terminal-loop-hermes-1', $this->fmt->recommendedQueueTag('Hermes-1'));
        $this->assertSame('terminal-loop-foo-bar', $this->fmt->recommendedQueueTag('Foo Bar'));
    }

    public function test_recommended_queue_tag_replaces_non_alphanum_runs_with_dash(): void
    {
        // The slug allow-list is `[A-Za-z0-9_.:-]+` — underscores, dots, colons ARE in the list,
        // so `a___b` stays verbatim and `a...b` stays verbatim. Only runs of chars OUTSIDE that
        // set (space, @, /, etc.) get collapsed to a single dash.
        $this->assertSame('terminal-loop-a-b', $this->fmt->recommendedQueueTag('a   b'), 'spaces');
        $this->assertSame('terminal-loop-a___b', $this->fmt->recommendedQueueTag('a___b'), 'underscores are kept');
        $this->assertSame('terminal-loop-a...b', $this->fmt->recommendedQueueTag('a...b'), 'dots are kept');
        $this->assertSame('terminal-loop-a-b', $this->fmt->recommendedQueueTag('a@@@b'), '@ collapsed');
        $this->assertSame('terminal-loop-a-b', $this->fmt->recommendedQueueTag('a/b'), '/ collapsed');
    }

    public function test_recommended_queue_tag_trims_leading_and_trailing_separators(): void
    {
        $this->assertSame('terminal-loop-foo', $this->fmt->recommendedQueueTag('---foo---'));
        $this->assertSame('terminal-loop-foo', $this->fmt->recommendedQueueTag('...foo...'));
    }

    public function test_recommended_queue_tag_falls_back_to_codex_for_empty_actor(): void
    {
        // All non-allowed chars / only separators => slug becomes '' => fallback 'codex'.
        $this->assertSame('terminal-loop-codex', $this->fmt->recommendedQueueTag(''));
        $this->assertSame('terminal-loop-codex', $this->fmt->recommendedQueueTag('   '));
        $this->assertSame('terminal-loop-codex', $this->fmt->recommendedQueueTag('---'));
    }

    // --- stringList -------------------------------------------------------

    public function test_string_list_trims_and_filters_empty_strings(): void
    {
        $out = $this->fmt->stringList(['  a  ', '', "\t\n", 'b', '   ']);

        $this->assertSame(['a', 'b'], $out);
    }

    public function test_string_list_casts_non_string_values_via_string_conversion(): void
    {
        // null casts to '' then filtered; true casts to '1'.
        $out = $this->fmt->stringList([1, 2.5, true, null]);

        $this->assertSame(['1', '2.5', '1'], $out);
    }

    public function test_string_list_handles_empty_input(): void
    {
        $this->assertSame([], $this->fmt->stringList([]));
    }

    public function test_string_list_returns_list_shape(): void
    {
        $out = $this->fmt->stringList(['c', 'a', 'b']);

        $this->assertSame([0, 1, 2], array_keys($out));
    }
}
