<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the project-lane runtime-instance cycle runner is live at the operator surface and emits a
 * deterministic, strictly-preview cycle: even with options requesting apply, the command forces dry-run, so a
 * scope-clean planned action is withheld (reason dry_run) and nothing is applied. A missing --instance is a
 * usage error.
 */
final class AtlasLoopLaneCycleCommandTest extends TestCase
{
    public function test_requires_instance(): void
    {
        $exit = Artisan::call('atlas:loop:lane-cycle', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_forces_dry_run_preview(): void
    {
        $exit = Artisan::call('atlas:loop:lane-cycle', [
            '--instance' => json_encode([
                'lane_id' => 'L1',
                'project_id' => 'P1',
                'queue_namespace' => 'ns1',
                'allowed_roots' => ['app/'],
            ]),
            '--facts' => json_encode([
                'planned_actions' => [
                    ['kind' => 'index_code', 'lane_id' => 'L1', 'queue_namespace' => 'ns1', 'write_roots' => ['app/']],
                ],
            ]),
            '--options' => json_encode(['apply' => true]), // requested apply, but the command forces dry-run
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.project_lane.runtime_instance_cycle_runner.v1', $decoded['schema_version']);
        $this->assertSame('L1', $decoded['lane_id']);
        $this->assertTrue($decoded['dry_run']);
        $this->assertSame([], $decoded['applied_actions']);

        $withheldReasons = array_column($decoded['withheld_actions'], 'reason');
        $this->assertContains('dry_run', $withheldReasons);
    }
}
