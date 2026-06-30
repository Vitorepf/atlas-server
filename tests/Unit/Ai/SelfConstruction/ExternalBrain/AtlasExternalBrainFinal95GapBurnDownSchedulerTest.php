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

    // ── AC2: new gap types sorted after the original four ─────────────────────

    public function test_integration_debt_priority_is_after_stale(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'a', 'gap_type' => 'integration_debt'],
            ['organ_id' => 'b', 'gap_type' => 'stale'],
        ]);

        $ids = array_column($result['burn_down_schedule'], 'organ_id');
        $this->assertSame('b', $ids[0]); // stale=4 before integration_debt=5
    }

    public function test_doc_drift_priority_is_after_integration_debt(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'a', 'gap_type' => 'doc_drift'],
            ['organ_id' => 'b', 'gap_type' => 'integration_debt'],
        ]);

        $ids = array_column($result['burn_down_schedule'], 'organ_id');
        $this->assertSame('b', $ids[0]);
    }

    public function test_weak_outcome_learning_is_last_priority(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'a', 'gap_type' => 'weak_outcome_learning'],
            ['organ_id' => 'b', 'gap_type' => 'doc_drift'],
        ]);

        $ids = array_column($result['burn_down_schedule'], 'organ_id');
        $this->assertSame('b', $ids[0]);
    }

    // ── AC3: doc_sync approach ────────────────────────────────────────────────

    public function test_doc_sync_chosen_when_can_doc_sync_true(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'doc_drift', 'can_doc_sync' => true],
        ]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_DOC_SYNC,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_doc_drift_defaults_to_doc_sync_without_flag(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'doc_drift'],
        ]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_DOC_SYNC,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_doc_sync_stop_condition(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'doc_drift', 'can_doc_sync' => true],
        ]);

        $this->assertSame('doc_drift_eliminated', $result['burn_down_schedule'][0]['stop_condition']);
    }

    // ── AC3: integration_wiring approach ──────────────────────────────────────

    public function test_integration_wiring_chosen_when_can_integration_wiring_true(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'thin', 'can_integration_wiring' => true],
        ]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_INTEGRATION_WIRING,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_integration_debt_defaults_to_integration_wiring_without_flag(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'integration_debt'],
        ]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_INTEGRATION_WIRING,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_integration_wiring_stop_condition(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'integration_debt'],
        ]);

        $this->assertSame('integration_points_fully_wired', $result['burn_down_schedule'][0]['stop_condition']);
    }

    public function test_weak_outcome_learning_defaults_to_evidence_backfill(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'x', 'gap_type' => 'weak_outcome_learning'],
        ]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_EVIDENCE_BACKFILL,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    // ── AC3: approach preference order includes doc_sync before integration_wiring ──

    public function test_doc_sync_preferred_over_integration_wiring(): void
    {
        $result = $this->scheduler()->schedule([[
            'organ_id'              => 'x',
            'gap_type'              => 'integration_debt',
            'can_doc_sync'          => true,
            'can_integration_wiring' => true,
        ]]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_DOC_SYNC,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    public function test_integration_wiring_preferred_over_unblock(): void
    {
        $result = $this->scheduler()->schedule([[
            'organ_id'              => 'x',
            'gap_type'              => 'blocked',
            'can_integration_wiring' => true,
        ]]);

        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_INTEGRATION_WIRING,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    // ── AC3 named scenarios: blocked / missing-evidence / replay / consolidation / stale / new-feature-last ──

    public function test_scenario_blocked_unblocks_dependency_and_records_blocker(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'blocker-organ', 'gap_type' => 'blocked', 'blocker' => 'dep-organ-77'],
        ]);

        $entry = $result['burn_down_schedule'][0];
        $this->assertSame(AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_UNBLOCK_DEPENDENCY, $entry['resolution_approach']);
        $this->assertSame('dep-organ-77', $entry['blocker']);
        $this->assertSame('blocker_resolved', $entry['stop_condition']);
        $this->assertStringContainsString('blocker-organ', $entry['cheapest_next_proof']);
    }

    public function test_scenario_missing_evidence_routes_to_backfill_when_available(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'missing-organ', 'gap_type' => 'missing', 'can_evidence_backfill' => true],
        ]);

        $entry = $result['burn_down_schedule'][0];
        $this->assertSame(AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_EVIDENCE_BACKFILL, $entry['resolution_approach']);
        $this->assertSame('first_green_evidence_captured', $entry['stop_condition']);
    }

    public function test_scenario_stale_proof_replayed_when_can_replay(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'stale-organ', 'gap_type' => 'stale', 'can_proof_replay' => true],
        ]);

        $entry = $result['burn_down_schedule'][0];
        $this->assertSame(AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_PROOF_REPLAY, $entry['resolution_approach']);
        $this->assertSame('existing_proof_returns_green', $entry['stop_condition']);
    }

    public function test_scenario_consolidation_for_thin_gap(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'thin-organ', 'gap_type' => 'thin', 'can_consolidate' => true],
        ]);

        $entry = $result['burn_down_schedule'][0];
        $this->assertSame(AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_CONSOLIDATION, $entry['resolution_approach']);
        $this->assertSame('duplicate_count_reduced_to_one', $entry['stop_condition']);
    }

    /** AC2: new_feature_work only appears when every cheaper route is unavailable. */
    public function test_scenario_new_feature_work_is_absolute_last_resort(): void
    {
        // With all cheap flags ON, new_feature_work must NOT be chosen.
        $result = $this->scheduler()->schedule([[
            'organ_id'               => 'x',
            'gap_type'               => 'missing',
            'can_evidence_backfill'  => true,
            'can_proof_replay'       => true,
            'can_consolidate'        => true,
            'can_doc_sync'           => true,
            'can_integration_wiring' => true,
        ]]);
        $this->assertNotSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_NEW_FEATURE_WORK,
            $result['burn_down_schedule'][0]['resolution_approach'],
        );

        // With no cheap flags, new_feature_work IS chosen.
        $result2 = $this->scheduler()->schedule([
            ['organ_id' => 'y', 'gap_type' => 'missing'],
        ]);
        $this->assertSame(
            AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_NEW_FEATURE_WORK,
            $result2['burn_down_schedule'][0]['resolution_approach'],
        );
    }

    /** AC2 + AC3: preference order strictly holds across all approach tiers. */
    public function test_approach_preference_order_evidence_backfill_first(): void
    {
        // backfill beats everything else.
        foreach ([
            'can_proof_replay'       => true,
            'can_consolidate'        => true,
            'can_doc_sync'           => true,
            'can_integration_wiring' => true,
        ] as $otherFlag => $_) {
            $result = $this->scheduler()->schedule([[
                'organ_id'              => 'x',
                'gap_type'              => 'missing',
                'can_evidence_backfill' => true,
                $otherFlag              => true,
            ]]);
            $this->assertSame(
                AtlasExternalBrainFinal95GapBurnDownScheduler::APPROACH_EVIDENCE_BACKFILL,
                $result['burn_down_schedule'][0]['resolution_approach'],
                "evidence_backfill must win over {$otherFlag}",
            );
        }
    }

    // ── Dependency-chain ordering ───────────────────────────────────────────

    public function test_blocked_upstream_gap_ranks_before_higher_impact_downstream_gap(): void
    {
        $result = $this->scheduler()->schedule([
            [
                'organ_id'     => 'downstream-high-impact',
                'gap_type'     => 'missing',
                'depends_on'   => ['upstream-blocker'],
                'impact_score' => 0.95,
                'effort_score' => 0.1,
            ],
            [
                'organ_id'     => 'upstream-blocker',
                'gap_type'     => 'thin',
                'impact_score' => 0.10,
                'effort_score' => 0.5,
            ],
        ]);

        $organsInOrder = array_column($result['burn_down_schedule'], 'organ_id');
        $upstreamRank   = array_search('upstream-blocker', $organsInOrder, true);
        $downstreamRank = array_search('downstream-high-impact', $organsInOrder, true);

        $this->assertNotFalse($upstreamRank);
        $this->assertNotFalse($downstreamRank);
        $this->assertLessThan($downstreamRank, $upstreamRank, 'upstream blocker must rank before its higher-impact downstream dependent');
    }

    public function test_unlocks_field_also_forces_dependency_ordering(): void
    {
        $result = $this->scheduler()->schedule([
            [
                'organ_id'     => 'downstream',
                'gap_type'     => 'missing',
                'impact_score' => 0.99,
            ],
            [
                'organ_id'     => 'upstream',
                'gap_type'     => 'missing',
                'unlocks'      => ['downstream'],
                'impact_score' => 0.01,
            ],
        ]);

        $organsInOrder = array_column($result['burn_down_schedule'], 'organ_id');
        $this->assertSame(['upstream', 'downstream'], $organsInOrder);
    }

    public function test_dependency_chains_lists_entries_with_edges_only(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'a', 'gap_type' => 'missing', 'depends_on' => ['b']],
            ['organ_id' => 'b', 'gap_type' => 'missing'],
            ['organ_id' => 'c', 'gap_type' => 'missing'],
        ]);

        $this->assertArrayHasKey('dependency_chains', $result);
        $chainOrganIds = array_column($result['dependency_chains'], 'organ_id');
        $this->assertContains('a', $chainOrganIds);
        $this->assertNotContains('c', $chainOrganIds);
    }

    public function test_schedule_entries_carry_new_fields(): void
    {
        $result = $this->scheduler()->schedule([[
            'organ_id'           => 'a',
            'gap_type'           => 'missing',
            'depends_on'         => ['b'],
            'unlocks'            => ['c'],
            'impact_score'       => 0.7,
            'effort_score'       => 0.3,
            'evidence_age_hours' => 12.0,
        ]]);

        $entry = $result['burn_down_schedule'][0];
        foreach (['depends_on', 'unlocks', 'impact_score', 'effort_score', 'evidence_age_hours'] as $key) {
            $this->assertArrayHasKey($key, $entry);
        }
        $this->assertSame(['b'], $entry['depends_on']);
        $this->assertSame(['c'], $entry['unlocks']);
        $this->assertSame(0.7, $entry['impact_score']);
        $this->assertSame(0.3, $entry['effort_score']);
        $this->assertSame(12.0, $entry['evidence_age_hours']);
    }

    public function test_next_batch_recommendation_describes_top_ready_gap(): void
    {
        $result = $this->scheduler()->schedule([
            ['organ_id' => 'a', 'gap_type' => 'blocked', 'can_evidence_backfill' => true],
        ]);

        $this->assertArrayHasKey('next_batch_recommendation', $result);
        $this->assertStringContainsString('a', $result['next_batch_recommendation']);
    }

    public function test_next_batch_recommendation_with_no_gaps(): void
    {
        $result = $this->scheduler()->schedule([]);

        $this->assertSame('no_gaps_to_schedule', $result['next_batch_recommendation']);
    }
}
