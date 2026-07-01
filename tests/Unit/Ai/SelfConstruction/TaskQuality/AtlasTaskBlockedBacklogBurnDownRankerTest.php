<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedBacklogBurnDownRanker;
use PHPUnit\Framework\TestCase;

final class AtlasTaskBlockedBacklogBurnDownRankerTest extends TestCase
{
    private function ranker(): AtlasTaskBlockedBacklogBurnDownRanker
    {
        return new AtlasTaskBlockedBacklogBurnDownRanker;
    }

    private function family(string $name, array $overrides = []): array
    {
        return array_merge([
            'family' => $name,
            'packet_count' => 5,
            'give_back_total' => 1,
            'recovered_field_confidence' => 'high',
            'can_submit_replacement' => true,
            'target_criticality' => 'low',
            'implementation_risk' => 'low',
        ], $overrides);
    }

    // ── AC: output shape ───────────────────────────────────────────────────────

    public function test_ranked_action_has_all_required_fields(): void
    {
        $r = $this->ranker()->rank([$this->family('missing_acceptance')]);
        $item = $r['ranked_actions'][0];

        foreach (['action', 'packet_count', 'expected_unblocked', 'waste_reduction_score', 'risk', 'reason_codes'] as $key) {
            $this->assertArrayHasKey($key, $item, "Missing key: {$key}");
        }
    }

    // ── AC: high-confidence can_submit ranks above manual-review unknowns ────

    public function test_high_confidence_replacement_outranks_manual_review_unknown(): void
    {
        $r = $this->ranker()->rank([
            $this->family('unknown_low_conf', [
                'recovered_field_confidence' => 'low',
                'can_submit_replacement' => false,
                'give_back_total' => 0,
            ]),
            $this->family('missing_acceptance', [
                'recovered_field_confidence' => 'high',
                'can_submit_replacement' => true,
            ]),
        ]);

        $this->assertSame(AtlasTaskBlockedBacklogBurnDownRanker::ACTION_RESPEC_AND_RESUBMIT, $r['ranked_actions'][0]['action']);
        $this->assertSame('missing_acceptance', $r['ranked_actions'][0]['family']);
        $this->assertSame(AtlasTaskBlockedBacklogBurnDownRanker::ACTION_MANUAL_REVIEW, $r['ranked_actions'][1]['action']);
    }

    public function test_medium_confidence_replacement_also_qualifies_for_respec(): void
    {
        $r = $this->ranker()->rank([
            $this->family('medium-conf', ['recovered_field_confidence' => 'medium', 'can_submit_replacement' => true]),
        ]);
        $this->assertSame(AtlasTaskBlockedBacklogBurnDownRanker::ACTION_RESPEC_AND_RESUBMIT, $r['ranked_actions'][0]['action']);
    }

    public function test_low_confidence_disqualifies_respec_even_with_replacement(): void
    {
        $r = $this->ranker()->rank([
            $this->family('low-conf', ['recovered_field_confidence' => 'low', 'can_submit_replacement' => true, 'give_back_total' => 0]),
        ]);
        $this->assertNotSame(AtlasTaskBlockedBacklogBurnDownRanker::ACTION_RESPEC_AND_RESUBMIT, $r['ranked_actions'][0]['action']);
    }

    // ── AC: repeated-give-back poison outranks cosmetic low-impact repairs ───

    public function test_repeated_give_back_poison_outranks_low_impact_manual_review(): void
    {
        $r = $this->ranker()->rank([
            $this->family('cosmetic-low-impact', [
                'can_submit_replacement' => false,
                'recovered_field_confidence' => 'low',
                'give_back_total' => 1,
            ]),
            $this->family('poison-family', [
                'can_submit_replacement' => false,
                'recovered_field_confidence' => 'low',
                'give_back_total' => 12,
            ]),
        ]);

        $this->assertSame(AtlasTaskBlockedBacklogBurnDownRanker::ACTION_RETIRE, $r['ranked_actions'][0]['action']);
        $this->assertSame('poison-family', $r['ranked_actions'][0]['family']);
    }

    public function test_retire_action_assigned_for_high_give_back_without_replacement(): void
    {
        $r = $this->ranker()->rank([
            $this->family('poison', ['can_submit_replacement' => false, 'give_back_total' => 10]),
        ]);
        $this->assertSame(AtlasTaskBlockedBacklogBurnDownRanker::ACTION_RETIRE, $r['ranked_actions'][0]['action']);
        $this->assertContains('repeated_give_back_waste', $r['ranked_actions'][0]['reason_codes']);
    }

    public function test_retiring_high_criticality_target_is_flagged_high_risk(): void
    {
        $r = $this->ranker()->rank([
            $this->family('critical-poison', [
                'can_submit_replacement' => false,
                'give_back_total' => 10,
                'target_criticality' => 'high',
                'implementation_risk' => 'low',
            ]),
        ]);

        $this->assertSame('high', $r['ranked_actions'][0]['risk']);
    }

    // ── expected_unblocked / waste_reduction_score ────────────────────────────

    public function test_respec_expected_unblocked_equals_packet_count(): void
    {
        $r = $this->ranker()->rank([$this->family('a', ['packet_count' => 9])]);
        $this->assertSame(9, $r['ranked_actions'][0]['expected_unblocked']);
    }

    public function test_retire_expected_unblocked_is_zero(): void
    {
        $r = $this->ranker()->rank([$this->family('a', ['can_submit_replacement' => false, 'give_back_total' => 10])]);
        $this->assertSame(0, $r['ranked_actions'][0]['expected_unblocked']);
    }

    public function test_waste_reduction_score_reflects_give_back_total(): void
    {
        $r = $this->ranker()->rank([$this->family('a', ['give_back_total' => 7])]);
        $this->assertSame(7, $r['ranked_actions'][0]['waste_reduction_score']);
    }

    // ── determinism + purity ──────────────────────────────────────────────────

    public function test_rank_is_deterministic(): void
    {
        $families = [$this->family('a'), $this->family('b', ['can_submit_replacement' => false, 'give_back_total' => 10])];
        $x = $this->ranker()->rank($families);
        $y = $this->ranker()->rank($families);

        $this->assertSame(json_encode($x), json_encode($y));
    }

    public function test_ranker_source_has_no_io_calls(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../../../app/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskBlockedBacklogBurnDownRanker.php');
        foreach (['DB::', 'Http::', 'file_put_contents', 'exec(', 'shell_exec', 'Process::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "ranker must not call {$forbidden}");
        }
    }

    public function test_empty_families_returns_empty_ranked_actions(): void
    {
        $r = $this->ranker()->rank([]);
        $this->assertSame([], $r['ranked_actions']);
    }

    // ── worker_feed_opportunity ──────────────────────────────────────────────

    public function test_high_confidence_respec_family_includes_worker_feed_opportunity_when_worker_floor_low(): void
    {
        $r = $this->ranker()->rank([
            $this->family('alpha', ['can_submit_replacement' => true, 'recovered_field_confidence' => 'high']),
        ], ['worker_floor_low' => true]);

        $this->assertSame('respec_and_resubmit', $r['ranked_actions'][0]['action']);
        $this->assertContains('worker_feed_opportunity', $r['ranked_actions'][0]['reason_codes']);
    }

    public function test_respec_family_omits_worker_feed_opportunity_when_worker_floor_not_low(): void
    {
        $r = $this->ranker()->rank([
            $this->family('alpha', ['can_submit_replacement' => true, 'recovered_field_confidence' => 'high']),
        ]);

        $this->assertNotContains('worker_feed_opportunity', $r['ranked_actions'][0]['reason_codes']);
    }

    public function test_repeated_give_back_poison_still_retired_when_no_replacement_even_with_low_worker_floor(): void
    {
        $r = $this->ranker()->rank([
            $this->family('poison_family', [
                'can_submit_replacement' => false,
                'give_back_total' => 6,
            ]),
        ], ['worker_floor_low' => true]);

        $this->assertSame('retire', $r['ranked_actions'][0]['action']);
        $this->assertNotContains('worker_feed_opportunity', $r['ranked_actions'][0]['reason_codes']);
    }

    // ── AC2/AC3: leverage and avoided_token_waste scoring ─────────────────────

    public function test_low_count_high_leverage_family_outranks_high_count_low_value_family(): void
    {
        $r = $this->ranker()->rank([
            $this->family('high-count-low-value', ['packet_count' => 20]),
            $this->family('low-count-high-leverage', ['packet_count' => 2, 'leverage' => 10.0]),
        ]);

        $this->assertSame('low-count-high-leverage', $r['ranked_actions'][0]['family']);
        $this->assertSame('high-count-low-value', $r['ranked_actions'][1]['family']);
    }

    public function test_zero_leverage_preserves_existing_ranking_by_expected_unblocked(): void
    {
        $r = $this->ranker()->rank([
            $this->family('a', ['packet_count' => 3]),
            $this->family('b', ['packet_count' => 9]),
        ]);

        $this->assertSame('b', $r['ranked_actions'][0]['family']);
        $this->assertSame('a', $r['ranked_actions'][1]['family']);
    }

    public function test_avoided_token_waste_reflects_give_back_total(): void
    {
        $r = $this->ranker()->rank([$this->family('a', ['give_back_total' => 4])]);
        $this->assertGreaterThan(0.0, $r['ranked_actions'][0]['avoided_token_waste']);
    }

    public function test_recovered_claimable_value_matches_expected_unblocked(): void
    {
        $r = $this->ranker()->rank([$this->family('a', ['packet_count' => 6])]);
        $this->assertSame($r['ranked_actions'][0]['expected_unblocked'], $r['ranked_actions'][0]['recovered_claimable_value']);
    }

    public function test_unrepairable_family_gets_retire_regardless_of_leverage(): void
    {
        $r = $this->ranker()->rank([
            $this->family('unrepairable', ['can_submit_replacement' => false, 'give_back_total' => 10, 'leverage' => 8.0]),
        ]);

        $this->assertSame(AtlasTaskBlockedBacklogBurnDownRanker::ACTION_RETIRE, $r['ranked_actions'][0]['action']);
    }

    public function test_deterministic_tie_breaking_with_leverage(): void
    {
        $families = [
            $this->family('high-count-low-value', ['packet_count' => 20]),
            $this->family('low-count-high-leverage', ['packet_count' => 2, 'leverage' => 10.0]),
        ];

        $x = $this->ranker()->rank($families);
        $y = $this->ranker()->rank($families);

        $this->assertSame(json_encode($x), json_encode($y));
    }

    // ── worker_waste_pressure / downstream_unlock_score ───────────────────────

    public function test_ranked_action_has_worker_waste_pressure_and_downstream_unlock_score(): void
    {
        $r = $this->ranker()->rank([$this->family('a')]);
        $item = $r['ranked_actions'][0];

        $this->assertArrayHasKey('worker_waste_pressure', $item);
        $this->assertArrayHasKey('downstream_unlock_score', $item);
        $this->assertSame(0.0, $item['worker_waste_pressure']);
        $this->assertSame(0.0, $item['downstream_unlock_score']);
    }

    public function test_small_family_with_high_worker_waste_pressure_outranks_larger_low_leverage_family(): void
    {
        $r = $this->ranker()->rank([
            $this->family('high-count-low-value', ['packet_count' => 20]),
            $this->family('small-worker-waste', [
                'packet_count' => 2,
                'active_workers_blocked' => 4,
                're_serve_rate' => 3.0,
            ]),
        ]);

        $this->assertSame('small-worker-waste', $r['ranked_actions'][0]['family']);
        $this->assertGreaterThan(0.0, $r['ranked_actions'][0]['worker_waste_pressure']);
    }

    public function test_small_family_with_high_downstream_unlocks_outranks_larger_low_leverage_family(): void
    {
        $r = $this->ranker()->rank([
            $this->family('high-count-low-value', ['packet_count' => 20]),
            $this->family('small-downstream-unlock', [
                'packet_count' => 2,
                'downstream_unlocks' => 15,
            ]),
        ]);

        $this->assertSame('small-downstream-unlock', $r['ranked_actions'][0]['family']);
        $this->assertGreaterThan(0.0, $r['ranked_actions'][0]['downstream_unlock_score']);
    }

    public function test_repeated_re_serve_rate_increases_worker_waste_pressure(): void
    {
        $low = $this->ranker()->rank([
            $this->family('a', ['active_workers_blocked' => 2, 're_serve_rate' => 0.0]),
        ]);
        $high = $this->ranker()->rank([
            $this->family('a', ['active_workers_blocked' => 2, 're_serve_rate' => 5.0]),
        ]);

        $this->assertGreaterThan(
            $low['ranked_actions'][0]['worker_waste_pressure'],
            $high['ranked_actions'][0]['worker_waste_pressure'],
        );
    }
}
