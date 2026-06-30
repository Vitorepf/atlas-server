<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricBrutalValueAdmissionGate;
use Tests\TestCase;

final class AtlasTaskFabricBrutalValueAdmissionGateTest extends TestCase
{
    private function gate(): AtlasTaskFabricBrutalValueAdmissionGate
    {
        return new AtlasTaskFabricBrutalValueAdmissionGate();
    }

    private function admissible(array $overrides = []): array
    {
        return array_merge([
            'target'                => 'AtlasFooService',
            'objective'             => 'Implement AtlasFooService to provide deterministic scoring for bar candidates.',
            'allowed_files'         => [
                'app/Services/Ai/SelfConstruction/Foo/AtlasFooService.php',
                'tests/Unit/Ai/SelfConstruction/Foo/AtlasFooServiceTest.php',
            ],
            'compound_impact_score' => 0.80,
            'give_back_risk_score'  => 0.20,
            'known_targets'         => [],
        ], $overrides);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->gate()->decide($this->admissible());
        $this->assertSame(AtlasTaskFabricBrutalValueAdmissionGate::SCHEMA, $result['schema']);
    }

    // ── admitted ──────────────────────────────────────────────────────────────

    public function test_admits_diverse_evidence_grounded_task(): void
    {
        $result = $this->gate()->decide($this->admissible());

        $this->assertTrue($result['admitted']);
        $this->assertSame([], $result['rejection_reasons']);
        $this->assertArrayHasKey('value_score', $result);
        $this->assertArrayHasKey('risk_score', $result);
        $this->assertArrayHasKey('required_followups', $result);
    }

    public function test_value_score_computed_correctly(): void
    {
        $result = $this->gate()->decide($this->admissible([
            'compound_impact_score' => 0.80,
            'give_back_risk_score'  => 0.20,
        ]));

        $this->assertEqualsWithDelta(0.80 * 0.80, $result['value_score'], 0.0001);
    }

    public function test_risk_score_equals_give_back_risk(): void
    {
        $result = $this->gate()->decide($this->admissible(['give_back_risk_score' => 0.30]));
        $this->assertSame(0.30, $result['risk_score']);
    }

    public function test_required_followups_empty_when_files_complete(): void
    {
        $result = $this->gate()->decide($this->admissible());
        $this->assertSame([], $result['required_followups']);
    }

    // ── semantic_duplicate ────────────────────────────────────────────────────

    public function test_rejects_semantic_duplicate(): void
    {
        $result = $this->gate()->decide($this->admissible([
            'target'        => 'AtlasFooService',
            'known_targets' => ['AtlasFooService'],
        ]));

        $this->assertFalse($result['admitted']);
        $this->assertContains('semantic_duplicate', $result['rejection_reasons']);
    }

    public function test_semantic_duplicate_is_case_insensitive(): void
    {
        $result = $this->gate()->decide($this->admissible([
            'target'        => 'AtlasFooService',
            'known_targets' => ['atlasfooservice'],
        ]));

        $this->assertContains('semantic_duplicate', $result['rejection_reasons']);
    }

    // ── template_farm ─────────────────────────────────────────────────────────

    public function test_rejects_short_objective(): void
    {
        $result = $this->gate()->decide($this->admissible(['objective' => 'Do stuff']));

        $this->assertFalse($result['admitted']);
        $this->assertContains('template_farm', $result['rejection_reasons']);
    }

    public function test_rejects_explicit_template_farm_flag(): void
    {
        $result = $this->gate()->decide($this->admissible(['is_template_farm' => true]));

        $this->assertFalse($result['admitted']);
        $this->assertContains('template_farm', $result['rejection_reasons']);
    }

    // ── implementability_weak ─────────────────────────────────────────────────

    public function test_rejects_when_no_implementation_file(): void
    {
        $result = $this->gate()->decide($this->admissible([
            'allowed_files' => [
                'tests/Unit/Ai/SelfConstruction/Foo/AtlasFooServiceTest.php',
            ],
        ]));

        $this->assertFalse($result['admitted']);
        $reasons = implode(',', $result['rejection_reasons']);
        $this->assertStringContainsString('implementability_weak', $reasons);
    }

    public function test_rejects_when_no_test_file(): void
    {
        $result = $this->gate()->decide($this->admissible([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Foo/AtlasFooService.php',
            ],
        ]));

        $this->assertFalse($result['admitted']);
        $reasons = implode(',', $result['rejection_reasons']);
        $this->assertStringContainsString('implementability_weak', $reasons);
    }

    // ── compound_impact_low ───────────────────────────────────────────────────

    public function test_rejects_low_compound_impact(): void
    {
        $result = $this->gate()->decide($this->admissible(['compound_impact_score' => 0.10]));

        $this->assertFalse($result['admitted']);
        $this->assertContains('compound_impact_low', $result['rejection_reasons']);
    }

    public function test_admits_at_exact_impact_floor(): void
    {
        $result = $this->gate()->decide($this->admissible(['compound_impact_score' => 0.30]));

        $this->assertTrue($result['admitted']);
    }

    // ── give_back_risk_high ───────────────────────────────────────────────────

    public function test_rejects_high_give_back_risk(): void
    {
        $result = $this->gate()->decide($this->admissible(['give_back_risk_score' => 0.75]));

        $this->assertFalse($result['admitted']);
        $this->assertContains('give_back_risk_high', $result['rejection_reasons']);
    }

    public function test_admits_just_below_give_back_ceiling(): void
    {
        $result = $this->gate()->decide($this->admissible(['give_back_risk_score' => 0.69]));
        $this->assertTrue($result['admitted']);
    }

    // ── multiple rejections accumulate ────────────────────────────────────────

    public function test_multiple_rejection_reasons_accumulate(): void
    {
        $result = $this->gate()->decide([
            'target'                => 'known',
            'objective'             => 'short',
            'allowed_files'         => [],
            'compound_impact_score' => 0.05,
            'give_back_risk_score'  => 0.90,
            'known_targets'         => ['known'],
        ]);

        $this->assertFalse($result['admitted']);
        $this->assertGreaterThanOrEqual(3, count($result['rejection_reasons']));
        $this->assertContains('semantic_duplicate', $result['rejection_reasons']);
        $this->assertContains('template_farm', $result['rejection_reasons']);
        $this->assertContains('compound_impact_low', $result['rejection_reasons']);
        $this->assertContains('give_back_risk_high', $result['rejection_reasons']);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_impact_floor_respected(): void
    {
        // With floor=0.60, score=0.50 should be rejected
        $result = $this->gate()->decide($this->admissible([
            'compound_impact_score' => 0.50,
            'thresholds'            => ['compound_impact_floor' => 0.60],
        ]));

        $this->assertFalse($result['admitted']);
        $this->assertContains('compound_impact_low', $result['rejection_reasons']);
    }

    // ── pure / deterministic ──────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = $this->admissible();
        $this->assertSame($this->gate()->decide($input), $this->gate()->decide($input));
    }
}
