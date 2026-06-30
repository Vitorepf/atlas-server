<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEvolutionBenchmarkHarness;
use Tests\TestCase;

final class AtlasExternalBrainEvolutionBenchmarkHarnessTest extends TestCase
{
    private function harness(): AtlasExternalBrainEvolutionBenchmarkHarness
    {
        return new AtlasExternalBrainEvolutionBenchmarkHarness;
    }

    public function test_golden_scenarios_returns_exactly_five(): void
    {
        $scenarios = $this->harness()->goldenScenarios();
        $this->assertCount(5, $scenarios);
    }

    public function test_all_five_golden_scenario_constants_defined(): void
    {
        $scenarios = array_column($this->harness()->goldenScenarios(), 'scenario');

        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_BUG_HUNT_ONLY,      $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_TEMPLATE_FARM,      $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_HONEST_EXHAUSTED,   $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_HIGH_LEVERAGE_ARCH, $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_CROSS_PROJECT,      $scenarios);
    }

    public function test_self_test_all_golden_scenarios_pass(): void
    {
        $result = $this->harness()->runSelfTest();

        $this->assertTrue($result['all_passed'], json_encode($result['results']));
        $this->assertSame(5, $result['total']);
        $this->assertSame(5, $result['passed']);
    }

    public function test_classifies_template_farm_scenario(): void
    {
        $batch = [
            ['objective' => 'Template A', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
            ['objective' => 'Template B', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
            ['objective' => 'Template C', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
            ['objective' => 'Fix bug',    'type' => 'bug_fix',  'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_TEMPLATE_FARM, $result['scenario']);
        $this->assertFalse($result['dimension_results']['template_farm_free']);
        $this->assertArrayHasKey('template_farm_free', $result['dimension_failures']);
    }

    public function test_template_farm_failure_reason_is_not_opaque(): void
    {
        $batch = array_fill(0, 4, [
            'objective' => 'Template', 'type' => 'template', 'leverage' => 'low',
            'is_template_copy' => true, 'projects' => ['atlas-server'], 'claims_evolution' => false,
        ]);

        $result = $this->harness()->evaluate($batch);

        $reason = $result['dimension_failures']['template_farm_free'] ?? '';
        $this->assertStringContainsString('%', $reason);
        $this->assertStringContainsString('40%', $reason);
    }

    public function test_classifies_quota_padding_bug_hunt_only(): void
    {
        $batch = array_fill(0, 5, [
            'objective' => 'Fix bug', 'type' => 'bug_fix', 'leverage' => 'low',
            'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false,
        ]);

        $result = $this->harness()->evaluate($batch);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_BUG_HUNT_ONLY, $result['scenario']);
        $this->assertFalse($result['dimension_results']['quota_padding_free']);
        $this->assertArrayHasKey('quota_padding_free', $result['dimension_failures']);
    }

    public function test_classifies_honest_exhausted_when_batch_empty_and_flag_set(): void
    {
        $result = $this->harness()->evaluate([], honestExhausted: true);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_HONEST_EXHAUSTED, $result['scenario']);
    }

    public function test_empty_batch_without_honest_flag_is_unclassified(): void
    {
        $result = $this->harness()->evaluate([]);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_UNCLASSIFIED, $result['scenario']);
    }

    public function test_classifies_high_leverage_architecture_wave(): void
    {
        $batch = [
            ['objective' => 'Design new arch',            'type' => 'architecture', 'leverage' => 'high',   'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true],
            ['objective' => 'Implement evolution core',   'type' => 'evolution',    'leverage' => 'high',   'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true],
            ['objective' => 'Wire self-improvement loop', 'type' => 'architecture', 'leverage' => 'medium', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_HIGH_LEVERAGE_ARCH, $result['scenario']);
        $this->assertTrue($result['dimension_results']['evolutionary_leap_present']);
        $this->assertTrue($result['dimension_results']['architectural_coverage']);
        $this->assertFalse($result['dimension_results']['cross_project_reach']);
    }

    public function test_classifies_cross_project_evolution_wave(): void
    {
        $batch = [
            ['objective' => 'Brain ctx pack to desktop', 'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-desktop', 'atlas-server'], 'claims_evolution' => true],
            ['objective' => 'Sync memory to mobile',     'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-app'],                     'claims_evolution' => true],
            ['objective' => 'Wire cross-project profile', 'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server', 'atlas-desktop'], 'claims_evolution' => true],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_CROSS_PROJECT, $result['scenario']);
        $this->assertTrue($result['dimension_results']['cross_project_reach']);
        $this->assertTrue($result['dimension_results']['evolutionary_leap_present']);
    }

    public function test_dimension_failures_are_per_dimension_not_opaque_aggregate(): void
    {
        $batch = [
            ['objective' => 'Template', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
            ['objective' => 'Template', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
            ['objective' => 'Template', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertArrayHasKey('dimension_failures', $result);
        $this->assertArrayHasKey('dimension_results',  $result);

        foreach ($result['dimension_failures'] as $dim => $reason) {
            $this->assertIsString($dim,    'failure key must be a dimension name');
            $this->assertIsString($reason, 'failure value must be a string reason');
            $this->assertNotEmpty($reason);
        }
    }

    public function test_output_has_schema_key_matching_constant(): void
    {
        $result = $this->harness()->evaluate([]);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCHEMA, $result['schema']);
    }

    public function test_run_self_test_output_has_canonical_keys(): void
    {
        $result = $this->harness()->runSelfTest();

        foreach (['schema', 'all_passed', 'total', 'passed', 'results'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCHEMA, $result['schema']);
    }

    public function test_catching_template_farm_does_not_require_zero_real_tasks(): void
    {
        // 3 templates out of 4 = 75% > 40% threshold → still a template farm
        $batch = [
            ['objective' => 'Tmpl A', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
            ['objective' => 'Tmpl B', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
            ['objective' => 'Tmpl C', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
            ['objective' => 'Real',   'type' => 'bug_fix',  'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_TEMPLATE_FARM, $result['scenario']);
    }
}
