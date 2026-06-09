<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasEvolutionScenarioExplorerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-loop-base-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/src', 0o755, true);
        mkdir($this->base.'/tests', 0o755, true);
        file_put_contents($this->base.'/src/Subject.php', "<?php\nfunction greet(){ return 'helo'; }\n");
        file_put_contents($this->base.'/tests/subject_test.php', "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (greet() !== 'hello') { fwrite(STDERR,'red'); exit(1);} echo 'green';\n");
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->base]))->run();
        parent::tearDown();
    }

    private function task(): array
    {
        return [
            'objective' => 'Make greet() return hello so the test passes. Only edit src/.',
            'base_workspace' => $this->base,
            'provider' => 'test_provider', // non-empty so config() is never touched in this pure unit test
            'allowed_files' => ['src/Subject.php'],
            'validation_commands' => ['php tests/subject_test.php'],
            'acceptance' => [
                'commands' => ['php tests/subject_test.php'],
                'allowed_globs' => ['src/**'],
                'frozen_globs' => ['tests/**'],
                'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
            ],
        ];
    }

    public function test_explores_n_scenarios_rejects_cheats_and_picks_the_one_valid_candidate(): void
    {
        // A fake engine that plays 3 distinct candidate strategies — the
        // senior-vs-junior search. The frozen judge must keep only the honest one.
        $fake = new class implements LoopExecutionDriver
        {
            public int $calls = 0;

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $n = $this->calls++;
                if ($n === 0) {
                    // CHEAT: weaken the FROZEN test so it "passes" — must be rejected
                    file_put_contents($workspace.'/tests/subject_test.php', "<?php\necho 'green';\n");
                } elseif ($n === 1) {
                    // HONEST minimal fix — the only valid candidate
                    file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");
                } else {
                    // correct fix BUT escapes scope — must be rejected
                    file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");
                    file_put_contents($workspace.'/sneaky.php', "<?php // out of scope\n");
                }

                return ['status' => 'completed'];
            }
        };

        $explorer = new AtlasEvolutionScenarioExplorer($fake, new AtlasEvolutionFrozenJudge);
        $result = $explorer->explore($this->task(), 3);

        $this->assertSame(3, $result['scenarios_explored']);
        $this->assertSame(1, $result['scenarios_accepted'], json_encode($result['attempts']));
        $this->assertNotNull($result['winner']);
        $this->assertTrue($result['winner']['verdict']['passed']);
        $this->assertSame('scn-2', $result['winner']['scenario_id']); // the honest one
        $this->assertSame(3, $fake->calls);

        // the two cheats were explored AND explicitly rejected with reasons
        $reasons = array_map(fn (array $a): string => (string) ($a['verdict']['details']['reason'] ?? ''), $result['attempts']);
        $this->assertContains('frozen_path_tampered', $reasons);
        $this->assertContains('out_of_scope_change', $reasons);
    }

    public function test_returns_no_winner_when_no_candidate_passes(): void
    {
        // engine that never actually fixes the target
        $fake = new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'nope'; }\n");

                return ['status' => 'completed'];
            }
        };

        $explorer = new AtlasEvolutionScenarioExplorer($fake, new AtlasEvolutionFrozenJudge);
        $result = $explorer->explore($this->task(), 2);

        $this->assertSame(2, $result['scenarios_explored']);
        $this->assertSame(0, $result['scenarios_accepted']);
        $this->assertNull($result['winner']);
        $this->assertSame('no_passing_candidate', $result['status']['reason']);
    }

    public function test_prefers_the_smaller_diff_when_two_candidates_tie_on_gate(): void
    {
        // both pass; one is minimal, the other adds noise — simplicity wins
        $fake = new class implements LoopExecutionDriver
        {
            public int $calls = 0;

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                if ($this->calls++ === 0) {
                    // bigger diff: fix + a verbose rewrite
                    file_put_contents($workspace.'/src/Subject.php', "<?php\n// verbose\n// many\n// lines\nfunction greet(){ return 'hello'; }\n");
                } else {
                    // minimal diff
                    file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");
                }

                return ['status' => 'completed'];
            }
        };

        $explorer = new AtlasEvolutionScenarioExplorer($fake, new AtlasEvolutionFrozenJudge);
        $result = $explorer->explore($this->task(), 2);

        $this->assertSame(2, $result['scenarios_accepted']);
        $this->assertNotNull($result['winner']);
        $this->assertSame('scn-2', $result['winner']['scenario_id']); // the minimal-diff one
    }

    public function test_deep_search_keeps_exploring_until_it_converges(): void
    {
        // identical minimal fix every time -> all pass, all tie -> no improvement
        $fake = new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");

                return ['status' => 'completed'];
            }
        };

        $task = $this->task();
        $task['min_scenarios'] = 2;
        $task['max_scenarios'] = 8;
        $task['search_patience'] = 3;
        unset($task['provider']);
        $task['provider'] = 'test_provider';

        // null scenarios => DEEP mode; params come from the task (so no config() call)
        $result = (new AtlasEvolutionScenarioExplorer($fake, new AtlasEvolutionFrozenJudge))->explore($task, null);

        // 1 winning scenario + 3 patience non-improving = 4 explored, then converge
        $this->assertSame(4, $result['scenarios_explored']);
        $this->assertTrue($result['status']['converged']);
        $this->assertNotNull($result['winner']);
    }

    public function test_deep_search_runs_to_the_cap_when_no_candidate_ever_passes(): void
    {
        $fake = new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'nope'; }\n");

                return ['status' => 'completed'];
            }
        };

        $task = $this->task();
        $task['min_scenarios'] = 2;
        $task['max_scenarios'] = 4;
        $task['search_patience'] = 2;

        $result = (new AtlasEvolutionScenarioExplorer($fake, new AtlasEvolutionFrozenJudge))->explore($task, null);

        // no winner => never converges => explores all the way to the hard cap
        $this->assertSame(4, $result['scenarios_explored']);
        $this->assertNull($result['winner']);
        $this->assertFalse($result['status']['converged']);
    }

    public function test_framework_clone_mode_uses_worktree_and_copies_local_support(): void
    {
        file_put_contents($this->base.'/.gitignore', "/vendor/\n.env.testing\n");
        mkdir($this->base.'/vendor', 0o755, true);
        file_put_contents($this->base.'/vendor/autoload.php', "<?php\n");
        file_put_contents($this->base.'/.env.testing', "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE=:memory:\n");
        foreach ([
            ['git', 'init', '-q'],
            ['git', 'add', '-A'],
            ['git', '-c', 'user.email=atlas-loop@local', '-c', 'user.name=Atlas Loop', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'base'],
        ] as $argv) {
            (new Process($argv, $this->base, null, null, 60.0))->run();
        }

        $fake = new class implements LoopExecutionDriver
        {
            /** @var array<string,bool> */
            public array $observed = [];

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $this->observed = [
                    'git_file' => is_file($workspace.'/.git'),
                    'vendor_copied' => is_file($workspace.'/vendor/autoload.php'),
                    'testing_env_copied' => is_file($workspace.'/.env.testing')
                        && str_contains((string) file_get_contents($workspace.'/.env.testing'), 'DB_DATABASE=:memory:'),
                ];
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");

                return ['status' => 'completed'];
            }
        };

        $task = $this->task();
        $task['scenario_clone_mode'] = 'worktree';

        $result = (new AtlasEvolutionScenarioExplorer($fake, new AtlasEvolutionFrozenJudge))->explore($task, 1);

        $this->assertSame(1, $result['scenarios_accepted'], json_encode($result));
        $this->assertTrue($fake->observed['git_file']);
        $this->assertTrue($fake->observed['vendor_copied']);
        $this->assertTrue($fake->observed['testing_env_copied']);
    }
}
