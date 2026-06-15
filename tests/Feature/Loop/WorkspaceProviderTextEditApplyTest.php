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
}
