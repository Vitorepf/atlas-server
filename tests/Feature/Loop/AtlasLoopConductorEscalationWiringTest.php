<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionLoopRunner;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use ReflectionClass;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Tier-1 #5: the autonomous conductor, wired into the grinder, turns a no-winner best-of-N round
 * into STRUCTURAL escalation (best_of_n -> repair_from_refutation -> decompose -> escalate_provider)
 * instead of a dead-end. Drives a REAL runner (final class) through a FAKE LoopExecutionDriver: the
 * first attempt does not fix the bug (no winner), a later escalation attempt does — so a later tier
 * certifies. Verifies the load-bearing contract: the grinder returns the FULL winning runner-result
 * (proposals), NOT conduct()'s lite last_outcome; and that any fault is fail-open.
 */
final class AtlasLoopConductorEscalationWiringTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-conductor-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/src', 0o755, true);
        mkdir($this->base.'/tests', 0o755, true);
        file_put_contents($this->base.'/src/Subject.php', "<?php\nfunction greet(){ return 'helo'; }\n");
        file_put_contents($this->base.'/tests/subject_test.php', "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (greet() !== 'hello') { fwrite(STDERR,'red'); exit(1);} echo 'green';\n");
        // a low best-of-N width so the first tier exhausts fast and escalation kicks in deterministically
        config()->set('atlas.loop.scenarios_per_task', 1);
        config()->set('atlas.loop.max_scenarios_per_task', 2);
        config()->set('atlas.loop.escalation_max_rounds', 6);
    }

    protected function tearDown(): void
    {
        if ($this->base !== '' && is_dir($this->base)) {
            (new Process(['rm', '-rf', $this->base]))->run();
        }
        parent::tearDown();
    }

    private function task(): array
    {
        return [
            'objective' => 'Make greet() return hello so the test passes. Only edit src/.',
            'base_workspace' => $this->base,
            'provider' => 'test_provider',
            'allowed_files' => ['src/Subject.php'],
            'validation_commands' => ['php tests/subject_test.php'],
            'acceptance' => [
                'commands' => ['php tests/subject_test.php'],
                'allowed_globs' => ['src/**'],
                'frozen_globs' => ['tests/**'],
                'metric_kind' => 'gate',
            ],
        ];
    }

    private function bindDriver(callable $attempt): object
    {
        $box = new class
        {
            public int $calls = 0;
        };
        $fake = new class($box, $attempt) implements LoopExecutionDriver
        {
            /** @var callable */
            private $attempt;

            public function __construct(private readonly object $box, callable $attempt)
            {
                $this->attempt = $attempt;
            }

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                ($this->attempt)(++$this->box->calls, $workspace);

                return ['status' => 'completed'];
            }
        };
        $this->app->instance(LoopExecutionDriver::class, $fake);

        return $box;
    }

    private function grinder(): AtlasLoopTaskGrinder
    {
        $grinder = (new ReflectionClass(AtlasLoopTaskGrinder::class))->newInstanceWithoutConstructor();
        $ref = new ReflectionClass($grinder);
        $ref->getProperty('runner')->setValue($grinder, app(AtlasEvolutionLoopRunner::class)); // real runner -> real explorer -> fake driver
        $ref->getProperty('conductor')->setValue($grinder, null); // grind() falls back to new AtlasLoopAutonomousConductor
        // every other readonly dep is left uninitialized on purpose — escalateViaConductor touches only
        // runner + conductor + pure helpers; accessing any unused prop would (correctly) error.

        return $grinder;
    }

    private function escalate(AtlasLoopTaskGrinder $grinder, array $result): array
    {
        $m = (new ReflectionClass($grinder))->getMethod('escalateViaConductor');
        $m->setAccessible(true);

        // frameworkTask=false + universal=false => the heavy gate is skipped; the raw runner result decides.
        return $m->invoke($grinder, $result, $this->task(), [], false, false, ['scenarios_per_task' => 1]);
    }

    public function test_no_winner_escalates_and_a_later_tier_certifies_returns_the_full_winning_result(): void
    {
        // attempt #1 (best_of_n) leaves the bug; attempt #2+ (escalation) writes the real fix => a later tier wins.
        $box = $this->bindDriver(function (int $call, string $workspace): void {
            if ($call >= 2) {
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");
            }
        });

        $out = $this->escalate($this->grinder(), ['proposals' => [], 'explorations' => [['rejected_reasons' => ['no_passing_candidate']]]]);

        $this->assertNotSame([], $out['proposals'] ?? [], 'escalation must return the FULL winning runner-result (proposals), not the lite last_outcome');
        $this->assertTrue($out['conductor_escalation']['certified'] ?? false);
        $this->assertGreaterThan(1, $box->calls, 'a no-winner first round must escalate to at least one more tier');
    }

    public function test_escalation_never_fixes_stays_no_winner(): void
    {
        // the driver never fixes the bug => every tier fails => the original no-winner result is preserved.
        $this->bindDriver(fn (int $call, string $workspace) => null);

        $out = $this->escalate($this->grinder(), ['proposals' => [], 'explorations' => [['rejected_reasons' => ['no_passing_candidate']]]]);

        $this->assertSame([], $out['proposals'] ?? ['x'], 'no fix across all tiers => no winner');
        $this->assertFalse($out['conductor_escalation']['certified'] ?? true);
    }

    public function test_escalation_does_not_reopen_an_exhausted_task_time_budget(): void
    {
        $box = $this->bindDriver(function (): void {
            $this->fail('exhausted task budget must not launch another provider attempt');
        });
        $task = $this->task();
        $task['search_time_budget_seconds'] = 1;
        $m = (new ReflectionClass($this->grinder()))->getMethod('escalateViaConductor');
        $m->setAccessible(true);

        $out = $m->invoke(
            $this->grinder(),
            ['proposals' => [], 'elapsed_seconds' => 1.0, 'explorations' => [['rejected_reasons' => ['no_passing_candidate']]]],
            $task,
            [],
            false,
            false,
            ['scenarios_per_task' => 1],
        );

        $this->assertSame(0, $box->calls, 'elapsed first round must consume the shared task budget before conductor tiers run');
        $this->assertFalse($out['conductor_escalation']['certified'] ?? true);
    }
}
