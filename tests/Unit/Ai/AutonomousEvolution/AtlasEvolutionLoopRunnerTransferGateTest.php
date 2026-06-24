<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionLoopRunner;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\AtlasLoopTransferGate;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasEvolutionLoopRunnerTransferGateTest extends TestCase
{
    /** @var list<string> */
    private array $bases = [];

    protected function tearDown(): void
    {
        foreach ($this->bases as $base) {
            (new Process(['rm', '-rf', $base]))->run();
        }
        parent::tearDown();
    }

    private function makeBase(): string
    {
        $base = sys_get_temp_dir().'/atlas-loop-runner-transfer-'.bin2hex(random_bytes(4));
        mkdir($base.'/src', 0o755, true);
        mkdir($base.'/tests', 0o755, true);
        file_put_contents($base.'/src/Subject.php', "<?php\nfunction greet(){ return 'helo'; }\n");
        file_put_contents($base.'/src/Other.php', "<?php\nfunction add(\$a, \$b){ return \$a + \$b; }\n");
        file_put_contents($base.'/tests/subject_test.php', "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (greet() !== 'hello') { exit(1); }\n");
        file_put_contents($base.'/tests/other_test.php', "<?php\nrequire __DIR__.'/../src/Other.php';\nif (add(2, 3) !== 5) { exit(1); }\n");
        $this->bases[] = $base;

        return $base;
    }

    /**
     * @return array<string,mixed>
     */
    private function task(string $base, bool $withTransfer = true): array
    {
        $acceptance = $this->mainAcceptance();
        if ($withTransfer) {
            $acceptance['transfer'] = $this->transferAcceptance();
        }

        return [
            'objective' => 'Fix greet() without regressing the held-out add() behavior.',
            'base_workspace' => $base,
            'provider' => 'test_provider',
            'allowed_files' => ['src/Subject.php', 'src/Other.php'],
            'validation_commands' => ['php tests/subject_test.php'],
            'acceptance' => $acceptance,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mainAcceptance(): array
    {
        return [
            'commands' => ['php tests/subject_test.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function transferAcceptance(): array
    {
        return [
            'commands' => ['php tests/other_test.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
        ];
    }

    private function runner(LoopExecutionDriver $driver): AtlasEvolutionLoopRunner
    {
        $judge = new AtlasEvolutionFrozenJudge;

        return new AtlasEvolutionLoopRunner(
            new AtlasEvolutionScenarioExplorer($driver, $judge),
            new AtlasLoopTransferGate(new AtlasEvolutionFrozenJudge),
        );
    }

    private function regressingDriver(): LoopExecutionDriver
    {
        return new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");
                file_put_contents($workspace.'/src/Other.php', "<?php\nfunction add(\$a, \$b){ return \$a - \$b; }\n");

                return ['status' => 'completed', 'provider_invoked' => true];
            }
        };
    }

    public function test_transfer_regression_rejects_main_winner_after_explorer(): void
    {
        config()->set('atlas.loop.transfer_gate_enabled', true);

        $result = $this->runner($this->regressingDriver())->run([$this->task($this->makeBase())], [
            'scenarios_per_task' => 1,
            'propose_only' => true,
        ]);

        $this->assertSame(0, $result['proposals_certified_for_review']);
        $this->assertFalse($result['explorations'][0]['has_winner']);
        $this->assertSame(0, $result['explorations'][0]['scenarios_accepted']);
        $this->assertContains('transfer_regressed', $result['explorations'][0]['rejected_reasons']);

        $metric = $result['explorations'][0]['attempt_metrics'][0];
        $this->assertFalse($metric['passed']);
        $this->assertSame('transfer_regressed', $metric['judge_reason']);
        $this->assertSame('transfer_regressed', $metric['transfer_verdict']['reason']);
        $this->assertFalse($metric['transfer_verdict']['ok']);
        $this->assertTrue($metric['transfer_verdict']['main_passed']);
        $this->assertFalse($metric['transfer_verdict']['transfer_passed']);
    }

    public function test_flag_off_keeps_transfer_acceptance_inert(): void
    {
        config()->set('atlas.loop.transfer_gate_enabled', false);

        $result = $this->runner($this->regressingDriver())->run([$this->task($this->makeBase())], [
            'scenarios_per_task' => 1,
            'propose_only' => true,
        ]);

        $this->assertSame(1, $result['proposals_certified_for_review']);
        $this->assertTrue($result['explorations'][0]['has_winner']);
        $this->assertSame(1, $result['explorations'][0]['scenarios_accepted']);
        $this->assertArrayNotHasKey('transfer_verdict', $result['explorations'][0]['attempt_metrics'][0]);
        $this->assertArrayNotHasKey('judge_reason', $result['explorations'][0]['attempt_metrics'][0]);
    }

    public function test_no_transfer_acceptance_keeps_runner_output_inert(): void
    {
        config()->set('atlas.loop.transfer_gate_enabled', true);

        $result = $this->runner($this->regressingDriver())->run([$this->task($this->makeBase(), withTransfer: false)], [
            'scenarios_per_task' => 1,
            'propose_only' => true,
        ]);

        $this->assertSame(1, $result['proposals_certified_for_review']);
        $this->assertTrue($result['explorations'][0]['has_winner']);
        $this->assertSame(1, $result['explorations'][0]['scenarios_accepted']);
        $this->assertArrayNotHasKey('transfer_verdict', $result['explorations'][0]['attempt_metrics'][0]);
        $this->assertArrayNotHasKey('judge_reason', $result['explorations'][0]['attempt_metrics'][0]);
    }
}
