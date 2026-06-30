<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFinal95GapBurnDownScheduler;
use Tests\TestCase;

final class AtlasExternalBrainFinal95GapBurnDownSchedulerTest extends TestCase
{
    private AtlasExternalBrainFinal95GapBurnDownScheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduler = new AtlasExternalBrainFinal95GapBurnDownScheduler;
    }

    // ── AC2: blocked sorts before all other gap types

    public function test_ac2_blocked_sorts_before_missing(): void
    {
        $result = $this->scheduler->schedule([
            ['organ_id' => 'B', 'gap_type' => 'missing'],
            ['organ_id' => 'A', 'gap_type' => 'blocked', 'blocker' => 'dep-x'],
        ]);

        $schedule = $result['burn_down_schedule'];
        $this->assertSame('A', $schedule[0]['organ_id']);
        $this->assertSame('B', $schedule[1]['organ_id']);
    }

    public function test_ac2_blocked_sorts_before_all_lower_priority_types(): void
    {
        $result = $this->scheduler->schedule([
            ['organ_id' => 'G', 'gap_type' => 'weak_outcome_learning'],
            ['organ_id' => 'F', 'gap_type' => 'doc_drift'],
            ['organ_id' => 'E', 'gap_type' => 'integration_debt'],
            ['organ_id' => 'D', 'gap_type' => 'stale'],
            ['organ_id' => 'C', 'gap_type' => 'thin'],
            ['organ_id' => 'B', 'gap_type' => 'missing'],
            ['organ_id' => 'A', 'gap_type' => 'blocked'],
        ]);

        $this->assertSame('blocked', $result['burn_down_schedule'][0]['gap_type']);
        $this->assertSame('missing', $result['burn_down_schedule'][1]['gap_type']);
        $this->assertSame('thin', $result['burn_down_schedule'][2]['gap_type']);
        $this->assertSame('stale', $result['burn_down_schedule'][3]['gap_type']);
        $this->assertSame('integration_debt', $result['burn_down_schedule'][4]['gap_type']);
        $this->assertSame('doc_drift', $result['burn_down_schedule'][5]['gap_type']);
        $this->assertSame('weak_outcome_learning', $result['burn_down_schedule'][6]['gap_type']);
    }

    // ── AC3: cheap proof flags → non-feature approach chosen first

    public function test_ac3_can_evidence_backfill_chooses_evidence_backfill(): void
    {
        $result = $this->scheduler->schedule([[
            'organ_id'              => 'AtlasLeanOrgan',
            'gap_type'              => 'thin',
            'can_evidence_backfill' => true,
        ]]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_EVIDENCE_BACKFILL,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_ac3_can_proof_replay_chooses_proof_replay(): void
    {
        $result = $this->scheduler->schedule([[
            'organ_id'       => 'AtlasStaleOrgan',
            'gap_type'       => 'stale',
            'can_proof_replay' => true,
        ]]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_PROOF_REPLAY,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_ac3_can_consolidate_chooses_consolidation(): void
    {
        $result = $this->scheduler->schedule([[
            'organ_id'        => 'AtlasDupOrgan',
            'gap_type'        => 'missing',
            'can_consolidate' => true,
        ]]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_CONSOLIDATION,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_ac3_can_doc_sync_chooses_doc_sync(): void
    {
        $result = $this->scheduler->schedule([[
            'organ_id'    => 'AtlasDocOrgan',
            'gap_type'    => 'doc_drift',
            'can_doc_sync' => true,
        ]]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_DOC_SYNC,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_ac3_can_integration_wiring_chooses_integration_wiring(): void
    {
        $result = $this->scheduler->schedule([[
            'organ_id'               => 'AtlasIntOrgan',
            'gap_type'               => 'integration_debt',
            'can_integration_wiring' => true,
        ]]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_INTEGRATION_WIRING,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_ac3_no_cheap_flag_on_missing_gap_falls_back_to_new_feature_work(): void
    {
        $result = $this->scheduler->schedule([[
            'organ_id' => 'AtlasMissingOrgan',
            'gap_type' => 'missing',
        ]]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_NEW_FEATURE_WORK,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    // ── AC4: output includes total_gaps and gaps_closeable_without_new_feature_work

    public function test_ac4_output_has_required_fields(): void
    {
        $result = $this->scheduler->schedule([
            ['organ_id' => 'A', 'gap_type' => 'missing', 'can_evidence_backfill' => true],
            ['organ_id' => 'B', 'gap_type' => 'thin'],
        ]);

        $this->assertArrayHasKey('total_gaps', $result);
        $this->assertArrayHasKey('gaps_closeable_without_new_feature_work', $result);
        $this->assertSame(2, $result['total_gaps']);
        $this->assertSame(1, $result['gaps_closeable_without_new_feature_work']);
    }

    public function test_ac4_all_cheap_gaps_counted_as_closeable_without_new_feature_work(): void
    {
        $result = $this->scheduler->schedule([
            ['organ_id' => 'A', 'gap_type' => 'thin',  'can_evidence_backfill' => true],
            ['organ_id' => 'B', 'gap_type' => 'stale', 'can_proof_replay' => true],
            ['organ_id' => 'C', 'gap_type' => 'missing'],
        ]);

        $this->assertSame(3, $result['total_gaps']);
        $this->assertSame(2, $result['gaps_closeable_without_new_feature_work']);
    }

    public function test_ac4_empty_input_returns_zero_counts(): void
    {
        $result = $this->scheduler->schedule([]);

        $this->assertSame(0, $result['total_gaps']);
        $this->assertSame(0, $result['gaps_closeable_without_new_feature_work']);
        $this->assertSame([], $result['burn_down_schedule']);
    }
}
