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

    public function test_golden_scenarios_returns_exactly_nine(): void
    {
        $this->assertCount(9, $this->harness()->goldenScenarios());
    }

    public function test_all_nine_golden_scenario_constants_defined(): void
    {
        $scenarios = array_column($this->harness()->goldenScenarios(), 'scenario');

        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_BUG_HUNT_ONLY,            $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_TEMPLATE_FARM,            $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_HONEST_EXHAUSTED,         $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_HIGH_LEVERAGE_ARCH,       $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_CROSS_PROJECT,            $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_PROOF_WEIGHTED_LEVERAGE,  $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_QUEUE_PRESSURE,           $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_SECOND_PASS_BREAKTHROUGH, $scenarios);
        $this->assertContains(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_FINALITY_FLOOR,           $scenarios);
    }

    public function test_self_test_all_golden_scenarios_pass(): void
    {
        $result = $this->harness()->runSelfTest();

        $this->assertTrue($result['all_passed'], json_encode($result['results']));
        $this->assertSame(9, $result['total']);
        $this->assertSame(9, $result['passed']);
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
            ['objective' => 'Brain ctx pack to desktop',  'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-desktop', 'atlas-server'], 'claims_evolution' => true],
            ['objective' => 'Sync memory to mobile',      'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-app'],                     'claims_evolution' => true],
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
            ['objective' => 'Template', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true, 'projects' => ['atlas-server'], 'claims_evolution' => false],
            ['objective' => 'Template', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true, 'projects' => ['atlas-server'], 'claims_evolution' => false],
            ['objective' => 'Template', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true, 'projects' => ['atlas-server'], 'claims_evolution' => false],
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
        $batch = [
            ['objective' => 'Tmpl A', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
            ['objective' => 'Tmpl B', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
            ['objective' => 'Tmpl C', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
            ['objective' => 'Real',   'type' => 'bug_fix',  'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_TEMPLATE_FARM, $result['scenario']);
    }

    // ── regression: proof_weighted_leverage ──────────────────────────────────

    public function test_classifies_proof_weighted_leverage_when_high_leverage_lacks_proof(): void
    {
        $batch = [
            ['objective' => 'Design compounding layer', 'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_proof' => false],
            ['objective' => 'Implement evidence v2',    'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_proof' => false],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_PROOF_WEIGHTED_LEVERAGE, $result['scenario']);
        $this->assertFalse($result['dimension_results']['proof_weighted_leverage']);
        $this->assertArrayHasKey('proof_weighted_leverage', $result['dimension_failures']);
        $this->assertStringContainsString('proof', $result['dimension_failures']['proof_weighted_leverage']);
    }

    public function test_proof_weighted_leverage_passes_when_no_high_leverage_tasks(): void
    {
        $batch = [
            ['objective' => 'Fix bug', 'type' => 'bug_fix', 'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertTrue($result['dimension_results']['proof_weighted_leverage']);
        $this->assertArrayNotHasKey('proof_weighted_leverage', $result['dimension_failures']);
    }

    public function test_proof_weighted_leverage_passes_when_at_least_one_high_leverage_has_proof(): void
    {
        $batch = [
            ['objective' => 'Task with proof',    'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_proof' => true],
            ['objective' => 'Task without proof', 'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_proof' => false],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertTrue($result['dimension_results']['proof_weighted_leverage']);
    }

    // ── regression: queue_pressure ───────────────────────────────────────────

    public function test_classifies_queue_pressure_when_majority_are_queue_driven(): void
    {
        $batch = array_fill(0, 4, [
            'objective' => 'Drain queue', 'type' => 'bug_fix', 'leverage' => 'low',
            'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false,
            'is_queue_driven' => true,
        ]);

        $result = $this->harness()->evaluate($batch);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_QUEUE_PRESSURE, $result['scenario']);
        $this->assertFalse($result['dimension_results']['queue_pressure_free']);
        $this->assertArrayHasKey('queue_pressure_free', $result['dimension_failures']);
        $this->assertStringContainsString('50%', $result['dimension_failures']['queue_pressure_free']);
    }

    public function test_queue_pressure_free_passes_when_below_threshold(): void
    {
        // 1 of 4 queue-driven = 25% — below 50% threshold
        $batch = [
            ['objective' => 'Real arch task', 'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'is_queue_driven' => false],
            ['objective' => 'Real evo task',  'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'is_queue_driven' => false],
            ['objective' => 'Real bug fix',   'type' => 'bug_fix',      'leverage' => 'low',  'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false, 'is_queue_driven' => false],
            ['objective' => 'Queue filler',   'type' => 'bug_fix',      'leverage' => 'low',  'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false, 'is_queue_driven' => true],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertTrue($result['dimension_results']['queue_pressure_free']);
        $this->assertArrayNotHasKey('queue_pressure_free', $result['dimension_failures']);
    }

    // ── regression: second_pass_breakthrough ─────────────────────────────────

    public function test_classifies_second_pass_breakthrough(): void
    {
        $batch = [
            ['objective' => 'Cross-codebase scan finds wiring gap', 'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'is_second_pass' => true, 'has_proof' => true],
            ['objective' => 'Journal harvest finds 3 proofs',       'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'is_second_pass' => true, 'has_proof' => true],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_SECOND_PASS_BREAKTHROUGH, $result['scenario']);
        $this->assertTrue($result['dimension_results']['second_pass_present']);
        $this->assertTrue($result['dimension_results']['evolutionary_leap_present']);
    }

    public function test_second_pass_present_false_when_no_second_pass_task(): void
    {
        $batch = [
            ['objective' => 'Normal arch task', 'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertFalse($result['dimension_results']['second_pass_present']);
    }

    public function test_second_pass_failure_message_is_explicit(): void
    {
        $batch = [
            ['objective' => 'Normal task', 'type' => 'bug_fix', 'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertArrayHasKey('second_pass_present', $result['dimension_failures']);
        $this->assertStringContainsString('second_pass', $result['dimension_failures']['second_pass_present']);
    }

    // ── regression: finality_floor ───────────────────────────────────────────

    public function test_classifies_finality_floor_when_no_task_is_certified(): void
    {
        $batch = [
            ['objective' => 'Design governor upgrade',   'type' => 'architecture', 'leverage' => 'high',   'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_certification' => false],
            ['objective' => 'Implement capability v3',   'type' => 'evolution',    'leverage' => 'medium', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_certification' => false],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_FINALITY_FLOOR, $result['scenario']);
        $this->assertFalse($result['dimension_results']['finality_floor_met']);
        $this->assertArrayHasKey('finality_floor_met', $result['dimension_failures']);
    }

    public function test_finality_floor_met_by_default_when_field_absent(): void
    {
        $batch = [
            ['objective' => 'Normal arch task', 'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertTrue($result['dimension_results']['finality_floor_met']);
        $this->assertArrayNotHasKey('finality_floor_met', $result['dimension_failures']);
    }

    public function test_finality_floor_met_when_at_least_one_task_is_certified(): void
    {
        $batch = [
            ['objective' => 'Certified task',     'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_certification' => true],
            ['objective' => 'Uncertified task',   'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_certification' => false],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertTrue($result['dimension_results']['finality_floor_met']);
    }

    // ── regression: per-dimension failure messages for new cases ─────────────

    public function test_new_regression_dimensions_produce_string_failure_messages(): void
    {
        // Batch that triggers queue_pressure and finality_floor failures.
        $batch = [
            ['objective' => 'Queue item', 'type' => 'bug_fix', 'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false, 'is_queue_driven' => true, 'has_certification' => false],
            ['objective' => 'Queue item', 'type' => 'bug_fix', 'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false, 'is_queue_driven' => true, 'has_certification' => false],
        ];

        $result = $this->harness()->evaluate($batch);

        foreach ($result['dimension_failures'] as $dim => $msg) {
            $this->assertIsString($msg, "dimension {$dim} failure must be a string");
            $this->assertNotEmpty($msg);
        }
    }

    // ── AC1: template-width tasks fail the benchmark ─────────────────────────

    public function test_template_width_candidate_fails_the_benchmark(): void
    {
        $batch = array_fill(0, 4, [
            'objective' => 'Add CRUD scaffold', 'type' => 'template', 'leverage' => 'low',
            'is_template_copy' => true, 'projects' => ['atlas-server'], 'claims_evolution' => false,
        ]);

        $result = $this->harness()->evaluate($batch);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_TEMPLATE_FARM, $result['scenario']);
        $this->assertFalse($result['dimension_results']['template_farm_free']);
        $this->assertNotEmpty($result['dimension_failures']);
    }

    // ── AC2: repairs malformed queue inputs into grounded high-value tasks passes ──

    public function test_queue_healing_candidate_producing_grounded_high_value_tasks_passes(): void
    {
        $batch = [
            [
                'objective' => 'Repair malformed queue packet into concrete architecture task',
                'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false,
                'projects' => ['atlas-server'], 'claims_evolution' => true,
                'is_queue_driven' => true, 'repairs_malformed_queue_input' => true,
                'is_grounded' => true, 'has_proof' => true, 'has_certification' => true,
            ],
            [
                'objective' => 'Repair second malformed queue packet into evolution task',
                'type' => 'evolution', 'leverage' => 'high', 'is_template_copy' => false,
                'projects' => ['atlas-server'], 'claims_evolution' => true,
                'is_queue_driven' => true, 'repairs_malformed_queue_input' => true,
                'is_grounded' => true, 'has_proof' => true, 'has_certification' => true,
            ],
        ];

        $result = $this->harness()->evaluate($batch);

        $this->assertTrue($result['dimension_results']['queue_pressure_free']);
        $this->assertTrue($result['dimension_results']['evolutionary_leap_present']);
        $this->assertTrue($result['dimension_results']['proof_weighted_leverage']);
        $this->assertTrue($result['dimension_results']['finality_floor_met']);
        $this->assertArrayNotHasKey('queue_pressure_free', $result['dimension_failures']);
        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_HIGH_LEVERAGE_ARCH, $result['scenario']);
    }

    public function test_queue_driven_task_without_repair_evidence_still_counts_as_pressure(): void
    {
        $batch = array_fill(0, 4, [
            'objective' => 'Drain queue filler', 'type' => 'bug_fix', 'leverage' => 'low',
            'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false,
            'is_queue_driven' => true,
        ]);

        $result = $this->harness()->evaluate($batch);

        $this->assertFalse($result['dimension_results']['queue_pressure_free']);
        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_QUEUE_PRESSURE, $result['scenario']);
    }

    public function test_queue_driven_task_claiming_repair_but_not_grounded_still_counts_as_pressure(): void
    {
        $batch = array_fill(0, 4, [
            'objective' => 'Repair claim without grounding', 'type' => 'bug_fix', 'leverage' => 'high',
            'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false,
            'is_queue_driven' => true, 'repairs_malformed_queue_input' => true, 'is_grounded' => false,
        ]);

        $result = $this->harness()->evaluate($batch);

        $this->assertFalse($result['dimension_results']['queue_pressure_free']);
    }

    // ── AC3: honest exhaustion accepted only when all exhaustion paths are exhausted ──

    public function test_honest_exhaustion_accepted_when_all_paths_explicitly_exhausted(): void
    {
        $result = $this->harness()->evaluate([], true, [
            'research_exhausted' => true,
            'simplification_exhausted' => true,
            'second_pass_exhausted' => true,
        ]);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_HONEST_EXHAUSTED, $result['scenario']);
        $this->assertTrue($result['dimension_results']['honest_exhaustion_accepted']);
    }

    public function test_honest_exhaustion_rejected_when_research_path_not_exhausted(): void
    {
        $result = $this->harness()->evaluate([], true, [
            'research_exhausted' => false,
            'simplification_exhausted' => true,
            'second_pass_exhausted' => true,
        ]);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_PREMATURE_EXHAUSTION_CLAIM, $result['scenario']);
        $this->assertFalse($result['dimension_results']['honest_exhaustion_accepted']);
        $this->assertStringContainsString('research_exhausted', $result['dimension_failures']['honest_exhaustion_accepted']);
    }

    public function test_honest_exhaustion_rejected_when_second_pass_path_not_exhausted(): void
    {
        $result = $this->harness()->evaluate([], true, [
            'research_exhausted' => true,
            'simplification_exhausted' => true,
            'second_pass_exhausted' => false,
        ]);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_PREMATURE_EXHAUSTION_CLAIM, $result['scenario']);
        $this->assertStringContainsString('second_pass_exhausted', $result['dimension_failures']['honest_exhaustion_accepted']);
    }

    public function test_honest_exhaustion_without_exhaustion_proof_argument_defaults_to_accepted(): void
    {
        // Backward-compatible default: omitting the argument entirely keeps prior behavior.
        $result = $this->harness()->evaluate([], honestExhausted: true);

        $this->assertSame(AtlasExternalBrainEvolutionBenchmarkHarness::SCENARIO_HONEST_EXHAUSTED, $result['scenario']);
    }
}
