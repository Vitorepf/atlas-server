<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\E2E\AtlasSelfConstructionStewardshipInstanceRuntimePlan;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the stewardship instance runtime plan is live at the operator surface: an admitted lane with
 * incomplete organ coverage is refused with blockers; the same lane with full coverage composes a runtime plan.
 */
final class AtlasLoopStewardshipPlanCommandTest extends TestCase
{
    private function lane(): array
    {
        return [
            'project_id' => 'p1',
            'repo_root' => '/repo',
            'mainline_branch' => 'main',
            'admitted' => true,
            'allowed_scope_roots' => ['/repo/app'],
            'merge_policy' => ['mode' => 'shared_main_with_scope_lock'],
            'verification_commands' => ['phpunit'],
        ];
    }

    private function compose(array $lane, array $coverage): array
    {
        $exit = Artisan::call('atlas:loop:stewardship-plan', [
            '--lane' => (string) json_encode($lane),
            '--coverage' => (string) json_encode($coverage),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_incomplete_coverage_is_refused(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->compose($this->lane(), ['fully_covered' => false, 'missing_organ' => ['cortex']]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasSelfConstructionStewardshipInstanceRuntimePlan::SCHEMA, $d['schema_version']);
        $this->assertTrue($d['refused'], (string) json_encode($d));
        $this->assertContains('organ_coverage_incomplete', $d['blockers']);
        $this->assertNull($d['runtime_plan']);
    }

    public function test_full_coverage_composes_runtime_plan(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->compose($this->lane(), ['fully_covered' => true]);

        $this->assertSame(0, $exit);
        $this->assertFalse($d['refused'], (string) json_encode($d));
        $this->assertSame([], $d['blockers']);
        $this->assertNotNull($d['runtime_plan']);
        $this->assertSame('/repo', $d['runtime_plan']['repository_root']);
        $this->assertSame(AtlasSelfConstructionStewardshipInstanceRuntimePlan::ISOLATION, $d['runtime_plan']['workspace_topology']);
    }

    public function test_missing_lane_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:stewardship-plan', ['--coverage' => '{}', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
