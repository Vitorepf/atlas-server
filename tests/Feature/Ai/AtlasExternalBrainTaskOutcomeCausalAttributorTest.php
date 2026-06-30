<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskOutcomeCausalAttributor;
use Tests\TestCase;

final class AtlasExternalBrainTaskOutcomeCausalAttributorTest extends TestCase
{
    private AtlasExternalBrainTaskOutcomeCausalAttributor $attributor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attributor = new AtlasExternalBrainTaskOutcomeCausalAttributor;
    }

    // ── AC1: worker_mismatch beats poor_spec when spec is green ──────────────

    public function test_green_spec_with_worker_avoid_class_is_worker_mismatch(): void
    {
        $result = $this->attributor->attribute([
            'spec'    => ['quality_score' => 0.9, 'has_acceptance_criteria' => true],
            'worker'  => ['task_class' => 'engineering', 'avoid_task_classes' => ['engineering']],
            'outcome' => ['result' => 'give_back'],
        ]);

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_WORKER_MISMATCH, $result['primary_cause']);
        $this->assertNotSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_POOR_SPEC, $result['primary_cause']);
        $this->assertContains('worker_avoid_class_matched', $result['contributing_causes']);
    }

    // ── AC2: scope_failure with actionable originator adjustment ─────────────

    public function test_forbidden_files_causes_scope_failure(): void
    {
        $result = $this->attributor->attribute([
            'spec'    => ['quality_score' => 0.8, 'has_acceptance_criteria' => true, 'forbidden_files_detected' => true],
            'outcome' => ['result' => 'give_back'],
        ]);

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_SCOPE_FAILURE, $result['primary_cause']);
        $this->assertSame('fix_scope_in_originator', $result['recommended_originator_adjustment']);
        $this->assertContains('forbidden_files_detected:true', $result['contributing_causes']);
    }

    public function test_missing_allowed_files_causes_scope_failure(): void
    {
        $result = $this->attributor->attribute([
            'spec'    => ['quality_score' => 0.8, 'has_acceptance_criteria' => true, 'missing_allowed_files' => true],
            'outcome' => ['result' => 'give_back'],
        ]);

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_SCOPE_FAILURE, $result['primary_cause']);
        $this->assertSame('fix_scope_in_originator', $result['recommended_originator_adjustment']);
    }

    public function test_scope_failure_has_high_confidence(): void
    {
        $result = $this->attributor->attribute([
            'spec'    => ['forbidden_files_detected' => true],
            'outcome' => ['result' => 'give_back'],
        ]);

        $this->assertSame('high', $result['confidence']);
    }

    // ── AC3: poisoned_acceptance / ambiguous_contract with lower confidence ──

    public function test_contradictory_acceptance_with_give_back_is_poisoned_acceptance(): void
    {
        $result = $this->attributor->attribute([
            'spec'    => ['quality_score' => 0.8, 'has_acceptance_criteria' => true, 'contradictory_acceptance' => true],
            'outcome' => ['result' => 'give_back'],
        ]);

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_POISONED_ACCEPTANCE, $result['primary_cause']);
        $this->assertSame('medium', $result['confidence'], 'poisoned_acceptance must have lower confidence');
        $this->assertContains('contradictory_acceptance:true', $result['contributing_causes']);
    }

    public function test_contradictory_evidence_causes_ambiguous_contract(): void
    {
        $result = $this->attributor->attribute([
            'spec'    => ['quality_score' => 0.8, 'has_acceptance_criteria' => true],
            'outcome' => ['result' => 'success', 'contradictory_evidence' => true],
        ]);

        $this->assertSame(AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_AMBIGUOUS_CONTRACT, $result['primary_cause']);
        $this->assertSame('medium', $result['confidence'], 'ambiguous_contract must have lower confidence');
    }

    public function test_poisoned_acceptance_adjustment_is_resolve_contradictory(): void
    {
        $result = $this->attributor->attribute([
            'spec'    => ['quality_score' => 0.8, 'has_acceptance_criteria' => true, 'contradictory_acceptance' => true],
            'outcome' => ['result' => 'give_back'],
        ]);

        $this->assertSame('resolve_contradictory_acceptance', $result['recommended_originator_adjustment']);
    }

    // ── AC4: deterministic output with required keys ──────────────────────────

    public function test_output_has_all_required_keys(): void
    {
        $result = $this->attributor->attribute([]);

        foreach (['primary_cause', 'contributing_causes', 'confidence', 'recommended_originator_adjustment', 'attribution_id'] as $k) {
            $this->assertArrayHasKey($k, $result, "Missing key: {$k}");
        }
    }

    public function test_attribution_id_is_deterministic(): void
    {
        $input = [
            'spec'    => ['quality_score' => 0.9, 'has_acceptance_criteria' => true],
            'worker'  => ['task_class' => 'refactor', 'avoid_task_classes' => ['refactor']],
            'outcome' => ['result' => 'give_back'],
        ];

        $this->assertSame(
            $this->attributor->attribute($input)['attribution_id'],
            $this->attributor->attribute($input)['attribution_id'],
        );
    }
}
