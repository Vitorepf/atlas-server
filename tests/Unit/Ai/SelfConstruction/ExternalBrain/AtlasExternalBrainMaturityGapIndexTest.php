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

    // ── final-95 blocker map fields ───────────────────────────────────────────

    public function test_gaps_include_blocker_map_keys(): void
    {
        $rubric = [$this->dim('loop_origination', 0.9, ['loop_signal'])];
        $r = $this->index->compute($rubric, ['proven_evidence' => []]);

        $gap = $r['gaps'][0];
        foreach (['blocker_class', 'next_best_task_family', 'missing_proof_type',
                  'autonomy_blocker', 'simplification_needed', 'readiness_tier'] as $key) {
            $this->assertArrayHasKey($key, $gap, "gap must contain {$key}");
        }
    }

    public function test_blocker_class_no_evidence_yet_when_no_queue_and_proof_gap_one(): void
    {
        $rubric = [$this->dim('dim', 0.8, ['s1'])];
        $r = $this->index->compute($rubric, ['proven_evidence' => [], 'queue_counts' => []]);

        $this->assertSame('no_evidence_yet', $r['gaps'][0]['blocker_class']);
    }

    public function test_blocker_class_queue_without_proof_when_queue_active_but_no_evidence(): void
    {
        $rubric = [$this->dim('dim', 0.8, ['s1'])];
        $r = $this->index->compute($rubric, [
            'proven_evidence' => [],
            'queue_counts'    => ['dim' => 5],
        ]);

        $this->assertSame('queue_without_proof', $r['gaps'][0]['blocker_class']);
    }

    public function test_blocker_class_partial_evidence_gap_when_some_proven(): void
    {
        $rubric = [$this->dim('dim', 0.8, ['s1', 's2'])];
        $r = $this->index->compute($rubric, ['proven_evidence' => ['s1']]);

        $this->assertSame('partial_evidence_gap', $r['gaps'][0]['blocker_class']);
    }

    public function test_readiness_tier_not_started_when_proof_gap_one(): void
    {
        $rubric = [$this->dim('dim', 0.8, ['s1', 's2'])];
        $r = $this->index->compute($rubric, ['proven_evidence' => []]);

        $this->assertSame('not_started', $r['gaps'][0]['readiness_tier']);
    }

    public function test_readiness_tier_partial_when_proof_gap_between_025_and_1(): void
    {
        $rubric = [$this->dim('dim', 0.8, ['s1', 's2', 's3', 's4'])];
        // 3 of 4 missing → proof_gap = 0.75
        $r = $this->index->compute($rubric, ['proven_evidence' => ['s1']]);

        $this->assertSame('partial', $r['gaps'][0]['readiness_tier']);
    }

    public function test_readiness_tier_near_complete_when_proof_gap_small(): void
    {
        $rubric = [$this->dim('dim', 0.8, ['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8'])];
        // only 1 of 8 missing → proof_gap = 0.125
        $proven = ['s1', 's2', 's3', 's4', 's5', 's6', 's7'];
        $r = $this->index->compute($rubric, ['proven_evidence' => $proven]);

        $this->assertSame('near_complete', $r['gaps'][0]['readiness_tier']);
    }

    public function test_next_best_task_family_bootstrap_when_proof_gap_one(): void
    {
        $rubric = [$this->dim('dim', 0.8, ['s1'], 'wiring')];
        $r = $this->index->compute($rubric, ['proven_evidence' => []]);

        $this->assertSame('wiring_bootstrap', $r['gaps'][0]['next_best_task_family']);
    }

    public function test_next_best_task_family_evidence_close_when_partial(): void
    {
        $rubric = [$this->dim('dim', 0.8, ['s1', 's2'], 'wiring')];
        $r = $this->index->compute($rubric, ['proven_evidence' => ['s1']]);

        $this->assertSame('wiring_evidence_close', $r['gaps'][0]['next_best_task_family']);
    }

    public function test_missing_proof_type_test_gate_when_signal_contains_test(): void
    {
        $rubric = [$this->dim('dim', 0.5, ['test_passes_green', 'gate_rejects_invalid'])];
        $r = $this->index->compute($rubric, ['proven_evidence' => []]);

        $this->assertSame('test_gate', $r['gaps'][0]['missing_proof_type']);
    }

    public function test_missing_proof_type_certification_when_signal_contains_cert(): void
    {
        $rubric = [$this->dim('dim', 0.5, ['certification_issued', 'cert_verified'])];
        $r = $this->index->compute($rubric, ['proven_evidence' => []]);

        $this->assertSame('certification', $r['gaps'][0]['missing_proof_type']);
    }

    public function test_missing_proof_type_runtime_evidence_when_signal_contains_runtime(): void
    {
        $rubric = [$this->dim('dim', 0.5, ['runtime_health_proven', 'live_evidence'])];
        $r = $this->index->compute($rubric, ['proven_evidence' => []]);

        $this->assertSame('runtime_evidence', $r['gaps'][0]['missing_proof_type']);
    }

    public function test_autonomy_blocker_true_for_high_leverage(): void
    {
        $rubric = [$this->dim('dim', 0.9, ['s1'])];
        $r = $this->index->compute($rubric, ['proven_evidence' => []]);

        $this->assertTrue($r['gaps'][0]['autonomy_blocker']);
    }

    public function test_autonomy_blocker_false_for_low_leverage(): void
    {
        $rubric = [$this->dim('dim', 0.3, ['s1'])];
        $r = $this->index->compute($rubric, ['proven_evidence' => []]);

        $this->assertFalse($r['gaps'][0]['autonomy_blocker']);
    }

    public function test_autonomy_blocker_explicit_override_from_rubric(): void
    {
        $dim = array_merge($this->dim('dim', 0.9, ['s1']), ['autonomy_blocker' => false]);
        $r = $this->index->compute([$dim], ['proven_evidence' => []]);

        // leverage 0.9 would derive true, but explicit false wins
        $this->assertFalse($r['gaps'][0]['autonomy_blocker']);
    }

    // ── acceptance criterion: queue-active dimension stays in blocker map ─────

    public function test_queue_active_dimension_with_no_proof_appears_in_blocker_map(): void
    {
        $rubric = [$this->dim('loop_origination', 0.9, ['loop_originates_novel_task'])];

        $r = $this->index->compute($rubric, [
            'proven_evidence' => [],
            'queue_counts'    => ['loop_origination' => 42],
        ]);

        // Dimension must remain incomplete
        $this->assertSame([], $r['complete_dimensions']);
        $this->assertCount(1, $r['gaps']);

        $gap = $r['gaps'][0];
        $this->assertSame('loop_origination', $gap['dimension']);
        $this->assertTrue($gap['has_queue_activity']);

        // Must appear in blocker map with queue_without_proof class
        $this->assertSame('queue_without_proof', $gap['blocker_class']);
        $this->assertArrayHasKey('readiness_tier',       $gap);
        $this->assertArrayHasKey('missing_proof_type',   $gap);
        $this->assertArrayHasKey('autonomy_blocker',     $gap);
        $this->assertArrayHasKey('simplification_needed', $gap);
    }

    // ── AC1: next_chain_step structure ────────────────────────────────────────

    public function test_next_chain_step_has_required_fields(): void
    {
        $rubric = [$this->dim('loop_origination', 0.9, ['loop_signal'], 'wiring')];
        $gap    = $this->index->compute($rubric, ['proven_evidence' => []])['gaps'][0];

        $this->assertArrayHasKey('next_chain_step', $gap);
        $ncs = $gap['next_chain_step'];
        foreach (['task_family', 'required_proof_type', 'why_this_unblocks_autonomy'] as $key) {
            $this->assertArrayHasKey($key, $ncs, "next_chain_step must contain {$key}");
        }
        $this->assertNotEmpty($ncs['why_this_unblocks_autonomy']);
    }

    // ── AC2: next_chain_step differs by blocker_class ─────────────────────────

    public function test_next_chain_step_why_for_no_evidence_yet_mentions_bootstrap(): void
    {
        // proof_gap=1.0, no queue → no_evidence_yet
        $rubric = [$this->dim('dim', 0.8, ['s1'], 'wiring')];
        $gap    = $this->index->compute($rubric, ['proven_evidence' => []])['gaps'][0];

        $this->assertSame('no_evidence_yet', $gap['blocker_class']);
        $this->assertStringContainsStringIgnoringCase('bootstrap', $gap['next_chain_step']['why_this_unblocks_autonomy']);
    }

    public function test_next_chain_step_why_for_queue_without_proof_mentions_proof(): void
    {
        // proof_gap=1.0, queue active → queue_without_proof
        $rubric = [$this->dim('dim', 0.8, ['s1'], 'cert')];
        $gap    = $this->index->compute($rubric, [
            'proven_evidence' => [],
            'queue_counts'    => ['dim' => 3],
        ])['gaps'][0];

        $this->assertSame('queue_without_proof', $gap['blocker_class']);
        $this->assertStringContainsStringIgnoringCase('proof', $gap['next_chain_step']['why_this_unblocks_autonomy']);
    }

    public function test_next_chain_step_why_for_partial_evidence_gap_mentions_gap(): void
    {
        // partial proof → partial_evidence_gap
        $rubric = [$this->dim('dim', 0.8, ['s1', 's2'], 'wiring')];
        $gap    = $this->index->compute($rubric, ['proven_evidence' => ['s1']])['gaps'][0];

        $this->assertSame('partial_evidence_gap', $gap['blocker_class']);
        $this->assertStringContainsStringIgnoringCase('gap', $gap['next_chain_step']['why_this_unblocks_autonomy']);
    }

    public function test_next_chain_step_task_family_matches_blocker_map(): void
    {
        $rubric = [$this->dim('dim', 0.8, ['s1'], 'cert')];
        $gap    = $this->index->compute($rubric, ['proven_evidence' => []])['gaps'][0];

        // next_chain_step.task_family must equal next_best_task_family
        $this->assertSame($gap['next_best_task_family'], $gap['next_chain_step']['task_family']);
    }

    public function test_next_chain_step_required_proof_type_matches_missing_proof_type(): void
    {
        $rubric = [$this->dim('dim', 0.5, ['cert_issued'], 'cert')];
        $gap    = $this->index->compute($rubric, ['proven_evidence' => []])['gaps'][0];

        $this->assertSame($gap['missing_proof_type'], $gap['next_chain_step']['required_proof_type']);
    }
}
