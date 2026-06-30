<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFinal95GapBurnDownScheduler;
use Tests\TestCase;

final class AtlasExternalBrainFinal95GapBurnDownSchedulerTest extends TestCase
{
    private function scheduler(): AtlasExternalBrainFinal95GapBurnDownScheduler
    {
        return new AtlasExternalBrainFinal95GapBurnDownScheduler();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->scheduler()->schedule([]);

        $this->assertSame(AtlasExternalBrainFinal95GapBurnDownScheduler::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->scheduler()->schedule([]);

        foreach (['schema', 'burn_down_schedule', 'total_gaps', 'gaps_closeable_without_new_feature_work'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_each_schedule_entry_has_required_fields(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'originator', 'gap_type' => 'missing'],
        ]);

        $entry = $result['burn_down_schedule'][0];
        foreach (['organ_id', 'gap_type', 'priority_rank', 'owner_subsystem', 'resolution_approach', 'cheapest_next_proof', 'stop_condition', 'blocker'] as $f) {
            $this->assertArrayHasKey($f, $entry);
        }
    }

    public function test_empty_input_yields_empty_schedule(): void
    {
        $result = $this->scheduler()->schedule([]);

        $this->assertSame([], $result['burn_down_schedule']);
        $this->assertSame(0, $result['total_gaps']);
        $this->assertSame(0, $result['gaps_closeable_without_new_feature_work']);
    }

    // ── priority ordering ─────────────────────────────────────────────────────

    public function test_blocked_gaps_rank_first(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'organ-stale',   'gap_type' => 'stale'],
            ['organ_id' => 'organ-blocked', 'gap_type' => 'blocked'],
            ['organ_id' => 'organ-thin',    'gap_type' => 'thin'],
            ['organ_id' => 'organ-missing', 'gap_type' => 'missing'],
        ]);

        $ids = array_column($result['burn_down_schedule'], 'organ_id');
        $this->assertSame('organ-blocked', $ids[0]);
    }

    public function test_priority_order_is_blocked_missing_thin_stale(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'b', 'gap_type' => 'stale'],
            ['organ_id' => 'c', 'gap_type' => 'thin'],
            ['organ_id' => 'a', 'gap_type' => 'missing'],
            ['organ_id' => 'd', 'gap_type' => 'blocked'],
        ]);

        $gapTypes = array_column($result['burn_down_schedule'], 'gap_type');
        $this->assertSame(['blocked', 'missing', 'thin', 'stale'], $gapTypes);
    }

    public function test_same_priority_gaps_sorted_alphabetically_by_organ_id(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'zzz', 'gap_type' => 'missing'],
            ['organ_id' => 'aaa', 'gap_type' => 'missing'],
        ]);

        $ids = array_column($result['burn_down_schedule'], 'organ_id');
        $this->assertSame(['aaa', 'zzz'], $ids);
    }

    public function test_priority_rank_starts_at_one_and_increments(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'blocked'],
            ['organ_id' => 'y', 'gap_type' => 'missing'],
        ]);

        $ranks = array_column($result['burn_down_schedule'], 'priority_rank');
        $this->assertSame([1, 2], $ranks);
    }

    // ── resolution approach preference ────────────────────────────────────────

    public function test_evidence_backfill_chosen_when_can_evidence_backfill_true(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'missing', 'can_evidence_backfill' => true, 'can_proof_replay' => true],
        ]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_EVIDENCE_BACKFILL,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_proof_replay_chosen_when_no_backfill_but_can_replay(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'stale', 'can_evidence_backfill' => false, 'can_proof_replay' => true, 'can_consolidate' => true],
        ]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_PROOF_REPLAY,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_consolidation_chosen_when_no_backfill_no_replay(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'thin', 'can_consolidate' => true],
        ]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_CONSOLIDATION,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_unblock_dependency_chosen_for_blocked_gap_with_no_cheap_approaches(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'blocked', 'blocker' => 'upstream-organ'],
        ]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_UNBLOCK_DEPENDENCY,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_new_feature_work_only_when_no_cheaper_option(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'missing'],  // no cheap flags → new_feature_work
        ]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_NEW_FEATURE_WORK,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    // ── stop conditions ───────────────────────────────────────────────────────

    public function test_evidence_backfill_stop_condition(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'missing', 'can_evidence_backfill' => true],
        ]);

        $this->assertSame('first_green_evidence_captured', $result['burn_down_schedule'][0]['stop_condition']);
    }

    public function test_proof_replay_stop_condition(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'stale', 'can_proof_replay' => true],
        ]);

        $this->assertSame('existing_proof_returns_green', $result['burn_down_schedule'][0]['stop_condition']);
    }

    public function test_unblock_stop_condition(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'blocked'],
        ]);

        $this->assertSame('blocker_resolved', $result['burn_down_schedule'][0]['stop_condition']);
    }

    public function test_new_feature_work_stop_condition(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'missing'],
        ]);

        $this->assertSame('acceptance_criteria_green', $result['burn_down_schedule'][0]['stop_condition']);
    }

    // ── cheapest_next_proof ───────────────────────────────────────────────────

    public function test_proof_command_contains_organ_id(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'my-organ', 'gap_type' => 'stale', 'can_evidence_backfill' => true],
        ]);

        $this->assertStringContainsString('my-organ', $result['burn_down_schedule'][0]['cheapest_next_proof']);
    }

    // ── totals ────────────────────────────────────────────────────────────────

    public function test_total_gaps_count(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'a', 'gap_type' => 'missing'],
            ['organ_id' => 'b', 'gap_type' => 'blocked'],
            ['organ_id' => 'c', 'gap_type' => 'thin'],
        ]);

        $this->assertSame(3, $result['total_gaps']);
    }

    public function test_closeable_without_new_feature_work_excludes_new_feature_work_entries(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'a', 'gap_type' => 'missing', 'can_evidence_backfill' => true],  // backfill
            ['organ_id' => 'b', 'gap_type' => 'missing'],  // new_feature_work
            ['organ_id' => 'c', 'gap_type' => 'blocked'],  // unblock_dependency
        ]);

        $this->assertSame(2, $result['gaps_closeable_without_new_feature_work']);
    }

    // ── blocker ───────────────────────────────────────────────────────────────

    public function test_blocker_echoed_in_schedule_entry(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'blocked', 'blocker' => 'upstream-organ-y'],
        ]);

        $this->assertSame('upstream-organ-y', $result['burn_down_schedule'][0]['blocker']);
    }

    public function test_blocker_is_null_when_not_provided(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'missing'],
        ]);

        $this->assertNull($result['burn_down_schedule'][0]['blocker']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $gaps = [
            ['organ_id' => 'b', 'gap_type' => 'stale'],
            ['organ_id' => 'a', 'gap_type' => 'blocked', 'can_evidence_backfill' => true],
        ];

        $this->assertSame($this->scheduler()->schedule($gaps), $this->scheduler()->schedule($gaps));
    }
}
