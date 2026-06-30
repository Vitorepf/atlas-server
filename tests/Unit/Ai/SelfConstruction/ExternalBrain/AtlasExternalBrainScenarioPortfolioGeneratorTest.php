<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScenarioPortfolioGenerator;
use Tests\TestCase;

final class AtlasExternalBrainScenarioPortfolioGeneratorTest extends TestCase
{
    private function gen(): AtlasExternalBrainScenarioPortfolioGenerator
    {
        return new AtlasExternalBrainScenarioPortfolioGenerator;
    }

    public function test_generate_emits_exactly_six_scenarios_by_default(): void
    {
        $result = $this->gen()->generate();

        $this->assertSame(6, $result['total_emitted']);
        $this->assertCount(6, $result['scenarios']);
    }

    public function test_output_has_canonical_keys(): void
    {
        $result = $this->gen()->generate();

        foreach (['schema', 'scenarios', 'rejected_duplicates', 'total_emitted', 'family_coverage'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainScenarioPortfolioGenerator::SCHEMA, $result['schema']);
    }

    public function test_all_six_family_constants_are_covered(): void
    {
        $result   = $this->gen()->generate();
        $families = $result['family_coverage'];

        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_HIGH_YIELD,        $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_LOW_YIELD,         $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_CROSS_PROJECT,     $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_BLOCKED_POISON,    $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_STALE_DOC,         $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_ARCHITECTURE_LEAP, $families);
    }

    public function test_each_scenario_has_required_fields(): void
    {
        $result = $this->gen()->generate();

        foreach ($result['scenarios'] as $scenario) {
            foreach (['scenario_id', 'family', 'description', 'failure_modes', 'evidence_inputs', 'success_criteria'] as $field) {
                $this->assertArrayHasKey($field, $scenario, "Missing field '{$field}' in scenario '{$scenario['scenario_id']}'");
            }
            $this->assertIsArray($scenario['failure_modes'],    "failure_modes must be array");
            $this->assertNotEmpty($scenario['failure_modes'],   "failure_modes must not be empty");
            $this->assertIsArray($scenario['evidence_inputs'],  "evidence_inputs must be array");
            $this->assertIsArray($scenario['success_criteria'], "success_criteria must be array");
            $this->assertNotEmpty($scenario['success_criteria'], "success_criteria must not be empty");
        }
    }

    public function test_no_rejected_duplicates_in_base_portfolio(): void
    {
        $result = $this->gen()->generate();

        $this->assertSame([], $result['rejected_duplicates']);
    }

    public function test_rejects_duplicate_scenario_with_only_renamed_id(): void
    {
        $result   = $this->gen()->generate();
        $original = $result['scenarios'][0];

        // Same structure, only scenario_id differs
        $duplicate                = $original;
        $duplicate['scenario_id'] = 'scenario:renamed_copy:v1';

        $result2 = $this->gen()->generate([$duplicate]);

        $this->assertCount(1, $result2['rejected_duplicates']);
        $this->assertSame('scenario:renamed_copy:v1', $result2['rejected_duplicates'][0]['scenario_id']);
        $this->assertStringContainsString('duplicate_structure', $result2['rejected_duplicates'][0]['reason']);
        $this->assertSame(6, $result2['total_emitted']); // still 6, not 7
    }

    public function test_accepts_genuinely_new_scenario_as_seventh(): void
    {
        $newScenario = [
            'scenario_id'     => 'scenario:custom_new:v1',
            'family'          => 'custom_family',
            'description'     => 'A genuinely new custom scenario.',
            'failure_modes'   => ['unique_failure_mode_xyz'],
            'evidence_inputs' => ['custom_input' => true],
            'success_criteria' => ['unique_success_criterion_xyz'],
        ];

        $result = $this->gen()->generate([$newScenario]);

        $this->assertSame(7, $result['total_emitted']);
        $this->assertSame([], $result['rejected_duplicates']);
        $this->assertContains('custom_family', $result['family_coverage']);
    }

    public function test_high_yield_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_HIGH_YIELD);

        $this->assertContains('quota_padding',                   $scenario['failure_modes']);
        $this->assertContains('false_high_leverage_claim',       $scenario['failure_modes']);
    }

    public function test_low_yield_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_LOW_YIELD);

        $this->assertContains('bug_hunt_only',       $scenario['failure_modes']);
        $this->assertContains('no_evolutionary_leap', $scenario['failure_modes']);
    }

    public function test_blocked_poison_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_BLOCKED_POISON);

        $this->assertContains('queue_jam',       $scenario['failure_modes']);
        $this->assertContains('give_back_loop',  $scenario['failure_modes']);
        $this->assertContains('stalled_yield',   $scenario['failure_modes']);
    }

    public function test_stale_doc_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_STALE_DOC);

        $this->assertContains('doc_drift',                         $scenario['failure_modes']);
        $this->assertContains('certification_blocked_by_stale_doc', $scenario['failure_modes']);
    }

    public function test_architecture_leap_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_ARCHITECTURE_LEAP);

        $this->assertContains('incremental_not_leap',               $scenario['failure_modes']);
        $this->assertContains('goodhart_proxy',                     $scenario['failure_modes']);
        $this->assertContains('capability_delta_vague_or_missing',  $scenario['failure_modes']);
    }

    public function test_cross_project_scenario_evidence_mentions_multiple_projects(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_CROSS_PROJECT);

        $this->assertArrayHasKey('distinct_project_count', $scenario['evidence_inputs']);
        $this->assertGreaterThan(1, $scenario['evidence_inputs']['distinct_project_count']);
        $this->assertIsArray($scenario['evidence_inputs']['projects']);
        $this->assertGreaterThan(1, count($scenario['evidence_inputs']['projects']));
    }

    public function test_duplicate_rejection_preserves_original_six_in_accepted_list(): void
    {
        $result = $this->gen()->generate();
        // Add two duplicates of two different scenarios
        $dup1 = array_merge($result['scenarios'][0], ['scenario_id' => 'dup_1']);
        $dup2 = array_merge($result['scenarios'][1], ['scenario_id' => 'dup_2']);

        $result2 = $this->gen()->generate([$dup1, $dup2]);

        $this->assertSame(6, $result2['total_emitted']);
        $this->assertCount(2, $result2['rejected_duplicates']);
        $rejectedIds = array_column($result2['rejected_duplicates'], 'scenario_id');
        $this->assertContains('dup_1', $rejectedIds);
        $this->assertContains('dup_2', $rejectedIds);
    }

    // ---- helpers ----

    private function findByFamily(array $scenarios, string $family): array
    {
        foreach ($scenarios as $scenario) {
            if ($scenario['family'] === $family) {
                return $scenario;
            }
        }
        $this->fail("No scenario found for family '{$family}'");
    }
}
