<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * item6_fanout — the per-scenario subprocess command. Proves the parent/child contract: a
 * base64(json) scenario spec round-trips through `atlas:loop:run-scenario` to a JSON attempt for a
 * tiny real workspace, and the command exits 0. The container driver is bound to a deterministic
 * stub so the round-trip is hermetic (no real provider).
 */
final class AtlasLoopRunScenarioCommandTest extends TestCase
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

        // Deterministic in-process driver so the command resolves the explorer without a real provider.
        $this->app->bind(LoopExecutionDriver::class, fn (): LoopExecutionDriver => new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
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
        (new Process(['rm', '-rf', $this->base]))->run();
        parent::tearDown();
    }

    public function test_command_round_trips_a_base64_spec_to_a_json_attempt(): void
    {
        $spec = [
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

        $exit = $this->artisan('atlas:loop:run-scenario', [
            '--spec' => base64_encode((string) json_encode($spec)),
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit, 'the per-scenario command must exit 0 on a valid spec');
    }

    public function test_command_rejects_an_invalid_spec(): void
    {
        $exit = $this->artisan('atlas:loop:run-scenario', [
            '--spec' => 'not-base64-json!!!',
            '--json' => true,
        ])->run();

        $this->assertSame(1, $exit, 'a garbled spec must fail cleanly rather than crash');
    }
}
