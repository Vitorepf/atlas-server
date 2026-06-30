<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationRoiLedger;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationRoiLedgerTest extends TestCase
{
    private AtlasExternalBrainSimplificationRoiLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new AtlasExternalBrainSimplificationRoiLedger;
    }

    private function rec(array $candidates): array
    {
        return $this->ledger->record(['candidates' => $candidates]);
    }

    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'id'           => 'c-1',
            'action'       => 'simplify',
            'target'       => 'Foo::bar',
            'roi_estimate' => 1.5,
        ], $overrides);
    }

    // ── AC2: delete without proof → refused ───────────────────────────────────

    public function test_delete_without_replacement_proof_key_is_refused(): void
    {
        $r = $this->rec([$this->candidate(['action' => 'delete'])]);

        $this->assertCount(1, $r['refused']);
        $this->assertSame('deletion_without_replacement_proof', $r['refused'][0]['refusal_reason']);
    }

    public function test_delete_with_empty_replacement_proof_is_refused(): void
    {
        $r = $this->rec([$this->candidate(['action' => 'delete', 'replacement_proof' => ''])]);

        $this->assertSame('deletion_without_replacement_proof', $r['refused'][0]['refusal_reason']);
    }

    public function test_delete_with_coverage_false_is_refused(): void
    {
        $r = $this->rec([$this->candidate([
            'action'             => 'delete',
            'replacement_proof'  => 'test_suite_green',
            'coverage_maintained' => false,
        ])]);

        $this->assertSame('deletion_without_coverage_guarantee', $r['refused'][0]['refusal_reason']);
    }

    public function test_delete_with_proof_and_coverage_true_is_approved(): void
    {
        $r = $this->rec([$this->candidate([
            'action'             => 'delete',
            'replacement_proof'  => 'test_suite_green',
            'coverage_maintained' => true,
        ])]);

        $this->assertCount(1, $r['approved']);
        $this->assertSame('proof_verified', $r['approved'][0]['behavior_preservation_status']);
    }

    // ── AC3: merge/simplify with non-positive ROI → refused + opportunity_cost

    public function test_merge_with_zero_roi_is_refused(): void
    {
        $r = $this->rec([$this->candidate(['action' => 'merge', 'roi_estimate' => 0.0])]);

        $this->assertSame('negative_roi_estimate', $r['refused'][0]['refusal_reason']);
    }

    public function test_simplify_with_negative_roi_is_refused(): void
    {
        $r = $this->rec([$this->candidate(['action' => 'simplify', 'roi_estimate' => -1.0])]);

        $this->assertSame('negative_roi_estimate', $r['refused'][0]['refusal_reason']);
    }

    public function test_refused_roi_equals_opportunity_cost(): void
    {
        $r = $this->rec([
            $this->candidate(['action' => 'simplify', 'roi_estimate' => 3.5]),
            $this->candidate(['id' => 'c-2', 'action' => 'merge', 'roi_estimate' => 0.0]),
        ]);

        $this->assertSame($r['refused_roi'], $r['opportunity_cost']);
    }

    public function test_refused_roi_accumulates_refused_candidates(): void
    {
        $r = $this->rec([
            $this->candidate(['action' => 'delete', 'roi_estimate' => 2.0]),
            $this->candidate(['id' => 'c-2', 'action' => 'delete', 'roi_estimate' => 3.0]),
        ]);

        // Both refused (no replacement_proof) → refused_roi = 5.0
        $this->assertSame(2, $r['refused_count']);
        $this->assertGreaterThan(0.0, $r['refused_roi']);
    }

    // ── AC4: approved entries emit bp_status + next_simplification_action ─────

    public function test_approved_simplify_has_roi_positive_no_proof_status(): void
    {
        $r = $this->rec([$this->candidate(['action' => 'simplify', 'roi_estimate' => 2.0])]);

        $this->assertSame('roi_positive_no_proof', $r['approved'][0]['behavior_preservation_status']);
    }

    public function test_approved_merge_has_next_action(): void
    {
        $r = $this->rec([$this->candidate(['action' => 'merge', 'roi_estimate' => 1.0])]);

        $this->assertArrayHasKey('next_simplification_action', $r);
        $this->assertNotEmpty($r['next_simplification_action']);
    }

    public function test_all_refused_next_action_guides_repair(): void
    {
        $r = $this->rec([$this->candidate(['action' => 'delete'])]);

        $this->assertStringContainsString('proof', $r['next_simplification_action']);
    }

    // ── totals and schema ──────────────────────────────────────────────────────

    public function test_approved_roi_equals_sum_of_approved_estimates(): void
    {
        $r = $this->rec([
            $this->candidate(['roi_estimate' => 1.0]),
            $this->candidate(['id' => 'c-2', 'roi_estimate' => 2.5]),
        ]);

        $this->assertEqualsWithDelta(3.5, $r['approved_roi'], 0.001);
    }

    public function test_schema_is_set(): void
    {
        $r = $this->rec([]);

        $this->assertSame(AtlasExternalBrainSimplificationRoiLedger::SCHEMA, $r['schema_version']);
    }

    // ── deterministic ─────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $candidates = [$this->candidate()];

        $this->assertSame(json_encode($this->rec($candidates)), json_encode($this->rec($candidates)));
    }
}
