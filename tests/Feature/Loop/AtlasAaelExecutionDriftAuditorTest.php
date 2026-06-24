<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionDriftAuditor;
use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionPlanProver;
use App\Services\Ai\AutonomousEvolution\AtlasAaelLoopExecutionBridge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionLoopRunner;
use Tests\TestCase;

final class AtlasAaelExecutionDriftAuditorTest extends TestCase
{
    public function test_extra_paths_outside_plan_are_flagged_and_bridge_marks_drift_detected(): void
    {
        $runner = new class
        {
            public function run(array $tasks, array $options = []): array
            {
                return [
                    'schema_version' => AtlasEvolutionLoopRunner::SCHEMA,
                    'merged_to_main' => false,
                    'proposals' => [['id' => 'p1']],
                    'proposals_certified_for_review' => 1,
                    'tasks_processed' => 1,
                    'explorations' => [[
                        'commands_exercised' => ['php artisan test'],
                        'diff_paths' => ['app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php', 'app/B.php'],
                    ]],
                ];
            }
        };

        $bridge = new AtlasAaelLoopExecutionBridge($runner, new AtlasAaelExecutionPlanProver, new AtlasAaelExecutionDriftAuditor);
        $result = $bridge->execute([[
            'objective' => 'op',
            'task' => $this->task(
                'Change app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php',
                ['app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php'],
                ['php artisan test']
            ),
        ]]);

        $audit = $result['drift_audit']['Change app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php'];
        $this->assertSame(['app/B.php'], $audit['extra_paths_outside_plan']);
        $this->assertTrue($audit['drift_detected']);
    }

    public function test_exact_match_has_no_drift_and_no_skipped_commands(): void
    {
        $runner = new class
        {
            public function run(array $tasks, array $options = []): array
            {
                return [
                    'schema_version' => AtlasEvolutionLoopRunner::SCHEMA,
                    'merged_to_main' => false,
                    'proposals' => [['id' => 'p1']],
                    'proposals_certified_for_review' => 1,
                    'tasks_processed' => 1,
                    'explorations' => [[
                        'commands_exercised' => ['php artisan test'],
                        'diff_paths' => ['app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php'],
                    ]],
                ];
            }
        };

        $bridge = new AtlasAaelLoopExecutionBridge($runner, new AtlasAaelExecutionPlanProver, new AtlasAaelExecutionDriftAuditor);
        $result = $bridge->execute([[
            'objective' => 'op',
            'task' => $this->task(
                'Change app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php',
                ['app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php'],
                ['php artisan test']
            ),
        ]]);

        $audit = $result['drift_audit']['Change app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php'];
        $this->assertFalse($audit['drift_detected']);
        $this->assertSame([], $audit['acceptance_commands_skipped']);
    }

    public function test_drift_audit_never_mutates_proposals_or_merge_state(): void
    {
        $runner = new class
        {
            public function run(array $tasks, array $options = []): array
            {
                return [
                    'schema_version' => AtlasEvolutionLoopRunner::SCHEMA,
                    'merged_to_main' => false,
                    'proposals' => [['id' => 'p1']],
                    'proposals_certified_for_review' => 1,
                    'tasks_processed' => 1,
                    'explorations' => [[
                        'commands_exercised' => [],
                        'diff_paths' => [],
                    ]],
                ];
            }
        };

        $bridge = new AtlasAaelLoopExecutionBridge($runner, new AtlasAaelExecutionPlanProver, new AtlasAaelExecutionDriftAuditor);
        $result = $bridge->execute([[
            'objective' => 'op',
            'task' => $this->task(
                'Change app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php',
                ['app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php'],
                ['php artisan test']
            ),
        ]]);

        $this->assertFalse($result['merged_to_main']);
        $this->assertFalse($result['loop_run']['merged_to_main']);
        $this->assertSame([['id' => 'p1']], $result['loop_run']['proposals']);
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $commands
     * @return array<string,mixed>
     */
    private function task(string $objective, array $allowedFiles, array $commands): array
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
