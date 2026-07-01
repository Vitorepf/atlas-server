<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDecisionQualityRegressionSuite;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDecisionQualityRegressionSuiteTest extends TestCase
{
    private function suite(): AtlasExternalBrainDecisionQualityRegressionSuite
    {
        return new AtlasExternalBrainDecisionQualityRegressionSuite;
    }

    // ── AC2: bad scenarios must be rejected (admitted=false is the passing case) ──

    public function test_bad_scenarios_pass_only_when_rejected(): void
    {
        $badScenarios = [
            AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_TEMPLATE_FARM,
            AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_DUPLICATE_TARGET,
            AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_WEAK_EVIDENCE,
            AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_POISON_PACKET,
            AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_PROXY_PROOF,
            AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_SHALLOW_WRAPPER,
        ];

        foreach ($badScenarios as $scenario) {
            $correctlyRejected = $this->suite()->score([['scenario_id' => $scenario, 'admitted' => false]]);
            $this->assertSame([$scenario], $correctlyRejected['passed_scenarios'], "scenario: {$scenario}");
            $this->assertSame([], $correctlyRejected['failed_scenarios'], "scenario: {$scenario}");

            $wronglyAdmitted = $this->suite()->score([['scenario_id' => $scenario, 'admitted' => true]]);
            $this->assertSame([$scenario], $wronglyAdmitted['failed_scenarios'], "scenario: {$scenario}");
            $this->assertNotEmpty($wronglyAdmitted['regression_reasons']);
        }
    }

    // ── AC3: good scenarios must be admitted (admitted=true is the passing case) ──

    public function test_good_scenarios_pass_only_when_admitted(): void
    {
        $goodScenarios = [
            AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_HIGH_LEVERAGE_GENUINE,
            AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_CONSOLIDATION_NEEDED,
            AtlasExternalBrainDecisionQualityRegressionSuite::SCENARIO_AMBITIOUS_MULTI_STEP_GENUINE,
        ];

        foreach ($goodScenarios as $scenario) {
            $correctlyAdmitted = $this->suite()->score([['scenario_id' => $scenario, 'admitted' => true]]);
            $this->assertSame([$scenario], $correctlyAdmitted['passed_scenarios'], "scenario: {$scenario}");

            $wronglyRejected = $this->suite()->score([['scenario_id' => $scenario, 'admitted' => false]]);
            $this->assertSame([$scenario], $wronglyRejected['failed_scenarios'], "scenario: {$scenario}");
        }
    }

    // ── AC4: content labels detect regressions even without a known scenario_id ──

    public function test_content_labels_detect_regression_when_scenario_id_missing(): void
    {
        $result = $this->suite()->score([
            ['weakness_labels' => ['template_farming'], 'admitted' => true],
        ]);

        $this->assertNotEmpty($result['failed_scenarios']);
        $this->assertNotEmpty($result['regression_reasons']);
    }

    public function test_content_labels_detect_regression_with_unknown_scenario_id(): void
    {
        $result = $this->suite()->score([
            ['scenario_id' => 'totally_unrecognized_scenario', 'is_poison' => true, 'admitted' => true],
        ]);

        $this->assertNotEmpty($result['failed_scenarios']);
    }

    public function test_is_duplicate_content_signal_detects_regression(): void
    {
        $result = $this->suite()->score([
            ['is_duplicate' => true, 'admitted' => true],
        ]);

        $this->assertNotEmpty($result['failed_scenarios']);
    }

    public function test_each_low_value_content_label_is_detected(): void
    {
        foreach (['shallow_duplication', 'fake_confidence', 'proxy_proof', 'shallow_wrapper'] as $label) {
            $result = $this->suite()->score([
                ['weakness_labels' => [$label], 'admitted' => true],
            ]);

            $this->assertNotEmpty($result['failed_scenarios'], "label: {$label}");
        }
    }

    public function test_frozen_regression_suite_passes_with_correct_baseline(): void
    {
        $result = $this->suite()->runFrozenRegressionSuite();

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['failed_case_ids']);
        $this->assertNull($result['violated_quality_rule']);
    }
}
