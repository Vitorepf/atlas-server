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
}
