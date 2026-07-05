<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Autonomy;

use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionManualDependencyBurnDownPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionManualDependencyBurnDownPlannerTest extends TestCase
{
    private AtlasSelfConstructionManualDependencyBurnDownPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasSelfConstructionManualDependencyBurnDownPlanner;
    }

    // ── AC: manual-only dependencies are flagged ──

    public function test_manual_only_dependency_is_flagged(): void
    {
        $result = $this->planner->plan([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'dependencies' => [
                [
                    'name' => 'manual_git_tag',
                    'surface' => 'release',
                    'requires_manual_step' => true,
                    'bootstrap_only' => false,
                    'has_runtime_alternative' => false,
                    'current_manual_step' => 'operator runs git tag',
                    'proposed_runtime_alternative' => 'autonomous tag service',
                ],
            ],
        ]);

        $this->assertSame(1, $result['burn_down_count']);
        $this->assertTrue($result['has_manual_only_dependencies']);
        $this->assertSame('manual_git_tag', $result['flagged_dependencies'][0]['name']);
        $this->assertSame(AtlasSelfConstructionManualDependencyBurnDownPlanner::KIND_MANUAL_ONLY, $result['flagged_dependencies'][0]['kind']);
    }

    // ── AC: bootstrap-only dependencies are allowed ──

    public function test_bootstrap_only_dependency_is_allowed(): void
    {
        $result = $this->planner->plan([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'dependencies' => [
                [
                    'name' => 'initial_secrets',
                    'surface' => 'bootstrap',
                    'requires_manual_step' => true,
                    'bootstrap_only' => true,
                    'has_runtime_alternative' => false,
                ],
            ],
        ]);

        $this->assertSame(0, $result['burn_down_count']);
        $this->assertFalse($result['has_manual_only_dependencies']);
        $this->assertSame('initial_secrets', $result['allowed_dependencies'][0]['name']);
    }

    // ── AC: burn-down specs include implementation plus test scope ──

    public function test_burn_down_spec_has_implementation_and_test_scope(): void
    {
        $result = $this->planner->plan([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'dependencies' => [
                [
                    'name' => 'manual_health_check',
                    'surface' => 'runtime',
                    'requires_manual_step' => true,
                    'bootstrap_only' => false,
                    'has_runtime_alternative' => false,
                    'current_manual_step' => 'operator reads dashboard',
                    'proposed_runtime_alternative' => 'automated health probe',
                ],
            ],
        ]);

        $spec = $result['burn_down_specs'][0];
        $this->assertNotEmpty($spec['objective']);
        $this->assertCount(2, $spec['allowed_files']);
        $this->assertStringContainsString('AtlasManualHealthCheckAutomation.php', $spec['allowed_files'][0]);
        $this->assertStringContainsString('AtlasManualHealthCheckAutomationTest.php', $spec['allowed_files'][1]);
        $this->assertCount(2, $spec['acceptance_criteria']);
        $this->assertContains('tests_or_gates_result', $spec['required_evidence']);
    }

    // ── AC: manual dependencies with runtime alternatives are allowed ──

    public function test_manual_dependency_with_runtime_alternative_is_allowed(): void
    {
        $result = $this->planner->plan([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'dependencies' => [
                [
                    'name' => 'manual_log_rotation',
                    'surface' => 'ops',
                    'requires_manual_step' => true,
                    'bootstrap_only' => false,
                    'has_runtime_alternative' => true,
                ],
            ],
        ]);

        $this->assertSame(0, $result['burn_down_count']);
        $this->assertSame('manual_log_rotation', $result['allowed_dependencies'][0]['name']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->planner->plan([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'dependencies' => [],
        ]);

        $this->assertSame(AtlasSelfConstructionManualDependencyBurnDownPlanner::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('burn_down_specs', $result);
        $this->assertArrayHasKey('flagged_dependencies', $result);
        $this->assertArrayHasKey('allowed_dependencies', $result);
        $this->assertArrayHasKey('burn_down_count', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'dependencies' => [
                [
                    'name' => 'manual_git_tag',
                    'surface' => 'release',
                    'requires_manual_step' => true,
                    'bootstrap_only' => false,
                    'has_runtime_alternative' => false,
                ],
            ],
        ];

        $a = $this->planner->plan($input);
        $b = $this->planner->plan($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
