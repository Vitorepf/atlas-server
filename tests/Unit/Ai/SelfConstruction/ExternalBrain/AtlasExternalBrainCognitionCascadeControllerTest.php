<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCognitionCascadeController;
use Tests\TestCase;

final class AtlasExternalBrainCognitionCascadeControllerTest extends TestCase
{
    private function controller(): AtlasExternalBrainCognitionCascadeController
    {
        return new AtlasExternalBrainCognitionCascadeController();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->controller()->control([]);

        $this->assertSame(AtlasExternalBrainCognitionCascadeController::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->controller()->control([]);

        foreach (['schema', 'selected_stage', 'skipped_stages', 'escalation_reasons',
                  'fallback_plan', 'required_local_gates'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    // ── required_local_gates ──────────────────────────────────────────────────

    public function test_required_local_gates_always_present(): void
    {
        $result = $this->controller()->control([]);

        $this->assertContains('evidence_list_non_empty', $result['required_local_gates']);
        $this->assertContains('dedup_proof_present', $result['required_local_gates']);
        $this->assertContains('no_retirable_patterns_in_scope', $result['required_local_gates']);
    }

    // ── local_only ────────────────────────────────────────────────────────────

    public function test_local_only_when_quality_ok_and_not_ambiguous_or_risky(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.30,
            'risk_score'       => 0.20,
        ]);

        $this->assertSame(AtlasExternalBrainCognitionCascadeController::STAGE_LOCAL_ONLY, $result['selected_stage']);
    }

    public function test_local_only_skips_scaffold_and_frontier(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.30,
            'risk_score'       => 0.20,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_SCAFFOLDED_SMALL_MODEL, $result['skipped_stages']);
        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_ESCALATION, $result['skipped_stages']);
    }

    public function test_local_only_has_no_escalation_reasons(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.30,
            'risk_score'       => 0.20,
        ]);

        $this->assertSame([], $result['escalation_reasons']);
    }

    // ── scaffolded_small_model ────────────────────────────────────────────────

    public function test_scaffolded_when_quality_ok_but_ambiguity_high(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.75,
            'risk_score'       => 0.20,
        ]);

        $this->assertSame(AtlasExternalBrainCognitionCascadeController::STAGE_SCAFFOLDED_SMALL_MODEL, $result['selected_stage']);
    }

    public function test_scaffolded_when_quality_ok_but_risk_high(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.20,
            'risk_score'       => 0.75,
        ]);

        $this->assertSame(AtlasExternalBrainCognitionCascadeController::STAGE_SCAFFOLDED_SMALL_MODEL, $result['selected_stage']);
    }

    public function test_scaffolded_skips_only_frontier(): void
    {
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.75,
            'risk_score'       => 0.20,
        ]);

        $this->assertContains(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_ESCALATION, $result['skipped_stages']);
        $this->assertNotContains(AtlasExternalBrainCognitionCascadeController::STAGE_LOCAL_ONLY, $result['skipped_stages']);
    }

    // ── frontier_escalation ───────────────────────────────────────────────────

    public function test_frontier_when_quality_low_and_no_safe_scaffold(): void
    {
        $result = $this->controller()->control([
            'evidence_quality'          => 0.40,
            'ambiguity_score'           => 0.30,
            'risk_score'                => 0.30,
            'has_safe_scaffold_fallback' => false,
        ]);

        $this->assertSame(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_ESCALATION, $result['selected_stage']);
    }

    public function test_frontier_when_both_ambiguity_and_risk_high_and_no_safe_scaffold(): void
    {
        $result = $this->controller()->control([
            'evidence_quality'          => 0.80,
            'ambiguity_score'           => 0.75,
            'risk_score'                => 0.75,
            'has_safe_scaffold_fallback' => false,
        ]);

        $this->assertSame(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_ESCALATION, $result['selected_stage']);
    }

    public function test_frontier_escalation_reasons_populated(): void
    {
        $result = $this->controller()->control([
            'evidence_quality'          => 0.40,
            'ambiguity_score'           => 0.30,
            'risk_score'                => 0.30,
            'has_safe_scaffold_fallback' => false,
        ]);

        $this->assertNotEmpty($result['escalation_reasons']);
        $this->assertStringContainsString('evidence_quality', $result['escalation_reasons'][0]);
    }

    // ── invariant: never frontier when safe scaffold exists ───────────────────

    public function test_never_frontier_when_safe_scaffold_fallback_true(): void
    {
        $result = $this->controller()->control([
            'evidence_quality'          => 0.10,  // very low
            'ambiguity_score'           => 0.90,  // very high
            'risk_score'                => 0.90,  // very high
            'has_safe_scaffold_fallback' => true,
        ]);

        $this->assertNotSame(AtlasExternalBrainCognitionCascadeController::STAGE_FRONTIER_ESCALATION, $result['selected_stage']);
    }

    public function test_falls_back_to_scaffolded_when_quality_low_but_scaffold_safe(): void
    {
        $result = $this->controller()->control([
            'evidence_quality'          => 0.30,
            'ambiguity_score'           => 0.80,
            'risk_score'                => 0.80,
            'has_safe_scaffold_fallback' => true,
        ]);

        $this->assertSame(AtlasExternalBrainCognitionCascadeController::STAGE_SCAFFOLDED_SMALL_MODEL, $result['selected_stage']);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_evidence_floor_respected(): void
    {
        // With floor=0.90, quality=0.80 is insufficient → not local_only
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.20,
            'risk_score'       => 0.20,
            'thresholds'       => ['evidence_floor' => 0.90],
        ]);

        $this->assertNotSame(AtlasExternalBrainCognitionCascadeController::STAGE_LOCAL_ONLY, $result['selected_stage']);
    }

    public function test_custom_ambiguity_ceiling_respected(): void
    {
        // With ceiling=0.50, ambiguity=0.60 triggers scaffolded even with good quality
        $result = $this->controller()->control([
            'evidence_quality' => 0.80,
            'ambiguity_score'  => 0.60,
            'risk_score'       => 0.20,
            'thresholds'       => ['ambiguity_ceiling' => 0.50],
        ]);

        $this->assertSame(AtlasExternalBrainCognitionCascadeController::STAGE_SCAFFOLDED_SMALL_MODEL, $result['selected_stage']);
    }

    // ── fallback_plan ─────────────────────────────────────────────────────────

    public function test_fallback_plan_non_empty(): void
    {
        foreach ([
            ['evidence_quality' => 0.80, 'ambiguity_score' => 0.20, 'risk_score' => 0.20],
            ['evidence_quality' => 0.80, 'ambiguity_score' => 0.80, 'risk_score' => 0.20],
            ['evidence_quality' => 0.30, 'ambiguity_score' => 0.20, 'risk_score' => 0.20, 'has_safe_scaffold_fallback' => false],
        ] as $input) {
            $result = $this->controller()->control($input);
            $this->assertNotEmpty($result['fallback_plan']);
        }
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'evidence_quality' => 0.75,
            'ambiguity_score'  => 0.40,
            'risk_score'       => 0.60,
        ];

        $this->assertSame($this->controller()->control($input), $this->controller()->control($input));
    }
}
