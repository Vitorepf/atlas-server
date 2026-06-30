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
}
