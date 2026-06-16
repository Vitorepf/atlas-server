<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Parallel\ScenarioWaveDispatcherContract;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * item6_fanout — FLAG-OFF PARITY. Even with the wave dispatcher INJECTED, when the
 * `atlas.loop.scenario_fanout.enabled` flag is off the explorer takes the byte-identical
 * SERIAL path: the spy dispatcher's dispatch() is NEVER called, the in-process driver runs every
 * attempt, and the result matches the serial contract. This guards the default-OFF invariant
 * against a wired-but-disabled production container.
 */
final class AtlasEvolutionScenarioFanoutFlagOffParityTest extends TestCase
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
        Config::set('atlas.loop.scenario_fanout.enabled', false); // explicit: the flag is OFF
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
            'provider' => 'test_provider',
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

    public function test_flag_off_runs_serial_path_and_never_calls_the_dispatcher(): void
    {
        $fake = new class implements LoopExecutionDriver
        {
            public int $calls = 0;

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $this->calls++;
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");

                return ['status' => 'completed'];
            }
        };

        // A spy dispatcher that records any call. With the flag OFF it must stay untouched.
        $spy = new class implements ScenarioWaveDispatcherContract
        {
            public int $dispatchCalls = 0;

            public function dispatch(array $specs): array
            {
                $this->dispatchCalls++;

                return [];
            }
        };

        $explorer = new AtlasEvolutionScenarioExplorer($fake, new AtlasEvolutionFrozenJudge, null, $spy);
        $result = $explorer->explore($this->task(), 3);

        // The serial path ran: the in-process driver executed every attempt, the dispatcher did not.
        $this->assertSame(0, $spy->dispatchCalls, 'flag-OFF must never engage the wave dispatcher');
        $this->assertSame(3, $fake->calls, 'flag-OFF runs all attempts through the serial in-process driver');
        $this->assertSame(3, $result['scenarios_explored']);
        $this->assertSame(3, $result['scenarios_accepted']);
        $this->assertNotNull($result['winner']);
        $this->assertSame('scn-1', $result['winner']['scenario_id']); // first minimal fix wins serially
    }
}
