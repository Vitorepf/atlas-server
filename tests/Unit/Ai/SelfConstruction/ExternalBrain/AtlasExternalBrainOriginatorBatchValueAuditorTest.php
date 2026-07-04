<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorBatchValueAuditor;
use PHPUnit\Framework\TestCase;

/**
 * Proves the AC-facing decision layer of AtlasExternalBrainOriginatorBatchValueAuditor: padding,
 * duplicate value, weak evidence, and over-concentrated target families are flagged even when the
 * batch passes structural gates, and each maps to a keep/trim/reject/split decision with
 * dropped_task_reasons naming the specific offending tasks.
 */
final class AtlasExternalBrainOriginatorBatchValueAuditorTest extends TestCase
{
    private function auditor(): AtlasExternalBrainOriginatorBatchValueAuditor
    {
        return new AtlasExternalBrainOriginatorBatchValueAuditor;
    }

    private function goodTask(array $overrides = []): array
    {
        return array_merge([
            'task_id' => 'task-1',
            'theme' => 'theme-a',
            'is_test_only' => false,
            'is_wrapper_only' => false,
            'has_runnable_proof' => true,
            'structural_gates_passed' => true,
            'impact_score' => 0.8,
            'evidence_strength' => 0.8,
            'duplicate_of' => null,
            'collision_risk' => 0.0,
        ], $overrides);
    }

    // ── high-quality batch ───────────────────────────────────────────────────

    public function test_high_quality_batch_yields_keep_decision(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'theme-a']),
            $this->goodTask(['task_id' => 'b', 'theme' => 'theme-b']),
            $this->goodTask(['task_id' => 'c', 'theme' => 'theme-c']),
        ]]);

        $this->assertFalse($result['low_value']);
        $this->assertSame('keep', $result['decision']);
        $this->assertSame([], $result['dropped_task_reasons']);
    }

    // ── padding rejection ────────────────────────────────────────────────────

    public function test_padding_dominant_batch_is_flagged_and_trimmed(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a', 'is_padding' => true]),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b', 'is_padding' => true]),
            $this->goodTask(['task_id' => 'c', 'theme' => 'c']),
        ]]);

        $this->assertTrue($result['low_value']);
        $this->assertContains('padding_tasks_dominant', $result['low_value_reasons']);
        $this->assertSame('trim', $result['decision']);
        $this->assertSame('padding', $result['dropped_task_reasons']['a']);
        $this->assertSame('padding', $result['dropped_task_reasons']['b']);
        $this->assertNotContains('c', $result['trimmed_task_ids']);
    }

    public function test_test_only_and_wrapper_only_task_counts_as_padding(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a', 'is_test_only' => true, 'is_wrapper_only' => true]),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b', 'is_test_only' => true, 'is_wrapper_only' => true]),
            $this->goodTask(['task_id' => 'c', 'theme' => 'c']),
        ]]);

        $this->assertGreaterThan(0.0, $result['padding_share']);
        $this->assertSame('trim', $result['decision']);
    }

    // ── duplicate value trim ─────────────────────────────────────────────────

    public function test_duplicate_value_present_trims_the_duplicate_task(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a']),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b', 'duplicate_of' => 'old-task-99']),
            $this->goodTask(['task_id' => 'c', 'theme' => 'c']),
        ]]);

        $this->assertTrue($result['low_value']);
        $this->assertContains('duplicate_value_present', $result['low_value_reasons']);
        $this->assertSame('trim', $result['decision']);
        $this->assertSame('duplicate_of_set', $result['dropped_task_reasons']['b']);
        $this->assertGreaterThan(0.0, $result['duplicate_share']);
    }

    // ── weak evidence rejection ──────────────────────────────────────────────

    public function test_weak_evidence_dominant_batch_is_rejected(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a', 'evidence_strength' => 0.05]),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b', 'evidence_strength' => 0.1]),
            $this->goodTask(['task_id' => 'c', 'theme' => 'c', 'evidence_strength' => 0.9]),
        ]]);

        $this->assertTrue($result['low_value']);
        $this->assertContains('weak_evidence_dominant', $result['low_value_reasons']);
        $this->assertSame('reject', $result['decision']);
        $this->assertGreaterThan(0.0, $result['weak_evidence_share']);
    }

    // ── low diversity / over-concentrated target family → split ─────────────

    public function test_over_concentrated_target_family_yields_split_decision(): void
    {
        $result = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'cleanup']),
            $this->goodTask(['task_id' => 'b', 'theme' => 'cleanup']),
            $this->goodTask(['task_id' => 'c', 'theme' => 'cleanup']),
            $this->goodTask(['task_id' => 'd', 'theme' => 'other']),
        ]]);

        $this->assertTrue($result['low_value']);
        $this->assertContains('same_theme_variants_dominant', $result['low_value_reasons']);
        $this->assertSame('split', $result['decision']);
        $this->assertSame('cleanup', $result['dominant_theme']);
    }

    // ── decision vocabulary coverage ─────────────────────────────────────────

    public function test_all_recommendation_kinds_map_to_the_ac_decision_vocabulary(): void
    {
        $keep = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a']),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b']),
        ]]);
        $this->assertSame('keep', $keep['decision']);

        $reject = $this->auditor()->audit(['tasks' => []]);
        $this->assertSame('reject', $reject['decision']);

        $trim = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a', 'has_runnable_proof' => false]),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b']),
        ]]);
        $this->assertSame('trim', $trim['decision']);

        $split = $this->auditor()->audit(['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'x']),
            $this->goodTask(['task_id' => 'b', 'theme' => 'x']),
            $this->goodTask(['task_id' => 'c', 'theme' => 'x']),
            $this->goodTask(['task_id' => 'd', 'theme' => 'y']),
        ]]);
        $this->assertSame('split', $split['decision']);
    }

    public function test_output_is_deterministic(): void
    {
        $auditor = $this->auditor();
        $facts = ['tasks' => [
            $this->goodTask(['task_id' => 'a', 'theme' => 'a']),
            $this->goodTask(['task_id' => 'b', 'theme' => 'b', 'duplicate_of' => 'old']),
        ]];

        $this->assertSame(
            json_encode($auditor->audit($facts)),
            json_encode($auditor->audit($facts)),
        );
    }

    // ── compoundProofAudit: compound_value_score, weak_compound_evidence_task_ids, recommendation_reasons ──

    public function test_strong_compound_evidence_yields_high_score(): void
    {
        $r = (new AtlasExternalBrainOriginatorBatchValueAuditor)->compoundProofAudit([
            ['task_id' => 't1', 'downstream_unlocks' => 1.0, 'risk_reduction' => 0.8, 'proof_strength' => 0.9, 'simplification_gain' => 0.7],
        ]);
        $this->assertGreaterThan(0.7, $r['compound_value_score']);
        $this->assertSame([], $r['weak_compound_evidence_task_ids']);
    }

    public function test_no_compound_evidence_flags_task_as_weak(): void
    {
        $r = (new AtlasExternalBrainOriginatorBatchValueAuditor)->compoundProofAudit([
            ['task_id' => 't1', 'impact_score' => 0.5],
        ]);
        $this->assertSame(0.0, $r['compound_value_score']);
        $this->assertSame(['t1'], $r['weak_compound_evidence_task_ids']);
        $this->assertContains('majority_tasks_lack_compound_proof_evidence', $r['recommendation_reasons']);
    }

    public function test_mixed_batch_identifies_weak_tasks(): void
    {
        $r = (new AtlasExternalBrainOriginatorBatchValueAuditor)->compoundProofAudit([
            ['task_id' => 't1', 'downstream_unlocks' => 1.0, 'risk_reduction' => 0.5, 'proof_strength' => 0.8, 'simplification_gain' => 0.3],
            ['task_id' => 't2'],
            ['task_id' => 't3'],
        ]);
        $this->assertSame(['t2', 't3'], $r['weak_compound_evidence_task_ids']);
        $this->assertContains('majority_tasks_lack_compound_proof_evidence', $r['recommendation_reasons']);
    }

    public function test_empty_tasks_returns_zero_score(): void
    {
        $r = (new AtlasExternalBrainOriginatorBatchValueAuditor)->compoundProofAudit([]);
        $this->assertSame(0.0, $r['compound_value_score']);
        $this->assertSame([], $r['weak_compound_evidence_task_ids']);
        $this->assertSame([], $r['recommendation_reasons']);
    }
}
