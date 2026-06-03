<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionLoopRunner;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasEvolutionLoopRunnerTest extends TestCase
{
    private array $bases = [];

    protected function tearDown(): void
    {
        foreach ($this->bases as $b) {
            (new Process(['rm', '-rf', $b]))->run();
        }
        parent::tearDown();
    }

    private function makeBase(): string
    {
        $base = sys_get_temp_dir().'/atlas-loop-runner-'.bin2hex(random_bytes(4));
        mkdir($base.'/src', 0o755, true);
        mkdir($base.'/tests', 0o755, true);
        file_put_contents($base.'/src/Subject.php', "<?php\nfunction greet(){ return 'helo'; }\n");
        file_put_contents($base.'/tests/subject_test.php', "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (greet() !== 'hello') { fwrite(STDERR,'red'); exit(1);} echo 'green';\n");
        $this->bases[] = $base;

        return $base;
    }

    private function task(string $base): array
    {
        return [
            'objective' => 'Make greet() return hello.',
            'base_workspace' => $base,
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

    private function runner(LoopExecutionDriver $driver): AtlasEvolutionLoopRunner
    {
        return new AtlasEvolutionLoopRunner(
            new AtlasEvolutionScenarioExplorer($driver, new AtlasEvolutionFrozenJudge),
        );
    }

    private function fixingDriver(): LoopExecutionDriver
    {
        return new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");

                return ['status' => 'completed'];
            }
        };
    }

    public function test_loop_never_merges_and_accumulates_certified_proposals(): void
    {
        $tasks = [$this->task($this->makeBase()), $this->task($this->makeBase())];

        $result = $this->runner($this->fixingDriver())->run($tasks, [
            'scenarios_per_task' => 2,
            'propose_only' => true,
        ]);

        // THE invariant — the loop proposes, it never merges to main.
        $this->assertFalse($result['merged_to_main']);
        $this->assertTrue($result['propose_only']);

        $this->assertSame(2, $result['tasks_processed']);
        $this->assertSame(2, $result['proposals_certified_for_review']);
        $this->assertSame('queue_exhausted', $result['stop_reason']);

        foreach ($result['proposals'] as $proposal) {
            $this->assertSame('certified_for_review', $proposal['status']);
            $this->assertNotSame('', $proposal['diff_text']);
            $this->assertStringContainsString("hello", $proposal['diff_text']);
            $this->assertSame(64, strlen($proposal['proposal_hash']));
        }
    }

    public function test_respects_the_max_tasks_cap(): void
    {
        $tasks = [$this->task($this->makeBase()), $this->task($this->makeBase()), $this->task($this->makeBase())];

        $result = $this->runner($this->fixingDriver())->run($tasks, [
            'scenarios_per_task' => 1,
            'max_tasks' => 1,
            'propose_only' => true,
        ]);

        $this->assertSame(1, $result['tasks_processed']);
        $this->assertSame('max_tasks_reached', $result['stop_reason']);
    }

    public function test_records_explorations_with_rejected_reasons_even_when_no_winner(): void
    {
        // a driver that always cheats by tampering the frozen test -> judge rejects all
        $cheater = new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                file_put_contents($workspace.'/tests/subject_test.php', "<?php\necho 'green';\n");

                return ['status' => 'completed'];
            }
        };

        $result = $this->runner($cheater)->run([$this->task($this->makeBase())], [
            'scenarios_per_task' => 2,
            'propose_only' => true,
        ]);

        $this->assertSame(0, $result['proposals_certified_for_review']);
        $this->assertFalse($result['explorations'][0]['has_winner']);
        $this->assertContains('frozen_path_tampered', $result['explorations'][0]['rejected_reasons']);
    }
}
