<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionPlanProver;
use App\Services\Ai\AutonomousEvolution\AtlasAaelLoopExecutionBridge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionLoopRunner;
use Tests\TestCase;

final class AtlasAaelExecutionPlanProverTest extends TestCase
{
    public function test_rejects_empty_acceptance_commands_and_runner_receives_zero_such_tasks(): void
    {
        $runner = new class
        {
            public array $tasks = [];

            public function run(array $tasks, array $options = []): array
            {
                $this->tasks = $tasks;

                return [
                    'schema_version' => AtlasEvolutionLoopRunner::SCHEMA,
                    'merged_to_main' => false,
                    'proposals' => [],
                    'proposals_certified_for_review' => 0,
                    'tasks_processed' => count($tasks),
                ];
            }
        };

        $bridge = new AtlasAaelLoopExecutionBridge($runner, new AtlasAaelExecutionPlanProver);
        $result = $bridge->execute([[
            'objective' => 'anchor [app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php]',
            'opportunity_id' => 'op-1',
            'task' => $this->task(
                objective: 'Change app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php',
                commands: [],
            ),
        ]]);

        $this->assertSame([], $runner->tasks);
        $this->assertSame(['fact_acceptance_commands_empty'], $result['prover_rejected'][0]['reasons']);
    }

    public function test_anchor_missing_is_rejected_and_valid_anchor_passes_with_prover_schema(): void
    {
        $prover = new AtlasAaelExecutionPlanProver;

        $bad = $prover->prove($this->task(
            objective: 'Change app/DoesNotExist.php',
            commands: ['php artisan test'],
        ), base_path());
        $good = $prover->prove($this->task(
            objective: 'Change app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php',
            commands: ['php artisan test'],
        ), base_path());

        $this->assertFalse($bad['passed']);
        $this->assertContains('fact_anchor_missing', $bad['reasons']);
        $this->assertTrue($good['passed']);
        $this->assertSame('atlas.aael.plan_prover.v1', $good['schema_version']);
        $this->assertCount(4, $good['fact_ids']);
    }

    public function test_when_all_tasks_fail_bridge_returns_zero_executed_tasks_and_non_empty_reasons(): void
    {
        $runner = new class
        {
            public function run(array $tasks, array $options = []): array
            {
                return [
                    'schema_version' => AtlasEvolutionLoopRunner::SCHEMA,
                    'merged_to_main' => false,
                    'proposals' => [],
                    'proposals_certified_for_review' => 0,
                    'tasks_processed' => count($tasks),
                ];
            }
        };

        $bridge = new AtlasAaelLoopExecutionBridge($runner, new AtlasAaelExecutionPlanProver);
        $result = $bridge->execute([
            [
                'objective' => 'bad one',
                'opportunity_id' => 'op-1',
                'task' => $this->task(objective: 'Change app/DoesNotExist.php', commands: []),
            ],
            [
                'objective' => 'bad two',
                'opportunity_id' => 'op-2',
                'task' => $this->task(objective: 'Change app/Nope.php', commands: ['php artisan test'], allowedFiles: ['vendor/foo.php']),
            ],
        ]);

        $this->assertSame(0, $result['executed_tasks']);
        $this->assertNotEmpty($result['prover_rejected']);
        $this->assertFalse($result['merged_to_main']);
    }

    /**
     * @param  list<string>  $commands
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function task(string $objective, array $commands, array $allowedFiles = ['app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php']): array
    {
        return [
            'objective' => $objective,
            'base_workspace' => base_path(),
            'allowed_files' => $allowedFiles,
            'forbidden_files' => [],
            'acceptance' => [
                'commands' => $commands,
                'allowed_globs' => ['app/**'],
                'frozen_globs' => ['tests/**'],
                'metric_kind' => 'metric_gate',
            ],
        ];
    }
}
