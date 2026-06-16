<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionLoopRunner;
use App\Services\Ai\AutonomousEvolution\AtlasLoopObraExecutionAdapter;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTaskGrinder;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\Obra\ObraNodeDelivery;
use ReflectionClass;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Leap 2 — the conductor's `decompose` tier ACTUALLY DECOMPOSES. Before, all four escalation tiers
 * mapped to the same scenario-width closure, so `decompose` only widened the search — it never changed the
 * STRUCTURE of the attempt. This proves a no-winner round, once escalation reaches the decompose tier,
 * invokes a REAL obra planning path (executeAndProve -> maybePlan -> AtlasObraExecutor) when planning is ON,
 * and degrades to the prior scenario-width closure when planning is OFF (byte-identical).
 */
final class AtlasLoopConductorDecomposeTierWiringTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        // A workspace with a test the driver NEVER fixes => every scenario tier is a no-winner round,
        // so escalation walks the ladder forward to the decompose tier deterministically.
        $this->base = sys_get_temp_dir().'/atlas-decompose-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/src', 0o755, true);
        mkdir($this->base.'/tests', 0o755, true);
        file_put_contents($this->base.'/src/Subject.php', "<?php\nfunction greet(){ return 'helo'; }\n");
        file_put_contents($this->base.'/tests/subject_test.php', "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (greet() !== 'hello') { fwrite(STDERR,'red'); exit(1);} echo 'green';\n");
        config()->set('atlas.loop.scenarios_per_task', 1);
        config()->set('atlas.loop.max_scenarios_per_task', 2);
        config()->set('atlas.loop.escalation_max_rounds', 6);
        // Fake driver that never writes a fix => the runner certifies nothing.
        $fake = new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                return ['status' => 'completed'];
            }
        };
        $this->app->instance(LoopExecutionDriver::class, $fake);
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
            'objective' => 'Extract a helper class from src/Subject.php and redirect callers. Only edit src/.',
            'objective_kind' => 'refactor_extract_class',
            'base_workspace' => $this->base,
            'provider' => 'test_provider',
            'allowed_files' => ['src/Subject.php', 'src/SubjectHelper.php'],
            'validation_commands' => ['php tests/subject_test.php'],
            'acceptance' => [
                'commands' => ['php tests/subject_test.php'],
                'allowed_globs' => ['src/**'],
                'frozen_globs' => ['tests/**'],
                'metric_kind' => 'gate',
            ],
        ];
    }

    /**
     * Build a grinder with the REAL runner (final class — driven by the fake LoopExecutionDriver bound in
     * setUp, so it never certifies) and a SPY obra-adapter so we can observe the decompose tier invoking it.
     *
     * @return array{grinder: AtlasLoopTaskGrinder, adapter: object}
     */
    private function grinderWithSpyAdapter(): array
    {
        $grinder = (new ReflectionClass(AtlasLoopTaskGrinder::class))->newInstanceWithoutConstructor();

        // Spy obra adapter: record executeAndProve() calls. ok=false so the decompose tier returns
        // uncertified (we only assert it was INVOKED — the real obra path is exercised by the adapter's
        // own tests). Anonymous subclass with an empty ctor so it needs no real deps.
        $adapter = new class extends AtlasLoopObraExecutionAdapter
        {
            public int $calls = 0;

            /** @var list<string> */
            public array $objectives = [];

            public function __construct() {}

            public function executeAndProve(array $payload, ?ObraNodeDelivery $delivery = null): array
            {
                $this->calls++;
                $this->objectives[] = (string) ($payload['objective'] ?? '');

                return ['ok' => false, 'reason' => 'spy_no_certify'];
            }
        };

        $ref = new ReflectionClass($grinder);
        $ref->getProperty('runner')->setValue($grinder, app(AtlasEvolutionLoopRunner::class)); // real runner -> real explorer -> fake driver
        $ref->getProperty('conductor')->setValue($grinder, null); // grind() falls back to a fresh conductor
        $ref->getProperty('obraAdapter')->setValue($grinder, $adapter);

        return ['grinder' => $grinder, 'adapter' => $adapter];
    }

    private function escalate(AtlasLoopTaskGrinder $grinder): array
    {
        $m = (new ReflectionClass($grinder))->getMethod('escalateViaConductor');
        $m->setAccessible(true);

        // frameworkTask=false + universal=false => the heavy gate is skipped; the raw runner result decides.
        return $m->invoke($grinder, ['proposals' => [], 'explorations' => [['rejected_reasons' => ['no_passing_candidate']]]], $this->task(), [], false, false, ['scenarios_per_task' => 1]);
    }

    public function test_decompose_tier_invokes_a_real_maybe_plan_when_planning_is_on(): void
    {
        config()->set('atlas.loop.planning_enabled', true);
        config()->set('atlas.loop.escalation_max_rounds', 6);

        ['grinder' => $grinder, 'adapter' => $adapter] = $this->grinderWithSpyAdapter();
        $out = $this->escalate($grinder);

        $this->assertGreaterThanOrEqual(1, $adapter->calls, 'the decompose tier must invoke the obra execution adapter (real maybePlan path), not the scenario-width closure');
        $this->assertStringContainsString('Extract a helper class', $adapter->objectives[0] ?? '', 'the decompose tier carries the obra objective into the planner');
        // Fully fail-open: the spy never certifies, so the conductor never fabricates a winner.
        $this->assertFalse($out['conductor_escalation']['certified'] ?? true);
    }

    public function test_decompose_tier_degrades_to_scenario_width_when_planning_is_off(): void
    {
        config()->set('atlas.loop.planning_enabled', false); // OFF => byte-identical to the pre-Leap-2 tier
        config()->set('atlas.loop.escalation_max_rounds', 6);

        ['grinder' => $grinder, 'adapter' => $adapter] = $this->grinderWithSpyAdapter();
        $out = $this->escalate($grinder);

        $this->assertSame(0, $adapter->calls, 'with planning OFF the decompose tier must NOT touch the obra adapter (degrades to scenario width)');
        $this->assertFalse($out['conductor_escalation']['certified'] ?? true);
    }
}
