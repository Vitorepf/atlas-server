<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionDriftAuditor;
use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionPlanProver;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight\AtlasAaelInFlightStepValidator;
use App\Services\Ai\AutonomousEvolution\AtlasAaelLoopExecutionBridge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionLoopRunner;
use Tests\TestCase;

final class AtlasAaelInFlightStepValidatorTest extends TestCase
{
    public function test_bridge_invokes_validator_once_per_step_boundary_and_emits_fact_records(): void
    {
        $runner = new class
        {
            public int $calls = 0;

            public function run(array $tasks, array $options = []): array
            {
                $task = $tasks[0] ?? [];
                $this->calls++;

                return [
                    'schema_version' => AtlasEvolutionLoopRunner::SCHEMA,
                    'merged_to_main' => false,
                    'proposals' => [],
                    'proposals_certified_for_review' => 0,
                    'tasks_processed' => 1,
                    'stop_reason' => 'queue_exhausted',
                    'elapsed_seconds' => 0.0,
                    'explorations' => [[
                        'objective' => (string) ($task['objective'] ?? ''),
                        'commands_exercised' => ['php artisan test'],
                        'diff_paths' => ['app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php'],
                        'world_snapshot' => [
                            'invariants' => [
                                'inv-ready' => true,
                            ],
                        ],
                    ]],
                ];
            }
        };

        $bridge = new AtlasAaelLoopExecutionBridge(
            $runner,
            new AtlasAaelExecutionPlanProver,
            new AtlasAaelExecutionDriftAuditor,
            new AtlasAaelInFlightStepValidator,
        );

        $result = $bridge->execute([
            $this->opportunity('step-1'),
            $this->opportunity('step-2'),
            $this->opportunity('step-3'),
        ]);

        $this->assertSame(3, $runner->calls);
        $this->assertCount(2, $result['in_flight_validation']['calls']);
        $this->assertFalse($result['in_flight_validation']['halted']);

        $fact = $result['in_flight_validation']['calls'][0]['facts'][0];
        $this->assertSame('inv-ready', $fact['invariant_id']);
        $this->assertTrue($fact['holds']);
        $this->assertTrue($fact['observed_value']);
        $this->assertTrue($fact['declared_value']);
        $this->assertSame(1, $fact['step_index']);
    }

    public function test_missing_invariant_fails_closed_and_bridge_halts_subsequent_steps(): void
    {
        $runner = new class
        {
            public int $calls = 0;

            public function run(array $tasks, array $options = []): array
            {
                $task = $tasks[0] ?? [];
                $this->calls++;

                return [
                    'schema_version' => AtlasEvolutionLoopRunner::SCHEMA,
                    'merged_to_main' => false,
                    'proposals' => [],
                    'proposals_certified_for_review' => 0,
                    'tasks_processed' => 1,
                    'stop_reason' => 'queue_exhausted',
                    'elapsed_seconds' => 0.0,
                    'explorations' => [[
                        'objective' => (string) ($task['objective'] ?? ''),
                        'commands_exercised' => ['php artisan test'],
                        'diff_paths' => ['app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php'],
                        'world_snapshot' => $this->calls === 1
                            ? ['invariants' => []]
                            : ['invariants' => ['inv-ready' => true]],
                    ]],
                ];
            }
        };

        $bridge = new AtlasAaelLoopExecutionBridge(
            $runner,
            new AtlasAaelExecutionPlanProver,
            new AtlasAaelExecutionDriftAuditor,
            new AtlasAaelInFlightStepValidator,
        );

        $result = $bridge->execute([
            $this->opportunity('step-1'),
            $this->opportunity('step-2'),
            $this->opportunity('step-3'),
        ]);

        $this->assertSame(1, $runner->calls);
        $this->assertSame(1, $result['executed_tasks']);
        $this->assertTrue($result['in_flight_validation']['halted']);
        $this->assertSame([
            'code' => 'in_flight_invariant_failed',
            'step_index' => 1,
            'failed_invariant_ids' => ['inv-ready'],
        ], $result['in_flight_validation']['halt_reason']);
        $this->assertFalse($result['in_flight_validation']['calls'][0]['facts'][0]['holds']);
        $this->assertSame('missing_invariant', $result['in_flight_validation']['calls'][0]['facts'][0]['reason']);
    }

    /**
     * @return array<string,mixed>
     */
    private function opportunity(string $label): array
    {
        return [
            'objective' => $label,
            'task' => [
                'objective' => 'Change app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php',
                'base_workspace' => base_path(),
                'allowed_files' => ['app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php'],
                'forbidden_files' => [],
                'declared_invariants' => [
                    'inv-ready' => true,
                ],
                'acceptance' => [
                    'commands' => ['php artisan test'],
                    'allowed_globs' => ['app/**'],
                    'frozen_globs' => ['tests/**'],
                    'metric_kind' => 'metric_gate',
                ],
            ],
        ];
    }
}
