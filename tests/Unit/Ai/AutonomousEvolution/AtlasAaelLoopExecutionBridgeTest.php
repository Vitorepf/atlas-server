<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasAaelLoopExecutionBridge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionLoopRunner;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasAaelLoopExecutionBridgeTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-bridge-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/app/Services/Ai/AutonomousEvolution', 0o755, true);
        mkdir($this->base.'/tests', 0o755, true);
        file_put_contents($this->base.'/app/Services/Ai/AutonomousEvolution/Subject.php', "<?php\nfunction greet(){ return 'helo'; }\n");
        file_put_contents($this->base.'/tests/subject_test.php', "<?php\nrequire __DIR__.'/../app/Services/Ai/AutonomousEvolution/Subject.php';\nif (greet() !== 'hello') { fwrite(STDERR,'red'); exit(1);} echo 'green';\n");
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->base]))->run();
        parent::tearDown();
    }

    public function test_executes_metric_shaped_opportunities_and_defers_the_rest(): void
    {
        $fix = new class implements LoopExecutionDriver
            {
                public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
                {
                    file_put_contents($workspace.'/app/Services/Ai/AutonomousEvolution/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");

                    return ['status' => 'completed'];
                }
        };

        $bridge = new AtlasAaelLoopExecutionBridge(
            new AtlasEvolutionLoopRunner(new AtlasEvolutionScenarioExplorer($fix, new AtlasEvolutionFrozenJudge)),
        );

        $opportunities = [
            [
                'objective' => 'fix greet',
                'opportunity_id' => 'op-1',
                    'task' => [
                    'objective' => 'Make app/Services/Ai/AutonomousEvolution/Subject.php greet() return hello.',
                    'base_workspace' => $this->base,
                    'provider' => 'test_provider',
                    'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Subject.php'],
                    'validation_commands' => ['php tests/subject_test.php'],
                    'acceptance' => [
                        'commands' => ['php tests/subject_test.php'],
                        'allowed_globs' => ['app/Services/Ai/AutonomousEvolution/**'],
                        'frozen_globs' => ['tests/**'],
                        'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
                    ],
                ],
            ],
            // a vague AAEL opportunity with NO metric-shaped task -> must be deferred
            ['objective' => 'improve Atlas somehow', 'opportunity_id' => 'op-2'],
        ];

        $result = $bridge->execute($opportunities, ['scenarios_per_task' => 1, 'propose_only' => true]);

        $this->assertSame(2, $result['opportunities_total']);
        $this->assertSame(1, $result['executed_tasks']);
        $this->assertSame(1, $result['deferred_count']);
        $this->assertFalse($result['merged_to_main']);
        $this->assertSame(1, $result['loop_run']['proposals_certified_for_review']);
        $this->assertFalse($result['loop_run']['merged_to_main']);
        $this->assertStringContainsString('needs decomposition', $result['deferred'][0]['reason']);
        $this->assertSame('op-2', $result['deferred'][0]['opportunity_id']);
    }

    public function test_complete_research_contract_is_attached_before_dispatch(): void
    {
        $runner = new class
        {
            public array $received = [];

            public function run(array $tasks, array $options): array
            {
                $this->received[] = $tasks[0];

                return [
                    'proposals_certified_for_review' => 0,
                    'elapsed_seconds' => 0.0,
                    'proposals' => [],
                    'explorations' => [],
                    'stop_reason' => 'queue_exhausted',
                ];
            }
        };
        $bridge = new AtlasAaelLoopExecutionBridge($runner);

        $result = $bridge->execute([[
            'objective' => 'contracted task',
            'opportunity_id' => 'op-contract',
            'ambition' => 'reach_target',
            'scope' => 'novelty_leaning',
            'task' => $this->metricTask(),
        ]]);

        $this->assertSame(1, $result['executed_tasks']);
        $this->assertSame(0, $result['incomplete_contracts_deferred']);
        $this->assertFalse($result['merged_to_main']);
        $this->assertSame('atlas.evolution.aael_bridge.v1', $result['schema_version']);
        $this->assertTrue($runner->received[0]['research_contract']['complete']);
        $this->assertSame('reach_target', $runner->received[0]['research_contract']['ambition']);
        $this->assertSame('novelty_leaning', $runner->received[0]['research_contract']['scope']);
    }

    public function test_incomplete_research_contract_is_deferred_before_runner_dispatch(): void
    {
        $runner = new class
        {
            public array $received = [];

            public function run(array $tasks, array $options): array
            {
                $this->received[] = $tasks[0];

                return [];
            }
        };
        $bridge = new AtlasAaelLoopExecutionBridge($runner);
        $task = $this->metricTask();
        unset($task['acceptance']['frozen_globs']);

        $result = $bridge->execute([[
            'objective' => 'malformed contract',
            'opportunity_id' => 'op-incomplete',
            'task' => $task,
        ]]);

        $this->assertSame(0, $result['executed_tasks']);
        $this->assertSame(1, $result['deferred_count']);
        $this->assertSame(1, $result['incomplete_contracts_deferred']);
        $this->assertSame([], $runner->received);
        $this->assertStringStartsWith('incomplete_research_contract:HARD_CONSTRAINTS', $result['deferred'][0]['reason']);
    }

    public function test_opportunity_without_acceptance_still_defers_as_no_metric_shaped_task(): void
    {
        $runner = new class
        {
            public function run(array $tasks, array $options): array
            {
                return [];
            }
        };
        $bridge = new AtlasAaelLoopExecutionBridge($runner);

        $result = $bridge->execute([[
            'objective' => 'vague AAEL opportunity',
            'opportunity_id' => 'op-vague',
            'task' => ['objective' => 'missing acceptance'],
        ]]);

        $this->assertSame(0, $result['executed_tasks']);
        $this->assertSame(1, $result['deferred_count']);
        $this->assertSame(0, $result['incomplete_contracts_deferred']);
        $this->assertStringStartsWith('no_metric_shaped_task', $result['deferred'][0]['reason']);
        $this->assertSame('op-vague', $result['deferred'][0]['opportunity_id']);
        $this->assertFalse($result['merged_to_main']);
        $this->assertFalse($result['loop_run']['merged_to_main']);
    }

    private function metricTask(): array
    {
        return [
            'objective' => 'Change app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php safely.',
            'base_workspace' => dirname(__DIR__, 4),
            'provider' => 'test_provider',
            'allowed_files' => ['app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php'],
            'validation_commands' => ['php -v'],
            'acceptance' => [
                'commands' => ['php -v'],
                'allowed_globs' => ['app/Services/Ai/AutonomousEvolution/**'],
                'frozen_globs' => ['tests/Unit/Ai/AutonomousEvolution/**'],
                'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
            ],
        ];
    }
}
