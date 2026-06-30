<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLeverageScorer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLeverageScorerTest extends TestCase
{
    private AtlasExternalBrainLeverageScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new AtlasExternalBrainLeverageScorer;
    }

    private function opp(array $overrides = []): array
    {
        return array_merge([
            'label'                    => 'default-opp',
            'capability_unlock'        => 0.5,
            'dependency_unblock'       => 0.5,
            'implementation_evidence'  => 0.5,
            'repeated_pain'            => 0.5,
            'blast_radius_safety'      => 0.5,
        ], $overrides);
    }

    // ── AC1: compound impact, unblock value, autonomy lift raise score ─────────

    public function test_high_capability_unlock_raises_weighted_sum(): void
    {
        $low  = $this->scorer->score($this->opp(['capability_unlock' => 0.0]));
        $high = $this->scorer->score($this->opp(['capability_unlock' => 1.0]));

        $this->assertGreaterThan($low['weighted_sum'], $high['weighted_sum']);
    }

    public function test_downstream_unblock_count_reflected_in_compound_impact(): void
    {
        $r = $this->scorer->score($this->opp(['downstream_unblock_count' => 5]));

        $this->assertSame(5, $r['compound_impact']['downstream_unblock_count']);
    }

    public function test_autonomy_gain_signals_included_in_compound_impact(): void
    {
        $r = $this->scorer->score($this->opp([
            'autonomy_gain_signals' => ['sustains_origination', 'reduces_human_seeding'],
        ]));

        $this->assertCount(2, $r['compound_impact']['autonomy_gain_signals']);
    }

    public function test_high_dependency_unblock_dimension_raises_score(): void
    {
        $low  = $this->scorer->score($this->opp(['dependency_unblock' => 0.0]));
        $high = $this->scorer->score($this->opp(['dependency_unblock' => 1.0]));

        $this->assertGreaterThan($low['final_score'], $high['final_score']);
    }

    public function test_unlocked_capabilities_included_in_compound_impact(): void
    {
        $r = $this->scorer->score($this->opp([
            'unlocked_capabilities' => ['loop_self_repair', 'evidence_driven_origination'],
        ]));

        $this->assertCount(2, $r['compound_impact']['unlocked_capabilities']);
    }

    // ── AC2: proxy risk, dup target, low evidence, blast-radius reduce score ───

    public function test_cosmetic_cli_flag_reduces_final_score(): void
    {
        $clean   = $this->scorer->score($this->opp());
        $penalty = $this->scorer->score($this->opp(['cosmetic_cli' => true]));

        $this->assertLessThan($clean['final_score'], $penalty['final_score']);
        $this->assertContains('cosmetic_cli', $penalty['triggered_penalties']);
    }

    public function test_duplicated_target_flag_reduces_final_score(): void
    {
        $clean   = $this->scorer->score($this->opp());
        $penalty = $this->scorer->score($this->opp(['duplicated_target' => true]));

        $this->assertLessThan($clean['final_score'], $penalty['final_score']);
        $this->assertContains('duplicated_target', $penalty['triggered_penalties']);
    }

    public function test_low_implementation_evidence_reduces_weighted_sum(): void
    {
        $weak   = $this->scorer->score($this->opp(['implementation_evidence' => 0.0]));
        $strong = $this->scorer->score($this->opp(['implementation_evidence' => 1.0]));

        $this->assertGreaterThan($weak['weighted_sum'], $strong['weighted_sum']);
    }

    public function test_low_blast_radius_safety_reduces_score(): void
    {
        $safe   = $this->scorer->score($this->opp(['blast_radius_safety' => 1.0]));
        $unsafe = $this->scorer->score($this->opp(['blast_radius_safety' => 0.0]));

        $this->assertGreaterThan($unsafe['final_score'], $safe['final_score']);
    }

    public function test_multiple_penalties_stack_and_reduce_score_further(): void
    {
        $one  = $this->scorer->score($this->opp(['cosmetic_cli' => true]));
        $two  = $this->scorer->score($this->opp(['cosmetic_cli' => true, 'duplicated_target' => true]));

        $this->assertLessThan($one['final_score'], $two['final_score']);
    }

    // ── AC3: rank orders by final_score, not raw ambition wording ────────────

    public function test_rank_places_high_evidence_before_low_evidence(): void
    {
        $ranked = $this->scorer->rank([
            $this->opp(['label' => 'weak', 'implementation_evidence' => 0.0, 'capability_unlock' => 0.0]),
            $this->opp(['label' => 'strong', 'implementation_evidence' => 1.0, 'capability_unlock' => 1.0]),
        ]);

        $this->assertSame('strong', $ranked[0]['label']);
        $this->assertSame('weak',   $ranked[1]['label']);
    }

    public function test_rank_penalised_entry_falls_behind_clean_entry(): void
    {
        $ranked = $this->scorer->rank([
            $this->opp(['label' => 'proxy-only', 'cosmetic_cli' => true, 'already_satisfied' => true]),
            $this->opp(['label' => 'real-work']),
        ]);

        $this->assertSame('real-work',   $ranked[0]['label']);
        $this->assertSame('proxy-only',  $ranked[1]['label']);
    }

    public function test_rank_annotates_why_this_beats_next(): void
    {
        $ranked = $this->scorer->rank([
            $this->opp(['label' => 'a', 'capability_unlock' => 1.0]),
            $this->opp(['label' => 'b', 'capability_unlock' => 0.0]),
        ]);

        $this->assertStringContainsString('score_advantage:', $ranked[0]['why_this_beats_next']);
        $this->assertSame('last_in_ranking', $ranked[1]['why_this_beats_next']);
    }

    // ── AC4: deterministic, exposes dimensions and penalties ──────────────────

    public function test_score_is_deterministic(): void
    {
        $opp = $this->opp(['label' => 'fixed', 'capability_unlock' => 0.7]);

        $this->assertSame(json_encode($this->scorer->score($opp)), json_encode($this->scorer->score($opp)));
    }

    public function test_dimensions_exposes_all_weighted_dimensions(): void
    {
        $dims = array_column($this->scorer->dimensions(), 'dimension');

        $this->assertContains('capability_unlock',       $dims);
        $this->assertContains('dependency_unblock',      $dims);
        $this->assertContains('implementation_evidence', $dims);
    }

    public function test_penalties_exposes_all_penalty_factors(): void
    {
        $penalties = array_column($this->scorer->penalties(), 'penalty');

        $this->assertContains('cosmetic_cli',      $penalties);
        $this->assertContains('duplicated_target', $penalties);
        $this->assertContains('already_satisfied', $penalties);
    }
}
