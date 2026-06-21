<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Parallel;

use App\Services\Ai\AutonomousEvolution\AtlasLoopResourceGate;
use App\Services\Ai\AutonomousEvolution\Parallel\ScenarioWaveDispatcher;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ScenarioWaveDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('atlas.loop.campaign.min_free_mb', 0);
        Config::set('atlas.loop.campaign.max_live_workspaces', 0);
        Config::set('atlas.loop.scenario_fanout.timeout_seconds', 5);
    }

    public function test_dispatch_returns_decoded_child_attempt_when_child_exits_zero(): void
    {
        $expected = $this->attempt(0, true, 'decoded-from-child');

        $dispatcher = new ScenarioWaveDispatcher(
            new AtlasLoopResourceGate,
            static function () use ($expected): Process {
                $json = json_encode($expected, JSON_THROW_ON_ERROR);
                $code = <<<'PHP'
                    $payload = getenv('ATLAS_ATTEMPT_JSON');
                    fwrite(STDOUT, "noise before\n");
                    fwrite(STDOUT, $payload);
                    fwrite(STDOUT, "\nnoise after\n");
                PHP;

                return new Process(
                    [PHP_BINARY, '-r', $code],
                    base_path(),
                    ['ATLAS_ATTEMPT_JSON' => $json],
                    null,
                    5
                );
            },
        );

        $results = $dispatcher->dispatch([$this->spec(0)]);

        $this->assertCount(1, $results);
        $this->assertSame('scn-1', $results[0]['scenario_id']);
        $this->assertSame('decoded-from-child', $results[0]['strategy']);
        $this->assertTrue((bool) ($results[0]['verdict']['passed'] ?? false));
        $this->assertSame(1, $results[0]['verdict']['metric']);
        $this->assertSame(['files' => 1, 'lines' => 1], $results[0]['diff_size']);
        $this->assertNull($results[0]['error']);
    }

    public function test_dispatch_returns_errored_attempt_shell_when_child_exits_non_zero(): void
    {
        $dispatcher = new ScenarioWaveDispatcher(
            new AtlasLoopResourceGate,
            static function (): Process {
                return new Process(
                    [PHP_BINARY, '-r', "fwrite(STDERR, 'child boom'); exit(7);"],
                    base_path(),
                    null,
                    null,
                    5
                );
            },
        );

        $results = $dispatcher->dispatch([$this->spec(1)]);

        $this->assertCount(1, $results);
        $attempt = $results[0];

        $this->assertSame('scn-2', $attempt['scenario_id']);
        $this->assertSame('errored', $attempt['loop_status']);
        $this->assertFalse((bool) ($attempt['verdict']['passed'] ?? true));
        $this->assertSame(['files' => 0, 'lines' => 0], $attempt['diff_size']);
        $this->assertSame('child boom', $attempt['error']);
    }

    /**
     * @return array<string,mixed>
     */
    private function spec(int $index): array
    {
        return [
            'index' => $index,
            'objective' => 'Characterize child harvest behavior.',
            'strategy_text' => 'test strategy '.$index,
            'strategy_key' => 'strategy_'.$index,
            'base_workspace' => sys_get_temp_dir(),
            'acceptance' => ['commands' => []],
            'surface_id' => 'atlas_evolution_loop',
            'user_constraints' => [],
            'surface_hints' => [],
            'provider' => 'test_provider',
            'keep_workspaces' => false,
            'workspace_root' => '',
            'clone_mode' => 'copy',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function attempt(int $index, bool $passed, string $strategy): array
    {
        return [
            'scenario_id' => 'scn-'.($index + 1),
            'strategy_key' => 'strategy_'.$index,
            'strategy' => $strategy,
            'provider' => 'test_provider',
            'loop_status' => 'completed',
            'cost_estimate_usd' => null,
            'tokens_used' => null,
            'verdict' => [
                'passed' => $passed,
                'metric' => $passed ? 1.0 : 0.0,
                'details' => ['reason' => $passed ? 'ok' : 'failed'],
            ],
            'diff_size' => ['files' => 1, 'lines' => $passed ? 1 : 0],
            'diff_text' => $passed ? 'diff text' : '',
            'workspace' => null,
            'error' => null,
        ];
    }
}
