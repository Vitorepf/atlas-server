<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

class AtlasSwarmConductCommandTest extends TestCase
{
    public function test_shadow_run_emits_governed_envelope_json_and_exits_zero(): void
    {
        $this->artisan('atlas:swarm:conduct', [
            'task' => 'code_generation',
            '--role' => 'primary',
            '--mode' => 'shadow',
            '--parallelism' => 1,
            '--json' => true,
        ])
            ->expectsOutputToContain('atlas.engineering_run.envelope.v1')
            ->assertExitCode(0);
    }

    public function test_human_render_runs_and_exits_zero(): void
    {
        $this->artisan('atlas:swarm:conduct', [
            'task' => 'code_generation',
            '--role' => 'primary',
            '--mode' => 'shadow',
        ])->assertExitCode(0);
    }

    public function test_live_without_flag_does_not_error_and_stays_governed(): void
    {
        // Production resolver disabled by default -> live request must downgrade
        // to shadow gracefully (never a hard failure, never real spend).
        config(['atlas.patamar4.swarm_production_resolver_enabled' => false]);

        $this->artisan('atlas:swarm:conduct', [
            'task' => 'code_generation',
            '--role' => 'primary',
            '--mode' => 'live',
            '--parallelism' => 1,
        ])->assertExitCode(0);
    }

    public function test_authored_plan_dag_via_cli_emits_plan_trace_and_exits_zero(): void
    {
        $plan = json_encode(['nodes' => [
            ['node_id' => 'reason', 'task_category' => 'reasoning', 'role' => 'engineer', 'depends_on' => ['gather']],
            ['node_id' => 'gather', 'task_category' => 'retrieval', 'role' => 'researcher'],
        ]]);

        // The opt-in --plan flag threads into $options['plan'] and runs the
        // authored ordering in SHADOW (zero spend), emitting a plan_trace.
        $this->artisan('atlas:swarm:conduct', [
            'task' => 'code_generation',
            '--role' => 'primary',
            '--mode' => 'shadow',
            '--plan' => $plan,
            '--json' => true,
        ])
            ->expectsOutputToContain('plan_trace')
            ->assertExitCode(0);
    }
}
