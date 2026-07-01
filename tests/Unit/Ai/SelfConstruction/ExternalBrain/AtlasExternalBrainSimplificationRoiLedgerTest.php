<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationRoiLedger;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationRoiLedgerTest extends TestCase
{
    private function ledger(): AtlasExternalBrainSimplificationRoiLedger
    {
        return new AtlasExternalBrainSimplificationRoiLedger;
    }

    private function deletion(string $id, string $proof = 'receipt:r1', bool $coverage = true): array
    {
        return [
            'id'                  => $id,
            'action'              => 'delete',
            'target'              => "app/{$id}.php",
            'roi_estimate'        => 0.7,
            'replacement_proof'   => $proof,
            'coverage_maintained' => $coverage,
        ];
    }

    private function merge(string $id, float $roi = 0.5): array
    {
        return ['id' => $id, 'action' => 'merge', 'target' => "app/{$id}.php", 'roi_estimate' => $roi];
    }

    // ── AC1: output shape (existing + new fields) ─────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->ledger()->record([]);
        $this->assertSame(AtlasExternalBrainSimplificationRoiLedger::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('approved', $r);
        $this->assertArrayHasKey('refused', $r);
        $this->assertArrayHasKey('total_roi', $r);
        $this->assertArrayHasKey('refused_roi', $r);
    }

    public function test_output_has_new_required_keys(): void
    {
        $r = $this->ledger()->record([]);

        foreach (['approved_roi', 'opportunity_cost', 'behavior_preservation_status', 'next_simplification_action'] as $k) {
            $this->assertArrayHasKey($k, $r, "Missing key: {$k}");
        }
    }

    public function test_empty_candidates_gives_zero_roi(): void
    {
        $r = $this->ledger()->record([]);
        $this->assertEmpty($r['approved']);
        $this->assertEmpty($r['refused']);
        $this->assertSame(0.0, $r['total_roi']);
    }

    // ── AC1: approved_roi mirrors total_roi ───────────────────────────────────

    public function test_approved_roi_equals_total_roi(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('D1'), $this->merge('M1')]]);

        $this->assertSame($r['total_roi'], $r['approved_roi']);
    }

    // ── AC1: opportunity_cost equals refused_roi ──────────────────────────────

    public function test_opportunity_cost_equals_refused_roi(): void
    {
        $r = $this->ledger()->record(['candidates' => [
            $this->deletion('c1', ''),   // refused
            $this->merge('c2', 0.4),     // approved
        ]]);

        $this->assertSame($r['refused_roi'], $r['opportunity_cost']);
        $this->assertSame(0.7, $r['opportunity_cost']);
    }

    public function test_opportunity_cost_zero_when_all_approved(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('D1'), $this->merge('M1')]]);

        $this->assertSame(0.0, $r['opportunity_cost']);
    }

    // ── AC1: behavior_preservation_status per candidate ───────────────────────

    public function test_approved_delete_has_proof_verified_bp_status(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('D1')]]);

        $this->assertSame('proof_verified', $r['approved'][0]['behavior_preservation_status']);
    }

    public function test_approved_merge_has_roi_positive_no_proof_bp_status(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->merge('M1')]]);

        $this->assertSame('roi_positive_no_proof', $r['approved'][0]['behavior_preservation_status']);
    }

    public function test_refused_candidate_has_refused_bp_status(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('D1', '')]]);

        $this->assertSame('refused', $r['refused'][0]['behavior_preservation_status']);
    }

    // ── AC1: global behavior_preservation_status ──────────────────────────────

    public function test_global_bp_status_no_approved_candidates(): void
    {
        $r = $this->ledger()->record([]);

        $this->assertSame('no_approved_candidates', $r['behavior_preservation_status']);
    }

    public function test_global_bp_status_all_proofs_verified_when_only_deletes_approved(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('D1'), $this->deletion('D2')]]);

        $this->assertSame('all_proofs_verified', $r['behavior_preservation_status']);
    }

    public function test_global_bp_status_no_proofs_submitted_when_only_merges_approved(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->merge('M1'), $this->merge('M2')]]);

        $this->assertSame('no_proofs_submitted', $r['behavior_preservation_status']);
    }

    public function test_global_bp_status_partial_when_mix_of_delete_and_merge_approved(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('D1'), $this->merge('M1')]]);

        $this->assertSame('partial_verification', $r['behavior_preservation_status']);
    }

    // ── AC1: next_simplification_action ──────────────────────────────────────

    public function test_next_action_no_candidates(): void
    {
        $r = $this->ledger()->record([]);

        $this->assertSame('originate_new_simplification_candidates', $r['next_simplification_action']);
    }

    public function test_next_action_all_approved(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('D1'), $this->merge('M1')]]);

        $this->assertSame('proceed_with_approved_simplifications', $r['next_simplification_action']);
    }

    public function test_next_action_add_proof_when_only_missing_proof_refusals(): void
    {
        $r = $this->ledger()->record(['candidates' => [
            $this->deletion('D1', ''),
            $this->deletion('D2', ''),
        ]]);

        $this->assertSame('add_replacement_proof_for_refused_deletions', $r['next_simplification_action']);
    }

    public function test_next_action_improve_roi_when_only_negative_roi_refusals(): void
    {
        $r = $this->ledger()->record(['candidates' => [
            $this->merge('M1', -0.1),
            $this->merge('M2', 0.0),
        ]]);

        $this->assertSame('improve_roi_estimates_for_refused_candidates', $r['next_simplification_action']);
    }

    // ── AC2: deletion refusal guards ──────────────────────────────────────────

    public function test_valid_deletion_is_approved_and_adds_roi(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('DeadService')]]);

        $this->assertCount(1, $r['approved']);
        $this->assertEmpty($r['refused']);
        $this->assertSame(0.7, $r['total_roi']);
        $this->assertSame('delete', $r['approved'][0]['action']);
    }

    public function test_valid_merge_is_approved(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->merge('FooBar', 0.5)]]);

        $this->assertCount(1, $r['approved']);
        $this->assertSame('merge', $r['approved'][0]['action']);
    }

    public function test_deletion_without_replacement_proof_key_is_refused(): void
    {
        $candidate = ['id' => 'c1', 'action' => 'delete', 'target' => 'app/Old.php', 'roi_estimate' => 0.6];
        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertEmpty($r['approved']);
        $this->assertCount(1, $r['refused']);
        $this->assertSame('deletion_without_replacement_proof', $r['refused'][0]['refusal_reason']);
    }

    public function test_deletion_with_empty_replacement_proof_is_refused(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('c1', '')]]);

        $this->assertEmpty($r['approved']);
        $this->assertSame('deletion_without_replacement_proof', $r['refused'][0]['refusal_reason']);
    }

    public function test_deletion_with_explicit_false_coverage_is_refused(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('c1', 'proof:ok', false)]]);

        $this->assertEmpty($r['approved']);
        $this->assertSame('deletion_without_coverage_guarantee', $r['refused'][0]['refusal_reason']);
    }

    public function test_proof_check_takes_precedence_over_coverage_check(): void
    {
        $candidate = [
            'id'                  => 'c1',
            'action'              => 'delete',
            'target'              => 'app/Old.php',
            'roi_estimate'        => 0.6,
            'replacement_proof'   => '',
            'coverage_maintained' => false,
        ];
        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertSame('deletion_without_replacement_proof', $r['refused'][0]['refusal_reason']);
    }

    public function test_deletion_with_true_coverage_and_proof_is_approved(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('c1', 'receipt:ok', true)]]);
        $this->assertCount(1, $r['approved']);
    }

    // ── ROI accounting ────────────────────────────────────────────────────────

    public function test_refused_roi_accumulates_opportunity_cost(): void
    {
        $r = $this->ledger()->record(['candidates' => [
            $this->deletion('c1', ''),
            $this->merge('c2', 0.4),
        ]]);

        $this->assertSame(0.4, $r['total_roi']);
        $this->assertSame(0.7, $r['refused_roi']);
    }

    public function test_merge_with_negative_roi_is_refused(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->merge('c1', -0.1)]]);

        $this->assertEmpty($r['approved']);
        $this->assertSame('negative_roi_estimate', $r['refused'][0]['refusal_reason']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = ['candidates' => [$this->deletion('x1'), $this->merge('x2'), $this->deletion('x3', '')]];
        $a     = $this->ledger()->record($facts);
        $b     = $this->ledger()->record($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC2: approved entries carry the full ROI proof fields ─────────────────

    public function test_approved_entry_has_all_required_fields(): void
    {
        $candidate = array_merge($this->deletion('D1'), [
            'tests_preserved' => true,
            'line_delta' => -120,
            'cognitive_load_delta' => -0.3,
            'compounding_benefit' => 0.6,
        ]);

        $r = $this->ledger()->record(['candidates' => [$candidate]]);
        $entry = $r['approved'][0];

        foreach (['behavior_preservation_status', 'tests_preserved', 'line_delta', 'cognitive_load_delta', 'compounding_benefit', 'approved_roi', 'next_simplification_action'] as $field) {
            $this->assertArrayHasKey($field, $entry, "approved entry missing field: {$field}");
        }
        $this->assertTrue($entry['tests_preserved']);
        $this->assertSame(-120, $entry['line_delta']);
        $this->assertSame(-0.3, $entry['cognitive_load_delta']);
        $this->assertSame(0.6, $entry['compounding_benefit']);
        $this->assertSame(0.7, $entry['approved_roi']);
        $this->assertStringContainsString('D1', $entry['next_simplification_action']);
    }

    public function test_approved_entry_defaults_new_fields_when_absent(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('D2')]]);
        $entry = $r['approved'][0];

        $this->assertFalse($entry['tests_preserved']);
        $this->assertSame(0, $entry['line_delta']);
        $this->assertSame(0.0, $entry['cognitive_load_delta']);
        $this->assertSame(0.0, $entry['compounding_benefit']);
    }

    // ── AC3: refused entries carry refused_roi / opportunity_cost / refusal_reason ──

    public function test_refused_entry_has_refused_roi_and_opportunity_cost(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->merge('M1', -1.5)]]);
        $entry = $r['refused'][0];

        $this->assertArrayHasKey('refused_roi', $entry);
        $this->assertArrayHasKey('opportunity_cost', $entry);
        $this->assertArrayHasKey('refusal_reason', $entry);
        $this->assertSame(-1.5, $entry['refused_roi']);
        $this->assertSame(-1.5, $entry['opportunity_cost']);
        $this->assertSame('negative_roi_estimate', $entry['refusal_reason']);
    }

    public function test_deletion_without_replacement_proof_refused_entry_has_roi_fields(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('D3', '')]]);
        $entry = $r['refused'][0];

        $this->assertSame('deletion_without_replacement_proof', $entry['refusal_reason']);
        $this->assertArrayHasKey('refused_roi', $entry);
        $this->assertArrayHasKey('opportunity_cost', $entry);
    }

    // ── AC3 (opt-in): missing behavior preservation proof when explicitly required ──

    public function test_requires_behavior_preservation_proof_without_proof_is_refused(): void
    {
        $candidate = array_merge($this->merge('M2', 2.0), ['requires_behavior_preservation_proof' => true]);

        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertEmpty($r['approved']);
        $this->assertSame('missing_behavior_preservation_proof', $r['refused'][0]['refusal_reason']);
    }

    public function test_requires_behavior_preservation_proof_with_proof_is_approved(): void
    {
        $candidate = array_merge($this->merge('M3', 2.0), [
            'requires_behavior_preservation_proof' => true,
            'behavior_preservation_proof' => 'receipt:bp1',
        ]);

        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertEmpty($r['refused']);
        $this->assertSame('roi_positive_no_proof', $r['approved'][0]['behavior_preservation_status']);
    }

    public function test_ordinary_merge_without_proof_requirement_still_approved_by_default(): void
    {
        // Regression guard: the default contract (no proof required for plain merge/simplify) must
        // never break — this is what the Feature test suite for this ledger already pins.
        $r = $this->ledger()->record(['candidates' => [$this->merge('M4', 1.2)]]);

        $this->assertCount(1, $r['approved']);
        $this->assertSame('roi_positive_no_proof', $r['approved'][0]['behavior_preservation_status']);
    }

    // ── new AC1: simplify/merge with requires_behavior_preservation_proof=true and no proof refused ──

    public function test_simplify_action_with_required_proof_missing_is_refused(): void
    {
        $candidate = [
            'id' => 'S1', 'action' => 'simplify', 'target' => 'app/S1.php', 'roi_estimate' => 1.0,
            'requires_behavior_preservation_proof' => true,
        ];

        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertEmpty($r['approved']);
        $this->assertSame('missing_behavior_preservation_proof', $r['refused'][0]['refusal_reason']);
    }

    // ── new AC2: rollback proof absence reduces/refuses risk_adjusted_roi for high-risk candidates ──

    public function test_high_risk_candidate_without_rollback_proof_has_zero_risk_adjusted_roi(): void
    {
        $candidate = array_merge($this->merge('HR1', 1.0), ['risk_level' => 'high']);

        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertCount(1, $r['approved']);
        $this->assertSame(1.0, $r['approved'][0]['raw_roi']);
        $this->assertSame(0.0, $r['approved'][0]['risk_adjusted_roi']);
    }

    public function test_high_risk_candidate_with_only_rollback_proof_still_has_zero_risk_adjusted_roi(): void
    {
        // AC2: high-risk needs BOTH proofs — rollback alone never proves behavior was preserved.
        $candidate = array_merge($this->merge('HR2', 1.0), [
            'risk_level' => 'high',
            'rollback_proof' => 'receipt:rb1',
        ]);

        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertSame(1.0, $r['approved'][0]['raw_roi']);
        $this->assertSame(0.0, $r['approved'][0]['risk_adjusted_roi']);
    }

    public function test_high_risk_candidate_with_both_proofs_has_reduced_but_nonzero_risk_adjusted_roi(): void
    {
        $candidate = array_merge($this->merge('HR3', 1.0), [
            'risk_level' => 'high',
            'rollback_proof' => 'receipt:rb1',
            'behavior_preservation_proof' => 'receipt:bp1',
        ]);

        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertSame(1.0, $r['approved'][0]['raw_roi']);
        $this->assertLessThan(1.0, $r['approved'][0]['risk_adjusted_roi']);
        $this->assertGreaterThan(0.0, $r['approved'][0]['risk_adjusted_roi']);
    }

    public function test_low_risk_candidate_keeps_full_risk_adjusted_roi(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->merge('LR1', 1.0)]]);

        $this->assertSame(1.0, $r['approved'][0]['raw_roi']);
        $this->assertSame(1.0, $r['approved'][0]['risk_adjusted_roi']);
    }

    // ── new AC3: approved entries include raw_roi, risk_adjusted_roi, next_simplification_action ──

    public function test_approved_entries_include_raw_roi_risk_adjusted_roi_and_next_action_deterministically(): void
    {
        $candidate = array_merge($this->deletion('D9'), ['risk_level' => 'medium', 'rollback_proof' => 'receipt:rb2']);

        $a = $this->ledger()->record(['candidates' => [$candidate]]);
        $b = $this->ledger()->record(['candidates' => [$candidate]]);

        foreach ([$a, $b] as $r) {
            $entry = $r['approved'][0];
            $this->assertArrayHasKey('raw_roi', $entry);
            $this->assertArrayHasKey('risk_adjusted_roi', $entry);
            $this->assertArrayHasKey('next_simplification_action', $entry);
            $this->assertStringContainsString('D9', $entry['next_simplification_action']);
        }
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── batch_roi_summary ──────────────────────────────────────────────────────

    public function test_batch_roi_summary_has_required_fields(): void
    {
        $r = $this->ledger()->record([
            'candidates' => [$this->deletion('D1'), $this->merge('M1', -0.2)],
        ]);

        $summary = $r['batch_roi_summary'];
        foreach (['approved_count', 'refused_count', 'approved_roi', 'refused_roi', 'risk_adjusted_approved_roi', 'top_refusal_reasons'] as $k) {
            $this->assertArrayHasKey($k, $summary);
        }
        $this->assertSame(1, $summary['approved_count']);
        $this->assertSame(1, $summary['refused_count']);
    }

    public function test_top_refusal_reasons_grouped_deterministically(): void
    {
        $r = $this->ledger()->record([
            'candidates' => [
                $this->merge('M1', -0.2),
                $this->merge('M2', -0.3),
                ['id' => 'D1', 'action' => 'delete', 'roi_estimate' => 0.5],
            ],
        ]);

        $reasons = $r['batch_roi_summary']['top_refusal_reasons'];
        $byReason = array_column($reasons, 'count', 'reason');
        $this->assertSame(2, $byReason['negative_roi_estimate']);
        $this->assertSame(1, $byReason['deletion_without_replacement_proof']);
    }

    // ── AC2/AC3: roi_classification (high_value vs low_value_cosmetic) ──────────

    public function test_pure_line_deletion_with_no_structural_benefit_is_cosmetic(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('D1')]]);

        $this->assertSame('low_value_cosmetic', $r['approved'][0]['roi_classification']);
    }

    public function test_deletion_with_collapsed_organs_is_high_value(): void
    {
        $candidate = array_merge($this->deletion('D1'), ['collapsed_organs' => 2]);

        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertSame(2, $r['approved'][0]['collapsed_organs']);
        $this->assertSame('high_value', $r['approved'][0]['roi_classification']);
    }

    public function test_merge_with_dependency_reduction_is_high_value(): void
    {
        $candidate = array_merge($this->merge('M1'), ['dependency_reduction' => 3]);

        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertSame(3, $r['approved'][0]['dependency_reduction']);
        $this->assertSame('high_value', $r['approved'][0]['roi_classification']);
    }

    public function test_simplify_with_risk_reduced_is_high_value(): void
    {
        $candidate = ['id' => 'S1', 'action' => 'simplify', 'roi_estimate' => 0.4, 'risk_reduced' => true];

        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertTrue($r['approved'][0]['risk_reduced']);
        $this->assertSame('high_value', $r['approved'][0]['roi_classification']);
    }

    public function test_maintenance_savings_alone_is_high_value(): void
    {
        $candidate = array_merge($this->merge('M2'), ['maintenance_savings' => 1.5]);

        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertSame(1.5, $r['approved'][0]['maintenance_savings']);
        $this->assertSame('high_value', $r['approved'][0]['roi_classification']);
    }

    // ── risky simplification without behavior proof ──────────────────────────

    public function test_high_risk_simplify_without_behavior_proof_is_refused_not_downgraded(): void
    {
        $candidate = [
            'id' => 'RS1', 'action' => 'simplify', 'roi_estimate' => 1.0,
            'risk_level' => 'high',
            'requires_behavior_preservation_proof' => true,
        ];

        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertEmpty($r['approved']);
        $this->assertSame('missing_behavior_preservation_proof', $r['refused'][0]['refusal_reason']);
    }

    public function test_risky_approved_candidate_reduces_risk_adjusted_roi_not_raw_roi(): void
    {
        $r = $this->ledger()->record([
            'candidates' => [
                array_merge($this->deletion('D1'), ['risk_level' => 'high']),
            ],
        ]);

        $summary = $r['batch_roi_summary'];
        $this->assertSame($summary['approved_roi'], $r['approved']['0']['raw_roi'] ?? $r['approved'][0]['raw_roi']);
        $this->assertLessThan($summary['approved_roi'], $summary['risk_adjusted_approved_roi']);
    }

    // ── new AC1: cosmetic candidates never count toward structural_roi credit ──

    public function test_cosmetic_candidate_has_zero_structural_roi_credit_despite_positive_roi_estimate(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('D1')]]);

        $this->assertSame('low_value_cosmetic', $r['approved'][0]['roi_classification']);
        $this->assertGreaterThan(0.0, $r['approved'][0]['roi_estimate']);
        $this->assertSame(0.0, $r['approved'][0]['structural_roi_credit']);
    }

    public function test_high_value_candidate_has_nonzero_structural_roi_credit(): void
    {
        $candidate = array_merge($this->deletion('D1'), ['collapsed_organs' => 1]);
        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertSame('high_value', $r['approved'][0]['roi_classification']);
        $this->assertGreaterThan(0.0, $r['approved'][0]['structural_roi_credit']);
    }

    // ── new AC2: high-risk merge/simplify requires BOTH proofs before structural credit ──

    public function test_high_risk_high_value_candidate_without_behavior_proof_has_zero_structural_credit(): void
    {
        $candidate = array_merge($this->merge('HR4', 1.0), [
            'collapsed_organs' => 1,
            'risk_level' => 'high',
            'rollback_proof' => 'receipt:rb1',
        ]);

        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertSame('high_value', $r['approved'][0]['roi_classification']);
        $this->assertSame(0.0, $r['approved'][0]['structural_roi_credit']);
    }

    public function test_high_risk_high_value_candidate_with_both_proofs_has_nonzero_structural_credit(): void
    {
        $candidate = array_merge($this->merge('HR5', 1.0), [
            'collapsed_organs' => 1,
            'risk_level' => 'high',
            'rollback_proof' => 'receipt:rb1',
            'behavior_preservation_proof' => 'receipt:bp1',
        ]);

        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertGreaterThan(0.0, $r['approved'][0]['structural_roi_credit']);
    }

    // ── new AC3: batch_roi_summary separates structural_roi from cosmetic_roi ──

    public function test_batch_roi_summary_separates_structural_roi_from_cosmetic_roi(): void
    {
        $r = $this->ledger()->record(['candidates' => [
            $this->deletion('D1'), // cosmetic
            array_merge($this->merge('M1', 1.0), ['collapsed_organs' => 1]), // structural
        ]]);

        $summary = $r['batch_roi_summary'];
        $this->assertArrayHasKey('structural_roi', $summary);
        $this->assertArrayHasKey('cosmetic_roi', $summary);
        $this->assertSame(0.7, $summary['cosmetic_roi']);
        $this->assertSame(1.0, $summary['structural_roi']);
    }

    public function test_batch_roi_summary_reports_top_refusal_reasons_alongside_structural_split(): void
    {
        $r = $this->ledger()->record(['candidates' => [
            $this->deletion('D1', ''), // refused
            array_merge($this->merge('M1', 1.0), ['collapsed_organs' => 1]),
        ]]);

        $summary = $r['batch_roi_summary'];
        $this->assertNotEmpty($summary['top_refusal_reasons']);
        $this->assertSame('deletion_without_replacement_proof', $summary['top_refusal_reasons'][0]['reason']);
        $this->assertSame(1.0, $summary['structural_roi']);
        $this->assertSame(0.0, $summary['cosmetic_roi']);
    }
}
