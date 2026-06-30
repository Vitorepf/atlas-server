<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLeverageScorer;
use Tests\TestCase;

final class AtlasExternalBrainLeverageScorerTest extends TestCase
{
    private function scorer(): AtlasExternalBrainLeverageScorer
    {
        return new AtlasExternalBrainLeverageScorer;
    }

    public function test_schema_constant(): void
    {
        $this->assertSame(
            'atlas.external_brain.leverage_scorer.v1',
            AtlasExternalBrainLeverageScorer::SCHEMA,
        );
    }

    public function test_architecture_unlock_outranks_cheap_wrapper(): void
    {
        $scorer = $this->scorer();

        $architectureUnlock = [
            'label'                   => 'architecture_unlock',
            'capability_unlock'       => 0.9,
            'dependency_unblock'      => 0.9,
            'implementation_evidence' => 0.8,
            'repeated_pain'           => 0.7,
            'blast_radius_safety'     => 0.7,
        ];

        $cheapWrapper = [
            'label'                   => 'cheap_wrapper',
            'capability_unlock'       => 0.2,
            'dependency_unblock'      => 0.1,
            'implementation_evidence' => 0.6,
            'repeated_pain'           => 0.3,
            'blast_radius_safety'     => 0.95,
            'cosmetic_cli'            => true,
        ];

        $archResult    = $scorer->score($architectureUnlock);
        $wrapperResult = $scorer->score($cheapWrapper);

        $this->assertGreaterThan(
            $wrapperResult['final_score'],
            $archResult['final_score'],
            'Architecture unlock must outrank cheap wrapper by leverage score',
        );
        $this->assertContains('cosmetic_cli', $wrapperResult['triggered_penalties']);
        $this->assertSame([], $archResult['triggered_penalties']);
    }

    public function test_cosmetic_cli_penalty_reduces_score(): void
    {
        $scorer = $this->scorer();
        $base = [
            'capability_unlock'       => 0.8,
            'dependency_unblock'      => 0.8,
            'implementation_evidence' => 0.8,
            'repeated_pain'           => 0.8,
            'blast_radius_safety'     => 0.8,
        ];

        $clean     = $scorer->score($base);
        $penalised = $scorer->score(array_merge($base, ['cosmetic_cli' => true]));

        $this->assertGreaterThan($penalised['final_score'], $clean['final_score']);
        $this->assertSame(0.25, $penalised['penalty']);
    }

    public function test_one_test_microtask_penalty(): void
    {
        $scorer = $this->scorer();
        $result = $scorer->score([
            'capability_unlock'       => 0.5,
            'dependency_unblock'      => 0.5,
            'implementation_evidence' => 0.5,
            'repeated_pain'           => 0.5,
            'blast_radius_safety'     => 0.5,
            'one_test_microtask'      => true,
        ]);

        $this->assertContains('one_test_microtask', $result['triggered_penalties']);
        $this->assertSame(0.20, $result['penalty']);
    }

    public function test_duplicated_target_penalty(): void
    {
        $scorer = $this->scorer();
        $result = $scorer->score(['duplicated_target' => true, 'capability_unlock' => 1.0]);

        $this->assertContains('duplicated_target', $result['triggered_penalties']);
        $this->assertSame(0.30, $result['penalty']);
    }

    public function test_already_satisfied_penalty(): void
    {
        $scorer = $this->scorer();
        $result = $scorer->score(['already_satisfied' => true, 'capability_unlock' => 1.0]);

        $this->assertContains('already_satisfied', $result['triggered_penalties']);
        $this->assertSame(0.35, $result['penalty']);
    }

    public function test_multiple_penalties_accumulate(): void
    {
        $scorer = $this->scorer();
        $result = $scorer->score([
            'capability_unlock' => 1.0,
            'cosmetic_cli'      => true,   // 0.25
            'duplicated_target' => true,   // 0.30
            'already_satisfied' => true,   // 0.35 → total 0.90
        ]);

        $this->assertSame(0.90, $result['penalty']);
        $this->assertCount(3, $result['triggered_penalties']);
    }

    public function test_penalty_capped_at_one_yields_zero_final_score(): void
    {
        $scorer = $this->scorer();
        $result = $scorer->score([
            'capability_unlock'  => 1.0,
            'cosmetic_cli'       => true,  // 0.25
            'one_test_microtask' => true,  // 0.20
            'duplicated_target'  => true,  // 0.30
            'already_satisfied'  => true,  // 0.35 → total 1.10 → capped at 1.0
        ]);

        $this->assertSame(1.0, $result['penalty']);
        $this->assertSame(0.0, $result['final_score']);
    }

    public function test_rank_orders_by_final_score_descending(): void
    {
        $scorer = $this->scorer();
        $ranked = $scorer->rank([
            ['label' => 'low',    'capability_unlock' => 0.1, 'cosmetic_cli' => true],
            ['label' => 'high',   'capability_unlock' => 0.9, 'dependency_unblock' => 0.9],
            ['label' => 'medium', 'capability_unlock' => 0.5],
        ]);

        $this->assertSame('high',   $ranked[0]['label']);
        $this->assertSame('medium', $ranked[1]['label']);
        $this->assertSame('low',    $ranked[2]['label']);
    }

    public function test_zero_input_yields_zero_score(): void
    {
        $result = $this->scorer()->score([]);

        $this->assertSame(0.0, $result['final_score']);
        $this->assertSame(0.0, $result['weighted_sum']);
        $this->assertSame([], $result['triggered_penalties']);
    }

    public function test_dimensions_list_has_five_entries_summing_to_one(): void
    {
        $dims = $this->scorer()->dimensions();

        $this->assertCount(5, $dims);
        $names = array_column($dims, 'dimension');
        $this->assertContains('capability_unlock', $names);
        $this->assertContains('dependency_unblock', $names);
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($dims, 'weight')), 0.001);
    }

    public function test_penalties_list_has_four_entries(): void
    {
        $penalties = $this->scorer()->penalties();

        $this->assertCount(4, $penalties);
        $this->assertContains('cosmetic_cli',       array_column($penalties, 'penalty'));
        $this->assertContains('one_test_microtask', array_column($penalties, 'penalty'));
        $this->assertContains('duplicated_target',  array_column($penalties, 'penalty'));
        $this->assertContains('already_satisfied',  array_column($penalties, 'penalty'));
    }

    public function test_score_output_has_canonical_keys(): void
    {
        $result = $this->scorer()->score(['label' => 'test', 'capability_unlock' => 0.7]);

        $this->assertSame(AtlasExternalBrainLeverageScorer::SCHEMA, $result['schema']);
        $this->assertArrayHasKey('weighted_sum',        $result);
        $this->assertArrayHasKey('penalty',             $result);
        $this->assertArrayHasKey('final_score',         $result);
        $this->assertArrayHasKey('dimension_scores',    $result);
        $this->assertArrayHasKey('triggered_penalties', $result);
    }

    public function test_dimension_scores_are_clamped_to_unit_interval(): void
    {
        $result = $this->scorer()->score([
            'capability_unlock' => 2.5,   // above 1
            'blast_radius_safety' => -0.5, // below 0
        ]);

        $this->assertSame(1.0, $result['dimension_scores']['capability_unlock']);
        $this->assertSame(0.0, $result['dimension_scores']['blast_radius_safety']);
    }

    // ---------- compound_impact receipt ----------

    public function test_score_includes_compound_impact_block(): void
    {
        $result = $this->scorer()->score([
            'label'                    => 'test_opportunity',
            'capability_unlock'        => 0.8,
            'unlocked_capabilities'    => ['auth_recovery', 'lease_repair'],
            'downstream_unblock_count' => 5,
            'risk_reduction_signals'   => ['removes_stale_lease_deadlock'],
            'autonomy_gain_signals'    => ['enables_unattended_recovery'],
        ]);

        $this->assertArrayHasKey('compound_impact', $result);
        $ci = $result['compound_impact'];

        $this->assertSame(['auth_recovery', 'lease_repair'], $ci['unlocked_capabilities']);
        $this->assertSame(5, $ci['downstream_unblock_count']);
        $this->assertSame(['removes_stale_lease_deadlock'], $ci['risk_reduction_signals']);
        $this->assertSame(['enables_unattended_recovery'], $ci['autonomy_gain_signals']);
    }

    public function test_score_compound_impact_defaults_to_empty_when_absent(): void
    {
        $result = $this->scorer()->score(['capability_unlock' => 0.5]);

        $ci = $result['compound_impact'];
        $this->assertSame([], $ci['unlocked_capabilities']);
        $this->assertSame(0, $ci['downstream_unblock_count']);
        $this->assertSame([], $ci['risk_reduction_signals']);
        $this->assertSame([], $ci['autonomy_gain_signals']);
    }

    public function test_score_why_this_beats_next_is_null_without_ranking(): void
    {
        $result = $this->scorer()->score(['capability_unlock' => 0.7]);
        $this->assertNull($result['why_this_beats_next']);
    }

    // ---------- why_this_beats_next in rank ----------

    public function test_rank_fills_why_this_beats_next_for_each_item(): void
    {
        $ranked = $this->scorer()->rank([
            ['label' => 'alpha', 'capability_unlock' => 0.9],
            ['label' => 'beta',  'capability_unlock' => 0.3],
        ]);

        $this->assertNotNull($ranked[0]['why_this_beats_next'], 'winner must have explanation');
        $this->assertStringContainsString('score_advantage', $ranked[0]['why_this_beats_next']);
        $this->assertSame('last_in_ranking', $ranked[1]['why_this_beats_next']);
    }

    public function test_rank_why_mentions_fewer_penalties_when_relevant(): void
    {
        $ranked = $this->scorer()->rank([
            ['label' => 'clean',   'capability_unlock' => 0.6],
            ['label' => 'penalised', 'capability_unlock' => 0.6, 'cosmetic_cli' => true],
        ]);

        $this->assertSame('clean', $ranked[0]['label']);
        $this->assertStringContainsString('fewer_penalties', $ranked[0]['why_this_beats_next']);
    }

    public function test_rank_why_mentions_downstream_unblocks_when_relevant(): void
    {
        $ranked = $this->scorer()->rank([
            ['label' => 'high_unblock', 'capability_unlock' => 0.6, 'downstream_unblock_count' => 8],
            ['label' => 'low_unblock',  'capability_unlock' => 0.6, 'downstream_unblock_count' => 1],
        ]);

        $this->assertSame('high_unblock', $ranked[0]['label']);
        $why = $ranked[0]['why_this_beats_next'];
        $this->assertStringContainsString('more_downstream_unblocks', $why);
        $this->assertStringContainsString('8_vs_1', $why);
    }

    // ---------- acceptance criteria: structural unblocker beats cosmetic task ----------

    public function test_structural_unblocker_beats_high_ease_cosmetic_task(): void
    {
        $scorer = $this->scorer();

        // High-ease cosmetic task: low friction but no real compound impact.
        $cosmeticTask = [
            'label'                    => 'cosmetic_cli_wrapper',
            'capability_unlock'        => 0.3,
            'dependency_unblock'       => 0.1,
            'implementation_evidence'  => 0.9,   // easy to implement
            'repeated_pain'            => 0.2,
            'blast_radius_safety'      => 0.95,  // very safe (tiny scope)
            'cosmetic_cli'             => true,
            'downstream_unblock_count' => 0,
            'unlocked_capabilities'    => [],
            'risk_reduction_signals'   => [],
            'autonomy_gain_signals'    => [],
        ];

        // Harder structural unblocker: more effort but real compound impact.
        $structuralUnblocker = [
            'label'                    => 'structural_arch_unlock',
            'capability_unlock'        => 0.9,
            'dependency_unblock'       => 0.85,
            'implementation_evidence'  => 0.6,   // harder, less obvious
            'repeated_pain'            => 0.8,
            'blast_radius_safety'      => 0.5,
            'downstream_unblock_count' => 7,
            'unlocked_capabilities'    => ['lease_recovery', 'acp_health', 'auto_merge'],
            'risk_reduction_signals'   => ['removes_zombie_lease', 'closes_stale_index'],
            'autonomy_gain_signals'    => ['enables_unattended_24h', 'closes_human_gating'],
        ];

        $ranked = $scorer->rank([$cosmeticTask, $structuralUnblocker]);

        $this->assertSame('structural_arch_unlock', $ranked[0]['label'],
            'Structural unblocker must outrank high-ease cosmetic task');

        $why = $ranked[0]['why_this_beats_next'];
        $this->assertNotEmpty($why);
        $this->assertStringContainsString('score_advantage', $why);
        // Compound impact signals must appear in the explanation
        $this->assertMatchesRegularExpression(
            '/(more_downstream_unblocks|more_unlocked_capabilities|more_risk_reduction_signals|more_autonomy_gain_signals)/',
            $why,
        );
    }
}
