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

    public function test_unknown_scenario_id_with_no_content_signal_is_skipped(): void
    {
        // No scenario_id, no content signals, no leverage → skip, not counted
        $result = $this->suite->score([
            ['scenario_id' => 'completely_unknown', 'admitted' => true],
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
}
