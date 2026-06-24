<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use Illuminate\Support\Facades\Artisan;
use stdClass;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §0 MASTER SWITCH — close the autonomous-respawn coverage hole on the GRIND subprocess path.
 * AtlasLoopMasterSwitch::enabled() was checked by atlas:loop:campaign + atlas:loop:keepalive but NOT by the
 * per-task grind/respawn commands the supervisor forks (atlas:loop:run-scenario) nor the auto-feed / orphan-reap
 * preflights. A keepalive race could fork these the instant the operator ran `atlas:loop:off`; the children
 * inherited the .env and kept burning provider tokens. This proves the new fail-closed gate at the very top of
 * each command: OFF (absent flag / =false) ⇒ exit 0 with {status:'master_switch_off'} and NO grind / NO DB
 * write; ON (=true) ⇒ the existing happy path proceeds and the grind (here a hermetic stub driver) runs.
 *
 * The explorer is FINAL, so — exactly like AtlasLoopRunScenarioCommandTest — we bind a real explorer over a
 * deterministic in-process LoopExecutionDriver stub. The stub counts its invocations so "no grind when OFF"
 * is asserted directly, never inferred.
 */
final class AtlasLoopRunScenarioMasterSwitchTest extends TestCase
{
    private string $tmpEnv;

    private string $base;

    /** Shared call-counter for the stub driver — `n` is the number of grind attempts actually executed. */
    private stdClass $grind;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpEnv = sys_get_temp_dir().'/atlas-runscn-master-'.bin2hex(random_bytes(6)).'.env';
        AtlasLoopMasterSwitch::$envPathOverride = $this->tmpEnv;

        $this->base = sys_get_temp_dir().'/atlas-runscn-base-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/src', 0o755, true);
        mkdir($this->base.'/tests', 0o755, true);
        file_put_contents($this->base.'/src/Subject.php', "<?php\nfunction greet(){ return 'helo'; }\n");
        file_put_contents($this->base.'/tests/subject_test.php', "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (greet() !== 'hello') { fwrite(STDERR,'red'); exit(1);} echo 'green';\n");

        $this->grind = new stdClass;
        $this->grind->n = 0;

        // Deterministic in-process driver: counts every grind attempt and makes the workspace pass the judge,
        // so the ON path is hermetic (no real provider). Binding it lets the FINAL explorer resolve for method
        // injection even on the OFF path (where it is never called).
        $this->app->bind(LoopExecutionDriver::class, fn (): LoopExecutionDriver => new class($this->grind) implements LoopExecutionDriver
        {
            public function __construct(private stdClass $grind) {}

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $this->grind->n++;
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");

                return ['status' => 'completed'];
            }
        });
        $this->app->bind(AtlasEvolutionScenarioExplorer::class, fn ($app): AtlasEvolutionScenarioExplorer => new AtlasEvolutionScenarioExplorer(
            $app->make(LoopExecutionDriver::class),
            new AtlasEvolutionFrozenJudge,
        ));
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->tmpEnv);
        (new Process(['rm', '-rf', $this->base]))->run();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function validSpec(): array
    {
        return [
            'index' => 0,
            'objective' => 'Make greet() return hello. Only edit src/.',
            'strategy_text' => '',
            'strategy_key' => 'baseline',
            'base_workspace' => $this->base,
            'acceptance' => [
                'commands' => ['php tests/subject_test.php'],
                'allowed_globs' => ['src/**'],
                'frozen_globs' => ['tests/**'],
                'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
            ],
            'surface_id' => 'atlas_evolution_loop',
            'user_constraints' => ['allowed_files=src/Subject.php'],
            'surface_hints' => ['provider_choice' => 'test_provider'],
            'provider' => 'test_provider',
            'keep_workspaces' => false,
            'workspace_root' => '',
            'clone_mode' => 'copy',
        ];
    }

    private function runScenario(): array
    {
        $code = Artisan::call('atlas:loop:run-scenario', [
            '--spec' => base64_encode((string) json_encode($this->validSpec())),
            '--json' => true,
        ]);
        $out = json_decode(trim(Artisan::output()), true);

        return ['code' => $code, 'out' => is_array($out) ? $out : []];
    }

    // ── run-scenario: the grind subprocess (the respawn vector this task closes) ─────────────────────────

    public function test_run_scenario_absent_flag_is_off_and_skips_grind(): void
    {
        @unlink($this->tmpEnv); // no .env at all — fail-closed

        $result = $this->runScenario();

        $this->assertSame(0, $result['code'], 'OFF must be a clean no-op exit (SUCCESS), never an error');
        $this->assertSame('master_switch_off', $result['out']['status'] ?? null, 'output keyed as master_switch_off');
        $this->assertSame(0, $this->grind->n, 'master OFF ⇒ the grind subprocess runs NOTHING');
    }

    public function test_run_scenario_explicit_false_is_off_and_skips_grind(): void
    {
        file_put_contents($this->tmpEnv, "ATLAS_LOOP_MASTER_ENABLED=false\n");

        $result = $this->runScenario();

        $this->assertSame(0, $result['code']);
        $this->assertSame('master_switch_off', $result['out']['status'] ?? null);
        $this->assertSame(0, $this->grind->n, 'master OFF ⇒ no grind');
    }

    public function test_run_scenario_master_on_proceeds_and_runs_the_grind(): void
    {
        file_put_contents($this->tmpEnv, "ATLAS_LOOP_MASTER_ENABLED=true\n");

        $result = $this->runScenario();

        $this->assertSame(0, $result['code'], 'ON: the existing happy path runs to completion');
        $this->assertNotSame('master_switch_off', $result['out']['status'] ?? null, 'ON must NOT short-circuit the gate');
        $this->assertSame(1, $this->grind->n, 'master ON ⇒ the grind actually ran (stub driver invoked once)');
    }

    // ── the other two respawn vectors gated by the same fail-closed §0 read ──────────────────────────────

    public function test_backlog_auto_feed_is_off_when_master_off(): void
    {
        file_put_contents($this->tmpEnv, "ATLAS_LOOP_MASTER_ENABLED=false\n");

        $code = Artisan::call('atlas:loop:backlog-feed', ['--json' => true]);
        $out = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $code, 'clean no-op exit');
        $this->assertSame('master_switch_off', $out['status'] ?? null, 'backlog auto-feed short-circuits on master OFF');
    }

    public function test_reap_orphans_is_off_when_master_off(): void
    {
        file_put_contents($this->tmpEnv, "ATLAS_LOOP_MASTER_ENABLED=false\n");

        // No DB write can happen: the §0 gate returns before the first AtlasLoopCampaign query.
        $code = Artisan::call('atlas:loop:reap-orphans', ['--json' => true]);
        $out = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $code, 'clean no-op exit');
        $this->assertSame('master_switch_off', $out['status'] ?? null, 'orphan reaper short-circuits on master OFF');
    }
}
