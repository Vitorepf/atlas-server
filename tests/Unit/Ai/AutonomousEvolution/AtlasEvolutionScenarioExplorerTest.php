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

    public function test_cross_provider_portfolio_rotates_the_engine_across_attempts(): void
    {
        // Lever 4: the N best-of-N attempts rotate across a provider portfolio (decorrelated by engine).
        // The fake driver records the provider_choice surface hint per call; assert it cycles a,b,c.
        $fake = new class implements LoopExecutionDriver
        {
            /** @var list<string> */
            public array $providers = [];

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $this->providers[] = (string) ($surfaceHints['provider_choice'] ?? '');
                // honest minimal fix so every attempt passes (we only care about provider rotation here)
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");

                return ['status' => 'completed'];
            }
        };

        $task = $this->task();
        // keep task['provider'] set so resolveProvider never reads config (pure unit test, no container);
        // scenario_providers is step-1 in the portfolio so it governs the rotation regardless.
        $task['scenario_providers'] = ['prov_a', 'prov_b', 'prov_c'];

        $result = (new AtlasEvolutionScenarioExplorer($fake, new AtlasEvolutionFrozenJudge))->explore($task, 4);

        $this->assertSame(4, $result['scenarios_explored']);
        $this->assertSame(['prov_a', 'prov_b', 'prov_c', 'prov_a'], $fake->providers, 'attempts rotate the portfolio and cycle back');
        // each attempt records the engine it ran on (audit trail for the A/B + bandit)
        $this->assertSame(['prov_a', 'prov_b', 'prov_c', 'prov_a'], array_map(
            static fn (array $a): string => (string) $a['provider'],
            $result['attempts'],
        ));
    }

    public function test_single_provider_is_byte_identical_no_portfolio(): void
    {
        // With a single pinned provider and no portfolio, every attempt uses it (today's behavior).
        $fake = new class implements LoopExecutionDriver
        {
            /** @var list<string> */
            public array $providers = [];

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $this->providers[] = (string) ($surfaceHints['provider_choice'] ?? '');
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");

                return ['status' => 'completed'];
            }
        };

        $result = (new AtlasEvolutionScenarioExplorer($fake, new AtlasEvolutionFrozenJudge))->explore($this->task(), 3);

        $this->assertSame(3, $result['scenarios_explored']);
        $this->assertSame(['test_provider', 'test_provider', 'test_provider'], $fake->providers);
    }

    /**
     * A driver that records the strategy mandate (the "STRATEGY X" marker) it was handed each call,
     * so the test can prove the N attempts were DECORRELATED by distinct mandates, not re-rolls.
     */
    private function strategyRecordingDriver(): LoopExecutionDriver
    {
        return new class implements LoopExecutionDriver
        {
            /** @var list<string> */
            public array $markers = [];

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $marker = '';
                if (preg_match('/STRATEGY [A-H]/', $intent, $m) === 1) {
                    $marker = $m[0];
                }
                $this->markers[] = $marker; // '' == the unconstrained baseline (index 0)
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");

                return ['status' => 'completed'];
            }
        };
    }

    public function test_deep_strategy_portfolio_widens_decorrelation_to_nine_distinct_mandates(): void
    {
        // ACDE direction-(a): on a single weak engine (no temp/seed), strategy diversity IS the only
        // decorrelation lever. With the flag on, widening to 9 scenarios buys 9 genuinely-distinct
        // mandates (baseline + A..D + the four extensions) instead of re-rolling the same 5.
        $fake = $this->strategyRecordingDriver();

        $task = $this->task();
        $task['deep_strategy_portfolio'] = true;

        $result = (new AtlasEvolutionScenarioExplorer($fake, new AtlasEvolutionFrozenJudge))->explore($task, 9);

        $this->assertSame(9, $result['scenarios_explored']);

        $keys = array_map(static fn (array $a): string => (string) $a['strategy_key'], $result['attempts']);
        $this->assertSame(
            ['baseline', 'surgical', 'clean_alternative', 'root_cause', 'simplify', 'guard_first', 'extract_helper', 'type_driven', 'invert_flatten'],
            $keys,
            'all 9 scenarios get a distinct mandate key in pool order',
        );
        $this->assertCount(9, array_unique($keys), 'no key repeats across the 9-wide search');

        // the four extension mandates were actually handed to the engine (decorrelation by TEXT, not just key)
        $this->assertContains('STRATEGY E', $fake->markers);
        $this->assertContains('STRATEGY F', $fake->markers);
        $this->assertContains('STRATEGY G', $fake->markers);
        $this->assertContains('STRATEGY H', $fake->markers);
    }

    public function test_default_strategy_pool_is_byte_identical_at_five_without_the_flag(): void
    {
        // OFF (default): the pool stays the base 5, so a 9-wide search cycles back — exactly today's
        // behavior. Indices 0..4 unchanged; none of the extension mandates ever appear.
        $fake = $this->strategyRecordingDriver();

        $result = (new AtlasEvolutionScenarioExplorer($fake, new AtlasEvolutionFrozenJudge))->explore($this->task(), 9);

        $this->assertSame(9, $result['scenarios_explored']);

        $keys = array_map(static fn (array $a): string => (string) $a['strategy_key'], $result['attempts']);
        $this->assertSame(
            ['baseline', 'surgical', 'clean_alternative', 'root_cause', 'simplify', 'baseline', 'surgical', 'clean_alternative', 'root_cause'],
            $keys,
            'without the flag the 5-mandate pool cycles (scenarios 5..8 repeat 0..3)',
        );
        $this->assertSame(['', 'STRATEGY A', 'STRATEGY B', 'STRATEGY C', 'STRATEGY D'], array_values(array_unique($fake->markers)));
        foreach (['STRATEGY E', 'STRATEGY F', 'STRATEGY G', 'STRATEGY H'] as $absent) {
            $this->assertNotContains($absent, $fake->markers);
        }
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

    public function test_attempts_are_decorrelated_and_every_intent_carries_the_anti_overfit_clause(): void
    {
        // ACDE item 1: on a weak single engine (no per-call temperature), best-of-N only lifts if the
        // N attempts are genuinely DECORRELATED. The explorer must hand the driver structurally-distinct
        // strategy mandates, and EVERY attempt must carry the anti-overfit clause that discourages the
        // observed `if(func_num_args()===1) return 5` gaming. Freeze both guarantees.
        $fake = new class implements LoopExecutionDriver
        {
            /** @var list<string> */
            public array $intents = [];

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $this->intents[] = $intent;

                return ['status' => 'completed'];
            }
        };

        $explorer = new AtlasEvolutionScenarioExplorer($fake, new AtlasEvolutionFrozenJudge);
        $explorer->explore($this->task(), 5);

        $this->assertCount(5, $fake->intents);
        // Every attempt carries the anti-overfit clause (general logic, no literal/argcount short-circuit).
        foreach ($fake->intents as $intent) {
            $this->assertStringContainsString('Do NOT special-case', $intent);
            $this->assertStringContainsString('all valid inputs', strtolower($intent));
        }
        // The 5 attempts are genuinely distinct (decorrelation), not 5 copies of one prompt.
        $this->assertGreaterThanOrEqual(4, count(array_unique($fake->intents)), 'attempts must be decorrelated');
        // The distinct structural mandates are present across the attempts.
        $joined = implode("\n", $fake->intents);
        $this->assertStringContainsString('MINIMAL SURGICAL', $joined);
        $this->assertStringContainsString('CLEAN REDESIGN', $joined);
        $this->assertStringContainsString('ROOT CAUSE', $joined);
    }
}
