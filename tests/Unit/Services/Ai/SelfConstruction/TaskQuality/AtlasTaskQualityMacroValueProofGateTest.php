<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskQualityMacroValueProofGate;
use Tests\TestCase;

final class AtlasTaskQualityMacroValueProofGateTest extends TestCase
{
    private function gate(): AtlasTaskQualityMacroValueProofGate
    {
        return new AtlasTaskQualityMacroValueProofGate;
    }

    // ── AC: proxy-only objectives fail ──

    public function test_proxy_only_objective_fails(): void
    {
        $result = $this->gate()->verify([
            'objective' => 'Increase queue depth by 10 tasks',
        ]);

        $this->assertFalse($result['passed']);
        $this->assertContains('proxy_only_objective_without_real_capability', $result['failures']);
    }

    public function test_wrapper_count_proxy_fails(): void
    {
        $result = $this->gate()->verify([
            'objective' => 'Add more wrappers to the codebase',
        ]);

        $this->assertFalse($result['passed']);
    }

    public function test_test_count_proxy_fails(): void
    {
        $result = $this->gate()->verify([
            'objective' => 'Increase test count by 20',
        ]);

        $this->assertFalse($result['passed']);
    }

    // ── AC: closed-loop autonomy, collision prevention or repair conversion objectives pass ──

    public function test_closed_loop_autonomy_passes(): void
    {
        $result = $this->gate()->verify([
            'objective' => 'Implement closed_loop_autonomy feedback for task outcomes',
            'capability_improvement' => 'closed_loop_autonomy',
        ]);

        $this->assertTrue($result['passed']);
    }

    public function test_collision_prevention_passes(): void
    {
        $result = $this->gate()->verify([
            'objective' => 'Add collision prevention for queued targets',
            'capability_improvement' => 'collision_prevention',
        ]);

        $this->assertTrue($result['passed']);
    }

    public function test_repair_conversion_passes(): void
    {
        $result = $this->gate()->verify([
            'objective' => 'Implement repair conversion for give_back tasks',
            'capability_improvement' => 'repair_conversion',
        ]);

        $this->assertTrue($result['passed']);
    }

    // ── proxy with real capability still passes ──

    public function test_proxy_with_real_capability_passes(): void
    {
        $result = $this->gate()->verify([
            'objective' => 'Increase queue depth while adding collision_prevention',
            'capability_improvement' => 'collision_prevention',
        ]);

        $this->assertTrue($result['passed']);
    }

    // ── missing capability improvement statement ──

    public function test_missing_capability_improvement_fails(): void
    {
        $result = $this->gate()->verify([
            'objective' => 'Do something useful',
        ]);

        $this->assertFalse($result['passed']);
        $this->assertContains('missing_capability_improvement_statement', $result['failures']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->gate()->verify([]);

        $this->assertSame(AtlasTaskQualityMacroValueProofGate::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('failures', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $spec = ['objective' => 'closed_loop_autonomy', 'capability_improvement' => 'closed_loop_autonomy'];
        $a = $this->gate()->verify($spec);
        $b = $this->gate()->verify($spec);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
