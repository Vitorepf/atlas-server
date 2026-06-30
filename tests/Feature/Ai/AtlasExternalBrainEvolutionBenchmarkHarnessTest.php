<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEvolutionBenchmarkHarness;
use Tests\TestCase;

/**
 * Feature-level gate for the AtlasExternalBrainEvolutionBenchmarkHarness.
 *
 * Verifies the golden scenario set includes the four regression dimensions
 * (proof_weighted_leverage, queue_pressure, second_pass_breakthrough,
 * finality_floor) and that batches failing those dimensions classify correctly.
 */
final class AtlasExternalBrainEvolutionBenchmarkHarnessTest extends TestCase
{
    private function harness(): AtlasExternalBrainEvolutionBenchmarkHarness
    {
        return new AtlasExternalBrainEvolutionBenchmarkHarness;
    }

    public function test_golden_scenarios_include_four_regression_cases(): void
    {
        $scenarios = array_column($this->harness()->goldenScenarios(), 'scenario');

        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_PROOF_WEIGHTED_LEVERAGE,  $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_QUEUE_PRESSURE,           $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_SECOND_PASS_BREAKTHROUGH, $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_FINALITY_FLOOR,           $scenarios);
    }

    public function test_self_test_all_golden_scenarios_pass(): void
    {
        $result = $this->harness()->runSelfTest();

        $this->assertTrue($result['all_passed'], json_encode($result['results']));
    }

    // ── proof_weighted_leverage ───────────────────────────────────────────────

    public function test_high_leverage_architecture_batch_without_proof_fails_dimension(): void
    {
        $batch = [
            ['objective' => 'Design compounding layer', 'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_proof' => false],
            ['objective' => 'Implement evidence v2',    'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_proof' => false],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertFalse($result['dimension_results']['proof_weighted_leverage']);
        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_PROOF_WEIGHTED_LEVERAGE, $result['scenario']);
        $this->assertArrayHasKey('proof_weighted_leverage', $result['dimension_failures']);
    }

    // ── queue_pressure ────────────────────────────────────────────────────────

    public function test_queue_driven_low_leverage_batch_fails_queue_pressure_free(): void
    {
        $batch = array_fill(0, 5, [
            'objective' => 'Drain queue item', 'type' => 'bug_fix', 'leverage' => 'low',
            'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false,
            'is_queue_driven' => true,
        ]);

        $result = $this->harness()->evaluate($batch);

        $this->assertFalse($result['dimension_results']['queue_pressure_free']);
        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_QUEUE_PRESSURE, $result['scenario']);
        $this->assertArrayHasKey('queue_pressure_free', $result['dimension_failures']);
    }

    // ── second_pass_breakthrough ──────────────────────────────────────────────

    public function test_second_pass_breakthrough_classifies_correctly(): void
    {
        $batch = [
            ['objective' => 'Cross-codebase scan finds wiring gap', 'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'is_second_pass' => true, 'has_proof' => true],
            ['objective' => 'Journal harvest finds 3 proofs',       'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'is_second_pass' => true, 'has_proof' => true],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertTrue($result['dimension_results']['second_pass_present']);
        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_SECOND_PASS_BREAKTHROUGH, $result['scenario']);
    }

    // ── finality_floor ────────────────────────────────────────────────────────

    public function test_finality_floor_fails_when_no_task_is_certified(): void
    {
        $batch = [
            ['objective' => 'Design governor upgrade', 'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_certification' => false],
            ['objective' => 'Implement capability v3', 'type' => 'evolution',    'leverage' => 'medium', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_certification' => false],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertFalse($result['dimension_results']['finality_floor_met']);
        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_FINALITY_FLOOR, $result['scenario']);
        $this->assertArrayHasKey('finality_floor_met', $result['dimension_failures']);
    }
}
