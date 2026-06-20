<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\WorkspaceProviderLoopExecutionDriver;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE engine-independence — driver seam. Proves that when a TEXT/HTTP provider returns
 * ONLY a completion (no filesystem edits → changed_files empty) whose body carries a unified
 * diff, the loop driver APPLIES that diff to the scenario workspace and surfaces the real
 * changed files. This is the exact failure mode of live MiniMax campaign 019ecd81 (every
 * scenario diff-0 because the model's diff was never applied) — frozen closed here.
 */
final class WorkspaceProviderTextEditApplyTest extends TestCase
{
    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.loop.default_provider', 'minimax_m27');
        config()->set('atlas.loop.text_provider_edit_apply', true);
        config()->set('atlas.code_graph.auto_context', false);

        $this->workspace = sys_get_temp_dir().'/atlas-text-apply-'.bin2hex(random_bytes(5));
        mkdir($this->workspace, 0o755, true);
        $this->git(['git', 'init', '-q']);
        $this->git(['git', 'config', 'user.email', 't@local']);
        $this->git(['git', 'config', 'user.name', 'T']);
        file_put_contents($this->workspace.'/Subject.php', "<?php\n\nreturn 1;\n");
        file_put_contents($this->workspace.'/AGENTS.md', "provider projection\n");
        file_put_contents($this->workspace.'/CLAUDE.md', "provider projection\n");
        $this->git(['git', 'add', '-A']);
        $this->git(['git', 'commit', '-q', '-m', 'seed']);
    }

    protected function tearDown(): void
    {
        if ($this->workspace !== '' && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace]))->run();
        }
        parent::tearDown();
    }

    /** @param list<string> $argv */
    private function git(array $argv): void
    {
        (new Process($argv, $this->workspace, null, null, 30.0))->run();
    }

    private function realDiffChanging(string $relpath, string $newContents): string
    {
        file_put_contents($this->workspace.'/'.$relpath, $newContents);
        $diff = new Process(['git', 'diff', '--no-ext-diff'], $this->workspace, null, null, 30.0);
        $diff->run();
        $this->git(['git', 'checkout', '--', $relpath]);

        return (string) $diff->getOutput();
    }

    private function bindTextProviderRouter(string $stdout): void
    {
        $fake = new class($stdout) extends AtlasForgeProviderInvocationDriverRouter
        {
            public function __construct(private readonly string $stdout) {}

            public function isConfigured(?string $provider): bool
            {
                return true;
            }

            public function invoke(?string $provider, ?string $model, array $prompt, array $context = []): array
            {
                // A text/HTTP engine: it "ran", reported tokens, edited NOTHING on disk, and
                // delivered its whole change as a unified diff in stdout.
                return [
                    'provider_called' => true,
                    'changed_files' => [],
                    'exit_code' => 0,
                    'stdout' => $this->stdout,
                    'tokens_used' => 1234,
                ];
            }
        };
        $this->app->instance(AtlasForgeProviderInvocationDriverRouter::class, $fake);
    }

    private function bindProjectionNoiseRouter(): void
    {
        $fake = new class extends AtlasForgeProviderInvocationDriverRouter
        {
            public function __construct() {}

            public function isConfigured(?string $provider): bool
            {
                return true;
            }

            public function invoke(?string $provider, ?string $model, array $prompt, array $context = []): array
            {
                $cwd = (string) ($context['cwd'] ?? '');
                file_put_contents($cwd.'/Subject.php', "<?php\n\nreturn 42;\n");
                file_put_contents($cwd.'/AGENTS.md', "provider projection refreshed\n");
                file_put_contents($cwd.'/CLAUDE.md', "provider projection refreshed\n");

                return [
                    'provider_called' => true,
                    'changed_files' => ['Subject.php', 'AGENTS.md', 'CLAUDE.md'],
                    'exit_code' => 0,
                ];
            }
        };
        $this->app->instance(AtlasForgeProviderInvocationDriverRouter::class, $fake);
    }

    private function bindPromptCapturingRouter(): object
    {
        $box = new class
        {
            /** @var array<string,mixed>|null */
            public ?array $prompt = null;
        };

        $fake = new class($box) extends AtlasForgeProviderInvocationDriverRouter
        {
            public function __construct(private readonly object $box) {}

            public function isConfigured(?string $provider): bool
            {
                return true;
            }

            public function invoke(?string $provider, ?string $model, array $prompt, array $context = []): array
            {
                $this->box->prompt = $prompt;

                return [
                    'provider_called' => true,
                    'changed_files' => ['Subject.php'],
                    'exit_code' => 0,
                ];
            }
        };

        $this->app->instance(AtlasForgeProviderInvocationDriverRouter::class, $fake);

        return $box;
    }

    public function test_text_provider_diff_is_applied_and_changed_files_surface(): void
    {
        $diff = $this->realDiffChanging('Subject.php', "<?php\n\nreturn 99;\n");
        $this->bindTextProviderRouter("Done — here is my patch:\n\n```diff\n".$diff."\n```\n");

        $result = app(WorkspaceProviderLoopExecutionDriver::class)
            ->attempt('loop', $this->workspace, 'change the return value to 99', [], []);

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($result['provider_invoked']);
        $this->assertTrue($result['edits_applied_from_text'] ?? false, 'the text diff must be applied');
        $this->assertSame(['Subject.php'], $result['changed_files']);
        $this->assertFalse($result['zero_diff_retry'], 'a successfully applied diff is NOT a zero-diff');
        $this->assertStringContainsString('return 99;', (string) file_get_contents($this->workspace.'/Subject.php'));
    }

    public function test_provider_projection_noise_is_reset_before_changed_files_surface(): void
    {
        config()->set('atlas.loop.provider_projection_noise_reset', true);
        config()->set('atlas.loop.provider_projection_noise_files', ['AGENTS.md', 'CLAUDE.md']);
        $this->bindProjectionNoiseRouter();

        $result = app(WorkspaceProviderLoopExecutionDriver::class)->attempt(
            'loop',
            $this->workspace,
            'change Subject.php',
            ['allowed_files=Subject.php'],
            ['provider_choice' => 'hermes_cli'],
        );

        $this->assertSame(['Subject.php'], $result['changed_files']);
        $this->assertTrue($result['provider_projection_noise_reset']);
        $this->assertSame(['AGENTS.md', 'CLAUDE.md'], $result['provider_projection_noise_files']);
        $this->assertSame("provider projection\n", (string) file_get_contents($this->workspace.'/AGENTS.md'));
        $this->assertSame("provider projection\n", (string) file_get_contents($this->workspace.'/CLAUDE.md'));
        $this->assertStringContainsString('return 42;', (string) file_get_contents($this->workspace.'/Subject.php'));
    }

    public function test_cli_provider_prompt_does_not_receive_text_only_edit_protocol(): void
    {
        config()->set('atlas.loop.text_provider_edit_apply', true);
        config()->set('atlas.loop.text_provider_edit_apply_providers', ['minimax_m27']);
        $box = $this->bindPromptCapturingRouter();

        app(WorkspaceProviderLoopExecutionDriver::class)->attempt(
            'loop',
            $this->workspace,
            'edit Subject.php',
            ['allowed_files=Subject.php'],
            ['provider_choice' => 'hermes_cli'],
        );

        $text = (string) data_get($box->prompt, 'text', '');
        $this->assertStringContainsString('Make the change directly by editing files in place.', $text);
        $this->assertStringContainsString('if a native patch/write_file/edit tool refuses the path as sensitive', $text);
        $this->assertStringNotContainsString('OUTPUT PROTOCOL', $text);
        $this->assertStringNotContainsString('NO filesystem access', $text);
        $this->assertStringNotContainsString('CURRENT FILE CONTENTS (edit these exactly):', $text);
    }

    public function test_text_provider_prompt_keeps_full_file_edit_protocol_and_current_contents(): void
    {
        config()->set('atlas.loop.text_provider_edit_apply', true);
        config()->set('atlas.loop.text_provider_edit_apply_providers', ['minimax_m27']);
        $box = $this->bindPromptCapturingRouter();

        app(WorkspaceProviderLoopExecutionDriver::class)->attempt(
            'loop',
            $this->workspace,
            'edit Subject.php',
            ['allowed_files=Subject.php'],
            ['provider_choice' => 'minimax_m27'],
        );

        $text = (string) data_get($box->prompt, 'text', '');
        $this->assertStringContainsString('OUTPUT PROTOCOL', $text);
        $this->assertStringContainsString('NO filesystem access', $text);
        $this->assertStringContainsString('CURRENT FILE CONTENTS (edit these exactly):', $text);
        $this->assertStringContainsString('*** ATLAS_FILE: Subject.php ***', $text);
        $this->assertStringContainsString("<?php\n\nreturn 1;", $text);
        $this->assertStringContainsString('*** ATLAS_END ***', $text);
    }

    public function test_text_provider_with_no_diff_stays_zero_diff(): void
    {
        config()->set('atlas.loop.zero_diff_retry', false);
        $this->bindTextProviderRouter('I think the code is already correct; no change needed.');

        $result = app(WorkspaceProviderLoopExecutionDriver::class)
            ->attempt('loop', $this->workspace, 'change something', [], []);

        $this->assertSame([], $result['changed_files'], 'prose with no diff changes nothing');
        $this->assertFalse($result['edits_applied_from_text'] ?? false);
        $this->assertStringContainsString('return 1;', (string) file_get_contents($this->workspace.'/Subject.php'));
    }

    public function test_armed_iterate_against_judge_reinvokes_when_command_passes_but_judge_rejects(): void
    {
        // ACDE Tier-0 #2: a candidate whose raw command exits 0 but is NOT diff-earned (the command does
        // not depend on the change) must NOT be treated as green — the judge rejects it, so judge-mode
        // iterate keeps re-prompting instead of stopping at the fake exit-0 (the proxy gap). Armed here.
        config()->set('atlas.loop.iterate_to_green_enabled', true);
        config()->set('atlas.loop.iterate_to_green_max', 2);
        config()->set('atlas.loop.iterate_against_judge', true);
        config()->set('atlas.loop.zero_diff_retry', false);

        $always = 'php -r \'exit(0);\''; // passes regardless of the diff => never diff-earned
        $acceptance = [
            'commands' => [$always],
            'allowed_globs' => ['Subject.php'],
            'frozen_globs' => [],
            'metric_kind' => 'gate',
            'revert_recheck' => true,
        ];

        $box = new class
        {
            public int $calls = 0;
        };
        $fake = new class($box) extends AtlasForgeProviderInvocationDriverRouter
        {
            public function __construct(private readonly object $box) {}

            public function isConfigured(?string $provider): bool
            {
                return true;
            }

            public function invoke(?string $provider, ?string $model, array $prompt, array $context = []): array
            {
                $n = ++$this->box->calls;

                // a distinct in-scope edit each call (so a fresh diff always exists) that the command ignores
                return [
                    'provider_called' => true,
                    'changed_files' => [],
                    'exit_code' => 0,
                    'stdout' => "*** ATLAS_FILE: Subject.php ***\n<?php\n\n// edit {$n}\nreturn 1;\n*** ATLAS_END ***",
                ];
            }
        };
        $this->app->instance(AtlasForgeProviderInvocationDriverRouter::class, $fake);

        app(WorkspaceProviderLoopExecutionDriver::class)->attempt(
            'loop',
            $this->workspace,
            'edit Subject.php',
            ['allowed_files=Subject.php', 'validation_command='.$always],
            ['provider_choice' => 'minimax_m27', 'acceptance' => $acceptance],
        );

        // The fake exit-0 would stop a raw-proxy iterate at 1 call; judge-mode must re-invoke (not diff-earned).
        $this->assertGreaterThan(1, $box->calls, 'judge-mode iterate must re-prompt past a fake exit-0 the judge rejects');
    }
}
