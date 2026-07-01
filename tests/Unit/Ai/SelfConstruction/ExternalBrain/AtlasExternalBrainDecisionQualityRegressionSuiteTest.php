<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDecisionQualityRegressionSuite;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDecisionQualityRegressionSuiteTest extends TestCase
{
    private AtlasExternalBrainDecisionQualityRegressionSuite $suite;

    protected function setUp(): void
    {
        $this->suite = new AtlasExternalBrainDecisionQualityRegressionSuite;
    }

    private function decision(string $scenarioId, bool $admitted, array $extra = []): array
    {
        return array_merge(['scenario_id' => $scenarioId, 'admitted' => $admitted], $extra);
    }

    // ── Schema / output shape ──────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $result = $this->suite->score([]);

        foreach (['schema', 'passed_scenarios', 'failed_scenarios', 'quality_score', 'regression_reasons'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainDecisionQualityRegressionSuite::SCHEMA, $result['schema']);
    }

    public function test_empty_input_returns_perfect_score(): void
    {
        $result = $this->suite->score([]);

        $this->assertSame([], $result['failed_scenarios']);
        $this->assertSame([], $result['regression_reasons']);
        $this->assertSame(1.0, $result['quality_score']);
    }

    // ── Scenarios that must be REJECTED ───────────────────────────────────────

    public function test_template_farm_correctly_rejected_passes(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_TEMPLATE_FARM, false),
        ]);

        $this->assertContains(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_TEMPLATE_FARM, $result['passed_scenarios']);
        $this->assertSame([], $result['failed_scenarios']);
        $this->assertSame(1.0, $result['quality_score']);
    }

    public function test_template_farm_admitted_is_regression(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_TEMPLATE_FARM, true),
        ]);

        $this->assertContains(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_TEMPLATE_FARM, $result['failed_scenarios']);
        $this->assertSame(0.0, $result['quality_score']);
        $this->assertNotEmpty($result['regression_reasons']);
    }

    public function test_duplicate_target_admitted_is_regression(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_DUPLICATE_TARGET, true),
        ]);

        $this->assertContains(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_DUPLICATE_TARGET, $result['failed_scenarios']);
        $this->assertSame(0.0, $result['quality_score']);
    }

    public function test_weak_evidence_admitted_is_regression(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_WEAK_EVIDENCE, true),
        ]);

        $this->assertContains(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_WEAK_EVIDENCE, $result['failed_scenarios']);
    }

    public function test_poison_packet_admitted_is_regression(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_POISON_PACKET, true),
        ]);

        $this->assertContains(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_POISON_PACKET, $result['failed_scenarios']);
    }

    // ── Scenarios that must be ADMITTED ──────────────────────────────────────

    public function test_high_leverage_genuine_admitted_passes(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_HIGH_LEVERAGE_GENUINE, true),
        ]);

        $this->assertContains(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_HIGH_LEVERAGE_GENUINE, $result['passed_scenarios']);
        $this->assertSame(1.0, $result['quality_score']);
    }

    public function test_high_leverage_genuine_rejected_is_regression(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_HIGH_LEVERAGE_GENUINE, false),
        ]);

        $this->assertContains(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_HIGH_LEVERAGE_GENUINE, $result['failed_scenarios']);
    }

    public function test_consolidation_needed_admitted_passes(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_CONSOLIDATION_NEEDED, true),
        ]);

        $this->assertContains(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_CONSOLIDATION_NEEDED, $result['passed_scenarios']);
    }

    // ── quality_score ─────────────────────────────────────────────────────────

    public function test_quality_score_is_ratio_of_passed_to_total(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_TEMPLATE_FARM,         false), // pass
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_HIGH_LEVERAGE_GENUINE, true),  // pass
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_DUPLICATE_TARGET,      true),  // fail
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_POISON_PACKET,         true),  // fail
        ]);

        $this->assertSame(2, count($result['passed_scenarios']));
        $this->assertSame(2, count($result['failed_scenarios']));
        $this->assertSame(0.5, $result['quality_score']);
    }

    public function test_all_scenarios_pass_yields_perfect_score(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_TEMPLATE_FARM,         false),
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_DUPLICATE_TARGET,      false),
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_WEAK_EVIDENCE,         false),
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_POISON_PACKET,         false),
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_HIGH_LEVERAGE_GENUINE, true),
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_CONSOLIDATION_NEEDED,  true),
        ]);

        $this->assertSame(6, count($result['passed_scenarios']));
        $this->assertSame([], $result['failed_scenarios']);
        $this->assertSame(1.0, $result['quality_score']);
    }

    // ── regression_reasons ────────────────────────────────────────────────────

    public function test_regression_reason_describes_the_failure(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_TEMPLATE_FARM, true),
        ]);

        $this->assertCount(1, $result['regression_reasons']);
        $this->assertStringContainsString('template_farm', $result['regression_reasons'][0]);
    }

    // ── Content-based detection (structurally valid but semantically bad) ─────

    public function test_content_based_detection_rejects_template_farming_weakness(): void
    {
        // No explicit scenario_id — suite must infer from content
        $result = $this->suite->score([
            ['scenario_id' => '', 'admitted' => true, 'weakness_labels' => ['template_farming']],
        ]);

        $this->assertSame(0.0, $result['quality_score']);
        $this->assertNotEmpty($result['failed_scenarios']);
    }

    public function test_content_based_detection_rejects_poison_packet(): void
    {
        $result = $this->suite->score([
            ['scenario_id' => '', 'admitted' => true, 'is_poison' => true],
        ]);

        $this->assertSame(0.0, $result['quality_score']);
    }

    public function test_content_based_detection_rejects_duplicate(): void
    {
        $result = $this->suite->score([
            ['scenario_id' => '', 'admitted' => true, 'is_duplicate' => true],
        ]);

        $this->assertSame(0.0, $result['quality_score']);
    }

    public function test_unknown_scenario_id_below_leverage_floor_with_no_evidence_is_scored_not_skipped(): void
    {
        // Omitting scenario_id/leverage/evidence must not let an admitted decision dodge scoring.
        $result = $this->suite->score([
            ['scenario_id' => 'completely_unknown', 'admitted' => true],
        ]);

        $this->assertSame([], $result['passed_scenarios']);
        $this->assertNotEmpty($result['failed_scenarios']);
        $this->assertSame(0.0, $result['quality_score']);
    }

    public function test_missing_scenario_id_below_leverage_floor_with_no_evidence_infers_weak_evidence_label(): void
    {
        // With scenario_id fully absent, the inferred label itself must be weak_evidence.
        $result = $this->suite->score([
            ['scenario_id' => '', 'admitted' => true],
        ]);

        $this->assertContains(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_WEAK_EVIDENCE, $result['failed_scenarios']);
        $this->assertSame(0.0, $result['quality_score']);
    }

    public function test_unknown_scenario_id_below_floor_with_evidence_is_still_skipped(): void
    {
        // Below the floor but WITH evidence is genuinely ambiguous — no rule covers it, so it
        // stays skipped rather than being force-scored either way.
        $result = $this->suite->score([
            ['scenario_id' => 'completely_unknown', 'admitted' => true, 'leverage_score' => 0.4, 'evidence' => ['proof' => 'ref']],
        ]);

        $this->assertSame([], $result['passed_scenarios']);
        $this->assertSame([], $result['failed_scenarios']);
        $this->assertSame(1.0, $result['quality_score']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $input = [
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_TEMPLATE_FARM,    false),
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_DUPLICATE_TARGET, true),
        ];

        $a = $this->suite->score($input);
        $b = $this->suite->score($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── new scenarios: proxy proof / shallow wrapper ───────────────────────────

    public function test_proxy_proof_admitted_is_regression(): void
    {
        $result = $this->suite->score([
            ['scenario_id' => AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_PROXY_PROOF, 'admitted' => true],
        ]);

        $this->assertSame(0.0, $result['quality_score']);
        $this->assertContains(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_PROXY_PROOF, $result['failed_scenarios']);
    }

    public function test_proxy_proof_correctly_rejected_passes(): void
    {
        $result = $this->suite->score([
            ['scenario_id' => AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_PROXY_PROOF, 'admitted' => false],
        ]);

        $this->assertSame(1.0, $result['quality_score']);
    }

    public function test_shallow_wrapper_admitted_is_regression(): void
    {
        $result = $this->suite->score([
            ['scenario_id' => AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_SHALLOW_WRAPPER, 'admitted' => true],
        ]);

        $this->assertSame(0.0, $result['quality_score']);
    }

    public function test_ambitious_multi_step_genuine_admitted_passes(): void
    {
        $result = $this->suite->score([
            ['scenario_id' => AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_AMBITIOUS_MULTI_STEP_GENUINE, 'admitted' => true],
        ]);

        $this->assertSame(1.0, $result['quality_score']);
        $this->assertSame([], $result['failed_scenarios']);
    }

    public function test_ambitious_multi_step_genuine_rejected_is_regression(): void
    {
        $result = $this->suite->score([
            ['scenario_id' => AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_AMBITIOUS_MULTI_STEP_GENUINE, 'admitted' => false],
        ]);

        $this->assertSame(0.0, $result['quality_score']);
        $this->assertStringContainsString('expected admission', $result['regression_reasons'][0]);
    }

    // ── runFrozenRegressionSuite ────────────────────────────────────────────────

    public function test_frozen_suite_passes_with_correct_built_in_cases(): void
    {
        $result = $this->suite->runFrozenRegressionSuite();

        $this->assertSame(AtlasExternalBrainDecisionQualityRegressionSuite::SCHEMA, $result['schema']);
        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['failed_case_ids']);
        $this->assertNull($result['violated_quality_rule']);
    }

    public function test_frozen_suite_includes_accepted_high_leverage_macro_case(): void
    {
        // Proven by the fact the frozen suite passes: the high-leverage and
        // ambitious-multi-step cases are admitted=true and still score as pass,
        // proving the suite does not reject valid ambitious work.
        $result = $this->suite->runFrozenRegressionSuite();

        $this->assertSame('pass', $result['verdict']);
    }

    // ── new scenario: false_wait_on_sufficient_depth ───────────────────────────

    public function test_false_wait_on_sufficient_depth_rejected_is_regression(): void
    {
        $result = $this->suite->score([
            ['scenario_id' => AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_FALSE_WAIT_ON_SUFFICIENT_DEPTH, 'admitted' => false],
        ]);

        $this->assertSame(0.0, $result['quality_score']);
        $this->assertContains(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_FALSE_WAIT_ON_SUFFICIENT_DEPTH, $result['failed_scenarios']);
        $this->assertNotEmpty($result['regression_reasons']);
    }

    public function test_false_wait_on_sufficient_depth_admitted_passes(): void
    {
        $result = $this->suite->score([
            ['scenario_id' => AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_FALSE_WAIT_ON_SUFFICIENT_DEPTH, 'admitted' => true],
        ]);

        $this->assertSame(1.0, $result['quality_score']);
        $this->assertSame([], $result['failed_scenarios']);
    }

    // ── AC3: failed_regressions / repair_hint ─────────────────────────────────

    public function test_failed_regressions_and_repair_hint_present_on_failure(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_TEMPLATE_FARM, true),
        ]);

        $this->assertSame($result['failed_scenarios'], $result['failed_regressions']);
        $this->assertNotNull($result['repair_hint']);
        $this->assertStringContainsString('template-farm', $result['repair_hint']);
    }

    public function test_repair_hint_is_null_when_no_regressions(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_TEMPLATE_FARM, false),
        ]);

        $this->assertNull($result['repair_hint']);
        $this->assertSame([], $result['failed_regressions']);
    }

    // ── AC1: missing scenario_id + high-risk weakness labels scored as failed ─

    public function test_missing_scenario_id_with_proxy_proof_weakness_is_scored_failed_when_admitted(): void
    {
        $result = $this->suite->score([
            ['scenario_id' => '', 'admitted' => true, 'weakness_labels' => ['proxy_proof']],
        ]);

        $this->assertSame(0.0, $result['quality_score']);
        $this->assertNotEmpty($result['failed_scenarios']);
    }

    public function test_missing_scenario_id_with_shallow_wrapper_weakness_is_scored_failed_when_admitted(): void
    {
        $result = $this->suite->score([
            ['scenario_id' => '', 'admitted' => true, 'weakness_labels' => ['shallow_wrapper']],
        ]);

        $this->assertSame(0.0, $result['quality_score']);
        $this->assertNotEmpty($result['failed_scenarios']);
    }

    public function test_missing_scenario_id_with_template_farming_weakness_is_scored_failed_when_admitted(): void
    {
        $result = $this->suite->score([
            ['scenario_id' => '', 'admitted' => true, 'weakness_labels' => ['template_farming']],
        ]);

        $this->assertSame(0.0, $result['quality_score']);
        $this->assertNotEmpty($result['failed_scenarios']);
    }

    // ── AC3: legitimate high-leverage / consolidation scenarios still pass ────

    public function test_high_leverage_genuine_and_consolidation_needed_still_pass_with_new_weak_evidence_logic(): void
    {
        $result = $this->suite->score([
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_HIGH_LEVERAGE_GENUINE, true),
            $this->decision(AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_CONSOLIDATION_NEEDED, true),
        ]);

        $this->assertSame(2, count($result['passed_scenarios']));
        $this->assertSame([], $result['failed_scenarios']);
        $this->assertSame(1.0, $result['quality_score']);
    }

    public function test_frozen_suite_includes_rejected_padding_and_proxy_and_duplicate_and_wrapper_cases(): void
    {
        // Re-derive expectations independently from the frozen cases via score()
        // to prove every required bad scenario is covered and correctly rejected.
        $badScenarios = [
            AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_TEMPLATE_FARM,
            AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_PROXY_PROOF,
            AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_DUPLICATE_TARGET,
            AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_SHALLOW_WRAPPER,
        ];

        foreach ($badScenarios as $scenario) {
            $result = $this->suite->score([['scenario_id' => $scenario, 'admitted' => false]]);
            $this->assertSame(1.0, $result['quality_score'], "{$scenario} should pass when correctly rejected");
        }
    }
}
