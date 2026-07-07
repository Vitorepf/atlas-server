<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Fanout\AtlasLoopFanOutProducer;
use Tests\TestCase;

/**
 * Gate for ATLAS REDONDO SLICE 3: the Loop-invoked fan-out producer turns a
 * ready obra-decomposition into N parallel worktree cells and composes the
 * governed dispatch plan — but ONLY fans out live under
 * workcell.policy=atlas_adapter. Default-off ⇒ the plan is composed yet
 * dispatch_allowed_now=false, so nothing runs (sovereignty preserved).
 */
class AtlasLoopFanOutProducerTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function readyDecomposition(): array
    {
        return [
            'ready' => true,
            'attempts' => 1,
            'plan' => [
                'plan_id' => 'obra-big-1',
                'nodes' => [
                    ['id' => 'n1', 'request' => 'extract billing calculator', 'allowed_files' => ['app/Billing/Calc.php'], 'role' => 'coder'],
                    ['id' => 'n2', 'request' => 'add characterization tests', 'allowed_files' => ['tests/Billing/CalcTest.php'], 'depends_on' => ['n1']],
                    ['id' => 'n3', 'request' => 'wire the new calculator', 'allowed_files' => ['app/Billing/Service.php'], 'depends_on' => ['n1']],
                ],
            ],
        ];
    }

    public function test_fans_out_into_n_worktree_cells_under_atlas_adapter_policy(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.workcell.policy', 'atlas_adapter');

        $result = app(AtlasLoopFanOutProducer::class)
            ->planFanOut('Refatorar o motor de billing', $this->readyDecomposition());

        $this->assertTrue($result['fanned_out'], 'under atlas_adapter the fan-out plan must be dispatch-ready');
        $this->assertSame(3, $result['cell_count']);
        $this->assertTrue($result['policy_enabled']);
        $this->assertTrue($result['plan']['dispatch_allowed_now']);
        $this->assertTrue($result['plan']['mesh_enabled']);
        $this->assertSame(3, $result['plan']['child_count']);
        // Every cell runs in its own isolated worktree — the point of the fleet.
        foreach ($result['plan']['children'] as $child) {
            $this->assertTrue((bool) $child['assigned_worktree']);
        }
    }

    public function test_default_off_composes_but_dispatches_nothing(): void
    {
        // No config override: workcell.policy defaults to 'off'.
        $this->assertNotSame('atlas_adapter', config('atlas.ai.providers.hermes_cli.workcell.policy', 'off'));

        $result = app(AtlasLoopFanOutProducer::class)
            ->planFanOut('Refatorar o motor de billing', $this->readyDecomposition());

        $this->assertFalse($result['fanned_out'], 'default-off must never fan out live');
        $this->assertFalse($result['policy_enabled']);
        $this->assertSame(3, $result['cell_count'], 'decomposition still happens; only dispatch is gated');
        $this->assertFalse($result['plan']['dispatch_allowed_now']);
        $this->assertFalse($result['plan']['mesh_enabled']);
        $this->assertNotNull($result['reason']);
    }

    public function test_refuses_a_not_ready_decomposition(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.workcell.policy', 'atlas_adapter');

        $result = app(AtlasLoopFanOutProducer::class)->planFanOut('vague goal', [
            'ready' => false,
            'plan' => null,
            'gaps' => ['no_plan_generated'],
        ]);

        $this->assertFalse($result['fanned_out']);
        $this->assertSame('decomposition_not_ready', $result['reason']);
        $this->assertSame(0, $result['cell_count']);
        $this->assertNull($result['plan']);
    }

    public function test_command_invokes_the_producer_and_reports_gated_result(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'decomp').'.json';
        file_put_contents($file, (string) json_encode($this->readyDecomposition()));

        // Default-off path through the real Loop-invocable command surface.
        $this->artisan('atlas:loop:fanout', ['--goal' => 'Refatorar billing', '--file' => $file, '--json' => true])
            ->assertExitCode(0);

        @unlink($file);
    }
}
