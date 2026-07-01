<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationLiveShadowPlan;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationLiveShadowPlanTest extends TestCase
{
    private function samples(int $count, mixed $newResultValue = 1): array
    {
        $samples = [];
        for ($i = 0; $i < $count; $i++) {
            $samples[] = [
                'sample_id' => "sample-{$i}",
                'old_output' => ['result' => 1],
                'new_output' => ['result' => $newResultValue],
            ];
        }

        return $samples;
    }

    public function test_exact_match_across_enough_samples_allows_promotion(): void
    {
        $result = (new AtlasSelfConstructionSimplificationLiveShadowPlan)->plan([
            'samples' => $this->samples(10),
            'compared_fields' => ['result'],
            'minimum_sample_count' => 10,
        ]);

        self::assertTrue($result['promotion_allowed']);
        self::assertSame([], $result['blockers']);
        self::assertSame([], $result['mismatches']);
        self::assertSame(10, $result['sample_count']);
        self::assertSame(
            ['shadow_run_receipts', 'comparison_report', 'promotion_approval', 'material_diff_report', 'sample_identity_receipts'],
            $result['evidence_required'],
        );
    }

    public function test_tolerated_numeric_drift_does_not_block_promotion(): void
    {
        $samples = [];
        for ($i = 0; $i < 10; $i++) {
            $samples[] = [
                'sample_id' => "sample-{$i}",
                'old_output' => ['latency_ms' => 100.0],
                'new_output' => ['latency_ms' => 105.0],
            ];
        }

        $result = (new AtlasSelfConstructionSimplificationLiveShadowPlan)->plan([
            'samples' => $samples,
            'compared_fields' => ['latency_ms'],
            'tolerated_drift' => ['latency_ms' => 10.0],
            'minimum_sample_count' => 10,
        ]);

        self::assertTrue($result['promotion_allowed']);
        self::assertSame([], $result['blockers']);
        // Tolerated diffs are still reported (audit trail), just classified and non-blocking.
        self::assertCount(10, $result['mismatches']);
        foreach ($result['mismatches'] as $mismatch) {
            self::assertSame('tolerated', $mismatch['classification']);
        }
    }

    public function test_non_tolerated_mismatch_blocks_promotion(): void
    {
        $result = (new AtlasSelfConstructionSimplificationLiveShadowPlan)->plan([
            'samples' => $this->samples(10, 2),
            'compared_fields' => ['result'],
            'minimum_sample_count' => 10,
        ]);

        self::assertFalse($result['promotion_allowed']);
        self::assertNotEmpty($result['mismatches']);
        foreach ($result['mismatches'] as $mismatch) {
            self::assertSame('material', $mismatch['classification']);
        }
        self::assertStringContainsString('material_field_mismatches_detected', implode(',', $result['blockers']));
    }

    public function test_below_minimum_sample_count_blocks_promotion(): void
    {
        $result = (new AtlasSelfConstructionSimplificationLiveShadowPlan)->plan([
            'samples' => $this->samples(3),
            'compared_fields' => ['result'],
            'minimum_sample_count' => 10,
        ]);

        self::assertFalse($result['promotion_allowed']);
        self::assertStringContainsString('sample_count_below_minimum', implode(',', $result['blockers']));
    }

    // ── stable sample identity ───────────────────────────────────────────────

    public function test_missing_sample_id_blocks_promotion_even_when_outputs_match(): void
    {
        $result = (new AtlasSelfConstructionSimplificationLiveShadowPlan)->plan([
            'samples' => [
                ['old_output' => ['result' => 1], 'new_output' => ['result' => 1]],
            ],
            'compared_fields' => ['result'],
            'minimum_sample_count' => 1,
        ]);

        self::assertFalse($result['promotion_allowed']);
        self::assertContains('missing_sample_id:0', $result['blockers']);
    }

    public function test_duplicate_sample_id_blocks_promotion_even_when_outputs_match(): void
    {
        $result = (new AtlasSelfConstructionSimplificationLiveShadowPlan)->plan([
            'samples' => [
                ['sample_id' => 'dup', 'old_output' => ['result' => 1], 'new_output' => ['result' => 1]],
                ['sample_id' => 'dup', 'old_output' => ['result' => 1], 'new_output' => ['result' => 1]],
            ],
            'compared_fields' => ['result'],
            'minimum_sample_count' => 2,
        ]);

        self::assertFalse($result['promotion_allowed']);
        self::assertContains('duplicate_sample_id:dup', $result['blockers']);
    }

    public function test_risky_candidate_emits_shadow_steps_for_old_and_new_circuits_with_diff_receipt_requirement(): void
    {
        $result = (new AtlasSelfConstructionSimplificationLiveShadowPlan)->planShadowRun([
            'candidate_id' => 'consolidation-1',
            'old_circuit' => 'OldOrgan',
            'new_circuit' => 'NewOrgan',
            'sample_input_refs' => ['sample-1', 'sample-2'],
        ]);

        self::assertSame('shadow_run_planned', $result['action']);
        self::assertContains('shadow_run:OldOrgan:sample-1', $result['shadow_steps']);
        self::assertContains('shadow_run:NewOrgan:sample-1', $result['shadow_steps']);
        self::assertContains('shadow_run:OldOrgan:sample-2', $result['shadow_steps']);
        self::assertContains('shadow_run:NewOrgan:sample-2', $result['shadow_steps']);
        self::assertSame(['sample-1', 'sample-2'], $result['sample_input_refs']);
        self::assertTrue($result['diff_receipt_required']);
    }

    public function test_candidate_with_side_effects_but_no_isolation_is_blocked(): void
    {
        $result = (new AtlasSelfConstructionSimplificationLiveShadowPlan)->planShadowRun([
            'candidate_id' => 'consolidation-2',
            'old_circuit' => 'OldOrgan',
            'new_circuit' => 'NewOrgan',
            'sample_input_refs' => ['sample-1'],
            'has_side_effects' => true,
            'side_effects_isolated' => false,
        ]);

        self::assertSame('blocked', $result['action']);
        self::assertContains('side_effects_not_isolated', $result['blockers']);
        self::assertSame([], $result['shadow_steps']);
    }

    public function test_side_effects_claimed_isolated_but_missing_isolation_proof_ref_is_blocked(): void
    {
        $result = (new AtlasSelfConstructionSimplificationLiveShadowPlan)->planShadowRun([
            'candidate_id' => 'consolidation-4',
            'old_circuit' => 'OldOrgan',
            'new_circuit' => 'NewOrgan',
            'sample_input_refs' => ['sample-1'],
            'has_side_effects' => true,
            'side_effects_isolated' => true,
            'isolation_proof_ref' => '',
        ]);

        self::assertSame('blocked', $result['action']);
        self::assertContains('missing_isolation_proof_ref', $result['blockers']);
        self::assertSame([], $result['shadow_steps']);
    }

    public function test_side_effects_with_isolation_flag_and_proof_ref_is_allowed(): void
    {
        $result = (new AtlasSelfConstructionSimplificationLiveShadowPlan)->planShadowRun([
            'candidate_id' => 'consolidation-5',
            'old_circuit' => 'OldOrgan',
            'new_circuit' => 'NewOrgan',
            'sample_input_refs' => ['sample-1'],
            'has_side_effects' => true,
            'side_effects_isolated' => true,
            'isolation_proof_ref' => 'receipt:isolation-run-42',
        ]);

        self::assertSame('shadow_run_planned', $result['action']);
        self::assertSame([], $result['blockers']);
    }

    public function test_promotion_requires_zero_material_diffs_and_required_sample_floor(): void
    {
        $result = (new AtlasSelfConstructionSimplificationLiveShadowPlan)->planShadowRun([
            'candidate_id' => 'consolidation-3',
            'old_circuit' => 'OldOrgan',
            'new_circuit' => 'NewOrgan',
            'sample_input_refs' => ['sample-1'],
            'minimum_sample_count' => 20,
        ]);

        self::assertSame(20, $result['required_sample_floor']);
        self::assertStringContainsString('zero_material_diffs', $result['promotion_requirement']);
        self::assertStringContainsString('20', $result['promotion_requirement']);
    }
}
