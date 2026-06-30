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

    public function test_generate_emits_twelve_scenarios_by_default(): void
    {
        $result = $this->gen()->generate();

        $this->assertSame(12, $result['total_emitted']);
        $this->assertCount(12, $result['scenarios']);
    }

    public function test_output_has_canonical_keys(): void
    {
        $result = $this->gen()->generate();

        foreach (['schema', 'scenarios', 'rejected_duplicates', 'total_emitted', 'family_coverage', 'missing_scenario_families'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainScenarioPortfolioGenerator::SCHEMA, $result['schema']);
    }

    public function test_all_twelve_family_constants_are_covered(): void
    {
        $result   = $this->gen()->generate();
        $families = $result['family_coverage'];

        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_HIGH_YIELD,          $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_LOW_YIELD,           $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_ADVERSARIAL,         $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_CROSS_PROJECT,       $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_BLOCKED_POISON,      $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_STALE_DOC,           $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_ARCHITECTURE_LEAP,   $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_SUCCESS_LOW_IMPACT,  $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_GIVE_BACK_DIAGNOSTIC, $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_QUARANTINE_RESPEC,   $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_PROXY_GREEN_COMMIT,  $families);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_MODEL_TIER_FAILURE,  $families);
    }

    public function test_each_scenario_has_required_fields(): void
    {
        $result = $this->gen()->generate();

        foreach ($result['scenarios'] as $scenario) {
            foreach (['scenario_id', 'family', 'description', 'failure_modes', 'evidence_inputs', 'success_criteria'] as $field) {
                $this->assertArrayHasKey($field, $scenario, "Missing field '{$field}' in scenario '{$scenario['scenario_id']}'");
            }
            $this->assertIsArray($scenario['failure_modes'],    'failure_modes must be array');
            $this->assertNotEmpty($scenario['failure_modes'],   'failure_modes must not be empty');
            $this->assertIsArray($scenario['evidence_inputs'],  'evidence_inputs must be array');
            $this->assertIsArray($scenario['success_criteria'], 'success_criteria must be array');
            $this->assertNotEmpty($scenario['success_criteria'], 'success_criteria must not be empty');
        }
    }

    public function test_no_rejected_duplicates_in_base_portfolio(): void
    {
        $result = $this->gen()->generate();

        $this->assertSame([], $result['rejected_duplicates']);
    }

    public function test_missing_scenario_families_empty_when_all_canonical_families_covered(): void
    {
        $result = $this->gen()->generate();

        $this->assertSame([], $result['missing_scenario_families']);
    }

    public function test_rejects_duplicate_scenario_with_only_renamed_id(): void
    {
        $result   = $this->gen()->generate();
        $original = $result['scenarios'][0];

        $duplicate                = $original;
        $duplicate['scenario_id'] = 'scenario:renamed_copy:v1';

        $result2 = $this->gen()->generate([$duplicate]);

        $this->assertCount(1, $result2['rejected_duplicates']);
        $this->assertSame('scenario:renamed_copy:v1', $result2['rejected_duplicates'][0]['scenario_id']);
        $this->assertStringContainsString('duplicate_structure', $result2['rejected_duplicates'][0]['reason']);
        $this->assertSame(12, $result2['total_emitted']);
    }

    public function test_accepts_genuinely_new_scenario(): void
    {
        $newScenario = [
            'scenario_id'      => 'scenario:custom_new:v1',
            'family'           => 'custom_family',
            'description'      => 'A genuinely new custom scenario.',
            'failure_modes'    => ['unique_failure_mode_xyz'],
            'evidence_inputs'  => ['custom_input' => true],
            'success_criteria' => ['unique_success_criterion_xyz'],
        ];

        $result = $this->gen()->generate([$newScenario]);

        $this->assertSame(13, $result['total_emitted']);
        $this->assertSame([], $result['rejected_duplicates']);
        $this->assertContains('custom_family', $result['family_coverage']);
    }

    public function test_high_yield_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_HIGH_YIELD);

        $this->assertContains('quota_padding',             $scenario['failure_modes']);
        $this->assertContains('false_high_leverage_claim', $scenario['failure_modes']);
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

        $this->assertContains('queue_jam',     $scenario['failure_modes']);
        $this->assertContains('give_back_loop', $scenario['failure_modes']);
        $this->assertContains('stalled_yield', $scenario['failure_modes']);
    }

    public function test_stale_doc_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_STALE_DOC);

        $this->assertContains('doc_drift',                          $scenario['failure_modes']);
        $this->assertContains('certification_blocked_by_stale_doc', $scenario['failure_modes']);
    }

    public function test_architecture_leap_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_ARCHITECTURE_LEAP);

        $this->assertContains('incremental_not_leap',              $scenario['failure_modes']);
        $this->assertContains('goodhart_proxy',                    $scenario['failure_modes']);
        $this->assertContains('capability_delta_vague_or_missing', $scenario['failure_modes']);
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

    public function test_duplicate_rejection_preserves_original_twelve_in_accepted_list(): void
    {
        $result = $this->gen()->generate();
        $dup1   = array_merge($result['scenarios'][0], ['scenario_id' => 'dup_1']);
        $dup2   = array_merge($result['scenarios'][1], ['scenario_id' => 'dup_2']);

        $result2 = $this->gen()->generate([$dup1, $dup2]);

        $this->assertSame(12, $result2['total_emitted']);
        $this->assertCount(2, $result2['rejected_duplicates']);
        $rejectedIds = array_column($result2['rejected_duplicates'], 'scenario_id');
        $this->assertContains('dup_1', $rejectedIds);
        $this->assertContains('dup_2', $rejectedIds);
    }

    public function test_adversarial_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_ADVERSARIAL);

        $this->assertContains('hallucinated_evidence',            $scenario['failure_modes']);
        $this->assertContains('goodhart_proxy_disguised_as_leap', $scenario['failure_modes']);
        $this->assertContains('false_positive_capability_claim',  $scenario['failure_modes']);
    }

    public function test_each_scenario_declares_evidence_floor_expected_failure_mode_capability_delta(): void
    {
        $result = $this->gen()->generate();

        foreach ($result['scenarios'] as $scenario) {
            $id = $scenario['scenario_id'];
            $this->assertArrayHasKey('evidence_floor',        $scenario, "Missing evidence_floor in {$id}");
            $this->assertArrayHasKey('expected_failure_mode', $scenario, "Missing expected_failure_mode in {$id}");
            $this->assertArrayHasKey('capability_delta',      $scenario, "Missing capability_delta in {$id}");
            $this->assertNotEmpty($scenario['evidence_floor'],        "evidence_floor must not be empty in {$id}");
            $this->assertNotEmpty($scenario['expected_failure_mode'], "expected_failure_mode must not be empty in {$id}");
            $this->assertNotEmpty($scenario['capability_delta'],      "capability_delta must not be empty in {$id}");
        }
    }

    // ── new post-muscle outcome families ─────────────────────────────────────

    public function test_success_low_impact_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_SUCCESS_LOW_IMPACT);

        $this->assertContains('proxy_success_accepted_as_real', $scenario['failure_modes']);
        $this->assertContains('low_leverage_disguised_as_success', $scenario['failure_modes']);
    }

    public function test_success_low_impact_scenario_evidence_inputs_show_green_but_low_delta(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_SUCCESS_LOW_IMPACT);

        $this->assertSame('success', $scenario['evidence_inputs']['task_outcome']);
        $this->assertTrue($scenario['evidence_inputs']['tests_green']);
        $this->assertLessThan(0.15, $scenario['evidence_inputs']['capability_delta_score']);
    }

    public function test_give_back_diagnostic_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_GIVE_BACK_DIAGNOSTIC);

        $this->assertContains('give_back_without_diagnosis', $scenario['failure_modes']);
        $this->assertContains('root_cause_missing_from_report', $scenario['failure_modes']);
    }

    public function test_give_back_diagnostic_scenario_evidence_inputs_reflect_give_back_outcome(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_GIVE_BACK_DIAGNOSTIC);

        $this->assertSame('give_back', $scenario['evidence_inputs']['task_outcome']);
        $this->assertTrue($scenario['evidence_inputs']['diagnostic_emitted']);
    }

    public function test_quarantine_respec_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_QUARANTINE_RESPEC);

        $this->assertContains('respec_too_similar_to_original', $scenario['failure_modes']);
        $this->assertContains('quarantine_without_respec', $scenario['failure_modes']);
    }

    public function test_quarantine_respec_scenario_evidence_inputs_show_quarantine_count(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_QUARANTINE_RESPEC);

        $this->assertArrayHasKey('quarantine_count', $scenario['evidence_inputs']);
        $this->assertGreaterThanOrEqual(2, $scenario['evidence_inputs']['quarantine_count']);
    }

    public function test_proxy_green_commit_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_PROXY_GREEN_COMMIT);

        $this->assertContains('green_tests_mask_zero_impact', $scenario['failure_modes']);
        $this->assertContains('commit_rate_proxy_gaming', $scenario['failure_modes']);
    }

    public function test_proxy_green_commit_scenario_evidence_shows_green_but_zero_impact(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_PROXY_GREEN_COMMIT);

        $this->assertTrue($scenario['evidence_inputs']['tests_green']);
        $this->assertSame('fail', $scenario['evidence_inputs']['anti_goodhart_verdict']);
        $this->assertLessThanOrEqual(0.05, $scenario['evidence_inputs']['impact_score']);
    }

    public function test_model_tier_failure_scenario_has_expected_failure_modes(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_MODEL_TIER_FAILURE);

        $this->assertContains('insufficient_model_tier', $scenario['failure_modes']);
        $this->assertContains('silent_quality_degradation', $scenario['failure_modes']);
    }

    public function test_model_tier_failure_scenario_evidence_shows_small_tier_high_complexity(): void
    {
        $result   = $this->gen()->generate();
        $scenario = $this->findByFamily($result['scenarios'], AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_MODEL_TIER_FAILURE);

        $this->assertSame('small', $scenario['evidence_inputs']['model_tier']);
        $this->assertSame('high',  $scenario['evidence_inputs']['task_complexity']);
        $this->assertLessThan(0.50, $scenario['evidence_inputs']['output_quality']);
    }

    // ── assessPortfolio: missing scenario family detection ────────────────────

    public function test_assess_portfolio_reports_missing_families_for_narrow_portfolio(): void
    {
        $narrowPortfolio = [
            ['scenario_id' => 'sc:1', 'family' => AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_HIGH_YIELD],
            ['scenario_id' => 'sc:2', 'family' => AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_ADVERSARIAL],
        ];

        $result = $this->gen()->assessPortfolio($narrowPortfolio);

        $this->assertTrue($result['too_narrow']);
        $this->assertNotEmpty($result['missing_scenario_families']);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_SUCCESS_LOW_IMPACT,   $result['missing_scenario_families']);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_GIVE_BACK_DIAGNOSTIC, $result['missing_scenario_families']);
        $this->assertContains(AtlasExternalBrainScenarioPortfolioGenerator::FAMILY_MODEL_TIER_FAILURE,   $result['missing_scenario_families']);
    }

    public function test_assess_portfolio_not_narrow_when_all_canonical_families_covered(): void
    {
        $full = array_map(
            static fn (string $f): array => ['scenario_id' => 'sc:'.$f, 'family' => $f],
            AtlasExternalBrainScenarioPortfolioGenerator::CANONICAL_FAMILIES,
        );

        $result = $this->gen()->assessPortfolio($full);

        $this->assertFalse($result['too_narrow']);
        $this->assertSame([], $result['missing_scenario_families']);
    }

    public function test_assess_portfolio_output_has_required_keys(): void
    {
        $result = $this->gen()->assessPortfolio([]);

        foreach (['required_families', 'covered_families', 'missing_scenario_families', 'too_narrow'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_assess_portfolio_empty_input_missing_all_canonical_families(): void
    {
        $result = $this->gen()->assessPortfolio([]);

        $this->assertTrue($result['too_narrow']);
        $this->assertSame(count(AtlasExternalBrainScenarioPortfolioGenerator::CANONICAL_FAMILIES), count($result['missing_scenario_families']));
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
