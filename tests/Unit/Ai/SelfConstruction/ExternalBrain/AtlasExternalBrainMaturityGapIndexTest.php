<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMaturityGapIndex;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainMaturityGapIndexTest extends TestCase
{
    private AtlasExternalBrainMaturityGapIndex $index;

    protected function setUp(): void
    {
        $this->index = new AtlasExternalBrainMaturityGapIndex;
    }

    private function dim(string $name, float $leverage, array $signals, string $family = 'wiring'): array
    {
        return [
            'dimension' => $name,
            'leverage' => $leverage,
            'required_evidence_signals' => $signals,
            'task_family' => $family,
        ];
    }

    // ── happy path: partial snapshot produces prioritised gaps ────────────────

    public function test_partial_snapshot_produces_gaps_with_all_required_keys(): void
    {
        $rubric = [
            $this->dim('loop_origination', 0.9, ['loop_originates_novel_task', 'loop_does_not_recycle']),
            $this->dim('cert_gate', 0.7, ['cert_gate_rejects_bad_task']),
        ];

        $r = $this->index->compute($rubric, ['proven_evidence' => []]);

        $this->assertSame(AtlasExternalBrainMaturityGapIndex::SCHEMA, $r['schema']);
        $this->assertCount(2, $r['gaps']);
        $this->assertSame([], $r['complete_dimensions']);

        foreach ($r['gaps'] as $gap) {
            foreach (['dimension', 'leverage', 'proof_gap', 'missing_evidence',
                      'has_queue_activity', 'suggested_task_family'] as $key) {
                $this->assertArrayHasKey($key, $gap, "gap must contain {$key}");
            }
        }
    }

    public function test_gaps_sorted_by_leverage_desc_then_proof_gap_desc(): void
    {
        $rubric = [
            $this->dim('low_leverage', 0.3, ['sig_a']),
            $this->dim('high_leverage', 0.9, ['sig_b']),
            $this->dim('mid_leverage', 0.6, ['sig_c']),
        ];

        $r = $this->index->compute($rubric, ['proven_evidence' => []]);

        $this->assertSame('high_leverage', $r['gaps'][0]['dimension']);
        $this->assertSame('mid_leverage', $r['gaps'][1]['dimension']);
        $this->assertSame('low_leverage', $r['gaps'][2]['dimension']);
    }

    public function test_when_same_leverage_sort_by_proof_gap_desc(): void
    {
        $rubric = [
            $this->dim('half_proven', 0.8, ['sig_a', 'sig_b']),  // 1 of 2 missing → proof_gap=0.5
            $this->dim('fully_missing', 0.8, ['sig_c', 'sig_d']),  // 2 of 2 missing → proof_gap=1.0
        ];

        $r = $this->index->compute($rubric, [
            'proven_evidence' => ['sig_a'],  // half_proven has sig_a proven
        ]);

        $this->assertSame('fully_missing', $r['gaps'][0]['dimension'], 'higher proof_gap goes first');
        $this->assertSame('half_proven', $r['gaps'][1]['dimension']);
    }

    // ── complete dimensions ───────────────────────────────────────────────────

    public function test_dimension_marked_complete_when_all_signals_proven(): void
    {
        $rubric = [
            $this->dim('cert_gate', 0.7, ['sig_proven_1', 'sig_proven_2']),
            $this->dim('loop_origination', 0.9, ['sig_missing']),
        ];

        $r = $this->index->compute($rubric, [
            'proven_evidence' => ['sig_proven_1', 'sig_proven_2'],
        ]);

        $this->assertContains('cert_gate', $r['complete_dimensions']);
        $this->assertCount(1, $r['gaps']);
        $this->assertSame('loop_origination', $r['gaps'][0]['dimension']);
    }

    // ── THE KEY INVARIANT: queue counts do NOT prove completeness ─────────────

    public function test_queue_counts_alone_do_not_mark_dimension_complete(): void
    {
        $rubric = [
            $this->dim('loop_origination', 0.9, ['loop_originates_novel_task']),
        ];

        $r = $this->index->compute($rubric, [
            'proven_evidence' => [],
            'queue_counts' => ['loop_origination' => 999],  // lots of queue activity, no proven signals
        ]);

        $this->assertSame([], $r['complete_dimensions'], 'queue counts must never imply completeness');
        $this->assertCount(1, $r['gaps']);
        $this->assertTrue($r['gaps'][0]['has_queue_activity'], 'queue activity should be surfaced informatively');
    }

    public function test_has_queue_activity_false_when_no_queue_entries(): void
    {
        $rubric = [$this->dim('cert_gate', 0.7, ['sig_missing'])];

        $r = $this->index->compute($rubric, ['proven_evidence' => [], 'queue_counts' => []]);

        $this->assertFalse($r['gaps'][0]['has_queue_activity']);
    }

    // ── proof_gap calculation ─────────────────────────────────────────────────

    public function test_proof_gap_is_zero_when_all_signals_proven(): void
    {
        // Dimension should become complete — not appear in gaps at all.
        $rubric = [$this->dim('dim', 0.5, ['s1', 's2'])];
        $r = $this->index->compute($rubric, ['proven_evidence' => ['s1', 's2']]);

        $this->assertSame([], $r['gaps']);
        $this->assertContains('dim', $r['complete_dimensions']);
    }

    public function test_proof_gap_is_one_when_nothing_proven(): void
    {
        $rubric = [$this->dim('dim', 0.5, ['s1', 's2'])];
        $r = $this->index->compute($rubric, ['proven_evidence' => []]);

        $this->assertEqualsWithDelta(1.0, $r['gaps'][0]['proof_gap'], 0.0001);
    }

    public function test_proof_gap_is_half_when_one_of_two_proven(): void
    {
        $rubric = [$this->dim('dim', 0.5, ['s1', 's2'])];
        $r = $this->index->compute($rubric, ['proven_evidence' => ['s1']]);

        $this->assertEqualsWithDelta(0.5, $r['gaps'][0]['proof_gap'], 0.0001);
        $this->assertSame(['s2'], $r['gaps'][0]['missing_evidence']);
    }

    // ── missing_evidence accuracy ─────────────────────────────────────────────

    public function test_missing_evidence_lists_only_unproven_signals(): void
    {
        $rubric = [$this->dim('dim', 0.5, ['s1', 's2', 's3'])];
        $r = $this->index->compute($rubric, ['proven_evidence' => ['s1', 's3']]);

        $this->assertSame(['s2'], $r['gaps'][0]['missing_evidence']);
    }

    // ── suggested_task_family propagates ─────────────────────────────────────

    public function test_suggested_task_family_propagates_from_rubric(): void
    {
        $rubric = [$this->dim('dim', 0.5, ['s1'], 'cert_family')];
        $r = $this->index->compute($rubric, ['proven_evidence' => []]);

        $this->assertSame('cert_family', $r['gaps'][0]['suggested_task_family']);
    }

    // ── empty inputs ──────────────────────────────────────────────────────────

    public function test_empty_rubric_returns_empty_result(): void
    {
        $r = $this->index->compute([], ['proven_evidence' => []]);

        $this->assertSame([], $r['gaps']);
        $this->assertSame([], $r['complete_dimensions']);
    }

    public function test_all_dimensions_complete_returns_no_gaps(): void
    {
        $rubric = [
            $this->dim('d1', 0.9, ['sig_a']),
            $this->dim('d2', 0.5, ['sig_b']),
        ];

        $r = $this->index->compute($rubric, ['proven_evidence' => ['sig_a', 'sig_b']]);

        $this->assertSame([], $r['gaps']);
        $this->assertCount(2, $r['complete_dimensions']);
    }
}
