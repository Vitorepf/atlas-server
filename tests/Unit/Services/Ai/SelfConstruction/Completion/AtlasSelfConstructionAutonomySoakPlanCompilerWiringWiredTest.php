<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Completion;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasSelfConstructionAutonomySoakPlanCompiler is wired into a real call path via the
 * `soak` action on atlas:self-construction:atlas-native-completion — it is no longer an orphan.
 */
final class AtlasSelfConstructionAutonomySoakPlanCompilerWiringWiredTest extends TestCase
{
    private string $factsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_soak_plan_'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        parent::tearDown();
    }

    private function callSoak(array $facts): array
    {
        file_put_contents($this->factsPath, json_encode($facts, JSON_UNESCAPED_SLASHES));

        Artisan::call('atlas:self-construction:atlas-native-completion', [
            'action' => 'soak',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);

        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_soak_action_is_ready_when_all_health_facts_pass(): void
    {
        $output = $this->callSoak([
            'queue_health' => ['pressure' => 0.1, 'poison_ratio' => 0.0],
            'worker_health' => ['zombie_count' => 0, 'active_workers' => 2],
            'merge_health' => ['auto_merge_enabled' => true, 'merge_success_rate' => 0.95],
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($output['is_soak_ready']);
        $this->assertEquals(24.0, $output['soak']['soak_duration_hours']);
        $this->assertSame('atlas.self_construction.completion.autonomy_soak_plan_compiler.v1', $output['soak']['schema_version']);
    }

    public function test_soak_action_fails_closed_on_human_dependency_override(): void
    {
        $output = $this->callSoak([
            'queue_health' => ['pressure' => 0.1, 'poison_ratio' => 0.0],
            'worker_health' => ['zombie_count' => 0, 'active_workers' => 2],
            'merge_health' => ['auto_merge_enabled' => true, 'merge_success_rate' => 0.95],
            'criteria_overrides' => [
                ['name' => 'requires_operator_approval', 'passing' => false],
            ],
        ]);

        $this->assertFalse($output['is_soak_ready']);
        $this->assertNotNull($output['soak']['fail_closed_reason']);
    }
}
