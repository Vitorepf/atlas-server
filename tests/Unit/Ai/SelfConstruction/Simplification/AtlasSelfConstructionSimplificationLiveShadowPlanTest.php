<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationLiveShadowPlan;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationLiveShadowPlanTest extends TestCase
{
    private function samples(int $count, mixed $newResultValue = 1): array
    {
        return array_fill(0, $count, [
            'old_output' => ['result' => 1],
            'new_output' => ['result' => $newResultValue],
        ]);
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
        self::assertSame(['shadow_run_receipts', 'comparison_report', 'promotion_approval'], $result['evidence_required']);
    }

    public function test_tolerated_numeric_drift_does_not_block_promotion(): void
    {
        $samples = array_fill(0, 10, [
            'old_output' => ['latency_ms' => 100.0],
            'new_output' => ['latency_ms' => 105.0],
        ]);

        $result = (new AtlasSelfConstructionSimplificationLiveShadowPlan)->plan([
            'samples' => $samples,
            'compared_fields' => ['latency_ms'],
            'tolerated_drift' => ['latency_ms' => 10.0],
            'minimum_sample_count' => 10,
        ]);

        self::assertTrue($result['promotion_allowed']);
        self::assertSame([], $result['mismatches']);
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
        self::assertStringContainsString('field_mismatches_detected', implode(',', $result['blockers']));
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
