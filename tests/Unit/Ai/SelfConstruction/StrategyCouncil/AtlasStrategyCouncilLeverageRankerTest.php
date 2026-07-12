<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilLeverageRanker;
use Tests\TestCase;

final class AtlasStrategyCouncilLeverageRankerTest extends TestCase
{
    private function candidate(array $overrides = []): array
    {
        return $overrides + [
            'candidate_id' => 'c-1',
            'organ' => 'cortex',
            'capability_gap' => 1,
            'user_impact' => 1,
            'autonomy_unlock' => 1,
            'waste_reduction' => 1,
            'risk' => 1,
            'evidence_refs' => ['receipt:r1'],
            'dependency_count' => 1,
        ];
    }

    public function test_higher_autonomy_unlock_ranks_first(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'lo', 'autonomy_unlock' => 1]),
            $this->candidate(['candidate_id' => 'hi', 'autonomy_unlock' => 9]),
        ]);

        $this->assertSame(['hi', 'lo'], array_column($verdict['ranked'], 'candidate_id'));
    }

    public function test_higher_capability_gap_ranks_before_lower_when_autonomy_ties(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'lo', 'capability_gap' => 1]),
            $this->candidate(['candidate_id' => 'hi', 'capability_gap' => 9]),
        ]);

        $this->assertSame('hi', $verdict['ranked'][0]['candidate_id']);
    }

    public function test_higher_user_impact_ranks_before_lower_when_autonomy_and_capability_gap_tie(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'lo', 'user_impact' => 1, 'capability_gap' => 5]),
            $this->candidate(['candidate_id' => 'hi', 'user_impact' => 9, 'capability_gap' => 5]),
        ]);

        $this->assertSame('hi', $verdict['ranked'][0]['candidate_id']);
    }

    public function test_reasons_include_capability_gap_and_user_impact(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['capability_gap' => 7, 'user_impact' => 4]),
        ]);

        $reasons = $verdict['ranked'][0]['reasons'];
        $this->assertContains('capability_gap=7', $reasons, 'capability_gap must be in the reasons vector');
        $this->assertContains('user_impact=4', $reasons, 'user_impact must be in the reasons vector');
        $this->assertContains('autonomy_unlock=1', $reasons, 'autonomy_unlock still in reasons');
        $this->assertContains('waste_reduction=1', $reasons, 'waste_reduction still in reasons');
    }

    public function test_lower_waste_reduction_loses_to_higher_when_autonomy_ties(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'low-waste-reduction', 'waste_reduction' => 1]),
            $this->candidate(['candidate_id' => 'high-waste-reduction', 'waste_reduction' => 9]),
        ]);

        $this->assertSame('high-waste-reduction', $verdict['ranked'][0]['candidate_id']);
    }

    public function test_no_evidence_refs_blocks_candidate(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'no-evidence', 'evidence_refs' => []]),
        ]);

        $this->assertSame([], $verdict['ranked']);
        $this->assertCount(1, $verdict['rejected']);
        $this->assertContains('rejected:no_evidence_refs', $verdict['rejected'][0]['reasons']);
    }

    public function test_proxy_only_candidate_is_rejected(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id' => 'proxy',
                'proxy_signals' => ['novelty', 'line_churn'],
                'capability_gap' => 0,
                'user_impact' => 0,
                'autonomy_unlock' => 0,
            ]),
        ]);

        $this->assertSame([], $verdict['ranked']);
        $this->assertCount(1, $verdict['rejected']);
        $this->assertStringContainsString('rejected:proxy_signals_only', $verdict['rejected'][0]['reasons'][0]);
    }

    public function test_deterministic_tie_break_by_candidate_id_asc(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'charlie']),
            $this->candidate(['candidate_id' => 'alpha']),
            $this->candidate(['candidate_id' => 'bravo']),
        ]);

        $this->assertSame(['alpha', 'bravo', 'charlie'], array_column($verdict['ranked'], 'candidate_id'));
    }

    public function test_verdict_carries_no_composite_score_field(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([$this->candidate()]);
        // Reason vectors are transparent, NOT a single composite score.
        foreach ($verdict['ranked'][0]['factors'] as $key => $_) {
            $this->assertStringNotContainsString('composite', (string) $key);
            $this->assertStringNotContainsString('hype', (string) $key);
            $this->assertStringNotContainsString('total_score', (string) $key);
        }
    }

    public function test_ranking_is_deterministic_byte_identical(): void
    {
        $svc = new AtlasStrategyCouncilLeverageRanker;
        $a = $svc->rank([$this->candidate(['candidate_id' => 'a']), $this->candidate(['candidate_id' => 'b'])]);
        $b = $svc->rank([$this->candidate(['candidate_id' => 'a']), $this->candidate(['candidate_id' => 'b'])]);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_higher_unblocks_count_ranks_first_when_autonomy_ties(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'lo', 'unblocks_count' => 1]),
            $this->candidate(['candidate_id' => 'hi', 'unblocks_count' => 9]),
        ]);

        $this->assertSame('hi', $verdict['ranked'][0]['candidate_id']);
    }

    public function test_reasons_include_unblocks_count_and_risk_reduction(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['unblocks_count' => 3, 'risk_reduction' => 5]),
        ]);

        $reasons = $verdict['ranked'][0]['reasons'];
        $this->assertContains('unblocks_count=3', $reasons);
        $this->assertContains('risk_reduction=5', $reasons);
    }

    public function test_task_count_only_proxy_is_rejected(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id' => 'task-count-only',
                'proxy_signals' => ['task_count'],
                'capability_gap' => 0,
                'user_impact' => 0,
                'autonomy_unlock' => 0,
            ]),
        ]);

        $this->assertSame([], $verdict['ranked']);
        $this->assertCount(1, $verdict['rejected']);
    }

    public function test_green_self_report_only_proxy_is_rejected(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id' => 'self-report',
                'proxy_signals' => ['green_self_report'],
                'capability_gap' => 0,
                'user_impact' => 0,
                'autonomy_unlock' => 0,
            ]),
        ]);

        $this->assertSame([], $verdict['ranked']);
        $this->assertCount(1, $verdict['rejected']);
    }

    // ── dominance_trace ───────────────────────────────────────────────────────

    public function test_dominance_trace_present_on_each_ranked_item(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'alpha', 'autonomy_unlock' => 5]),
            $this->candidate(['candidate_id' => 'beta',  'autonomy_unlock' => 1]),
        ]);

        $this->assertArrayHasKey('dominance_trace', $verdict['ranked'][0]);
        $this->assertArrayHasKey('dominance_trace', $verdict['ranked'][1]);
    }

    public function test_dominance_trace_last_item_is_last_in_ranking(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'alpha', 'autonomy_unlock' => 5]),
            $this->candidate(['candidate_id' => 'beta',  'autonomy_unlock' => 1]),
        ]);

        $this->assertSame('last_in_ranking', $verdict['ranked'][1]['dominance_trace']);
    }

    public function test_dominance_trace_names_differentiating_factor(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'winner', 'autonomy_unlock' => 9]),
            $this->candidate(['candidate_id' => 'loser',  'autonomy_unlock' => 2]),
        ]);

        $trace = $verdict['ranked'][0]['dominance_trace'];
        $this->assertStringContainsString('autonomy_unlock', $trace);
        $this->assertStringContainsString('9', $trace);
        $this->assertStringContainsString('2', $trace);
    }

    public function test_dominance_trace_uses_capability_gap_when_autonomy_ties(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'hi-gap', 'capability_gap' => 8]),
            $this->candidate(['candidate_id' => 'lo-gap', 'capability_gap' => 2]),
        ]);

        // Both have same default autonomy_unlock=1, so capability_gap is the first differentiator
        $trace = $verdict['ranked'][0]['dominance_trace'];
        $this->assertStringContainsString('capability_gap', $trace);
    }

    public function test_dominance_trace_two_strong_candidates(): void
    {
        // Acceptance-criteria fixture: two strong candidates — winner must explain why it won
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id'    => 'arch-unlock',
                'autonomy_unlock' => 9,
                'unblocks_count'  => 7,
                'capability_gap'  => 8,
                'evidence_refs'   => ['receipt:arch-1', 'receipt:arch-2'],
            ]),
            $this->candidate([
                'candidate_id'    => 'cert-gate',
                'autonomy_unlock' => 5,
                'unblocks_count'  => 3,
                'capability_gap'  => 6,
                'evidence_refs'   => ['receipt:cert-1'],
            ]),
        ]);

        $this->assertSame('arch-unlock', $verdict['ranked'][0]['candidate_id']);
        $trace = $verdict['ranked'][0]['dominance_trace'];
        $this->assertNotEmpty($trace);
        $this->assertNotSame('last_in_ranking', $trace);
        $this->assertStringContainsString('autonomy_unlock', $trace);
        // No composite score in trace
        $this->assertStringNotContainsString('composite', $trace);
        $this->assertStringNotContainsString('total_score', $trace);
    }

    // ── rejected_proxy_summary ────────────────────────────────────────────────

    public function test_proxy_rejection_carries_rejected_proxy_summary(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id'   => 'proxy-only',
                'proxy_signals'  => ['task_count', 'novelty'],
                'capability_gap' => 0,
                'user_impact'    => 0,
                'autonomy_unlock'=> 0,
            ]),
        ]);

        $rejected = $verdict['rejected'][0];
        $this->assertArrayHasKey('rejected_proxy_summary', $rejected);

        $summary = $rejected['rejected_proxy_summary'];
        $this->assertSame(['task_count', 'novelty'], $summary['proxy_signals_present']);
        $this->assertContains('autonomy_unlock', $summary['zero_real_levers']);
        $this->assertContains('capability_gap',  $summary['zero_real_levers']);
        $this->assertSame('no_real_leverage_evidence', $summary['verdict']);
    }

    public function test_proxy_summary_does_not_promote_task_count_as_positive(): void
    {
        // The summary must list task_count as a proxy signal, not as a positive factor
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id'   => 'tc-only',
                'proxy_signals'  => ['task_count'],
                'capability_gap' => 0,
                'user_impact'    => 0,
                'autonomy_unlock'=> 0,
            ]),
        ]);

        $summary = $verdict['rejected'][0]['rejected_proxy_summary'];
        // task_count should be in proxy_signals_present (named as a problem, not a feature)
        $this->assertContains('task_count', $summary['proxy_signals_present']);
        // zero_real_levers shows what's missing, not what the proxy has
        $this->assertNotContains('task_count', $summary['zero_real_levers']);
    }

    public function test_no_evidence_rejection_has_no_rejected_proxy_summary(): void
    {
        // Only proxy-only rejections get the summary; no_evidence_refs does not
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'no-ev', 'evidence_refs' => []]),
        ]);

        $this->assertArrayNotHasKey('rejected_proxy_summary', $verdict['rejected'][0]);
    }

    // ── cross-campaign compounding ─────────────────────────────────────────────

    public function test_cross_campaign_compounding_ranks_above_otherwise_similar_one_off(): void
    {
        // Both candidates have the same autonomy_unlock/capability_gap/user_impact.
        // The compounding one has campaign_count>=2 and unlock_family_count>=2.
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id'       => 'one-off',
                'autonomy_unlock'    => 5,
                'capability_gap'     => 5,
            ]),
            $this->candidate([
                'candidate_id'       => 'compounding',
                'autonomy_unlock'    => 5,
                'capability_gap'     => 5,
                'campaign_count'     => 3,
                'unlock_family_count'=> 2,
            ]),
        ]);

        $this->assertSame('compounding', $verdict['ranked'][0]['candidate_id'],
            'candidate with cross-campaign compounding must outrank an otherwise identical one-off');
        $this->assertSame('one-off', $verdict['ranked'][1]['candidate_id']);
    }

    public function test_reasons_include_campaign_count_and_unlock_family_count(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'campaign_count'      => 4,
                'unlock_family_count' => 3,
            ]),
        ]);

        $reasons = $verdict['ranked'][0]['reasons'];
        $this->assertContains('campaign_count=4', $reasons);
        $this->assertContains('unlock_family_count=3', $reasons);
        $this->assertContains('cross_campaign_compounding=1', $reasons);
    }

    public function test_dominance_trace_names_cross_campaign_compounding_when_differentiating(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id'       => 'compound',
                'campaign_count'     => 2,
                'unlock_family_count'=> 2,
            ]),
            $this->candidate([
                'candidate_id' => 'one-off',
            ]),
        ]);

        $trace = $verdict['ranked'][0]['dominance_trace'];
        $this->assertStringContainsString('cross_campaign_compounding', $trace);
    }

    public function test_below_threshold_campaign_count_does_not_trigger_compounding(): void
    {
        // campaign_count=1 — below threshold of 2 — must not get compounding boost.
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id'        => 'almost',
                'campaign_count'      => 1,
                'unlock_family_count' => 5,
                'autonomy_unlock'     => 1,
            ]),
            $this->candidate([
                'candidate_id'    => 'strong',
                'autonomy_unlock' => 9,
            ]),
        ]);

        $this->assertSame('strong', $verdict['ranked'][0]['candidate_id'],
            'campaign_count=1 must not trigger compounding; autonomy_unlock=9 still wins');
        $this->assertSame(0, $verdict['ranked'][0]['factors']['cross_campaign_compounding']);
    }

    public function test_high_task_count_proxy_still_rejected_even_with_campaign_count(): void
    {
        // proxy_signals=[task_count] + no real levers → reject, regardless of campaign_count
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id'       => 'proxy-compounding',
                'proxy_signals'      => ['task_count'],
                'capability_gap'     => 0,
                'user_impact'        => 0,
                'autonomy_unlock'    => 0,
                'campaign_count'     => 5,
                'unlock_family_count'=> 5,
            ]),
        ]);

        $this->assertSame([], $verdict['ranked']);
        $this->assertCount(1, $verdict['rejected']);
        $this->assertStringContainsString('proxy_signals_only', $verdict['rejected'][0]['reasons'][0]);
    }

    public function test_compounding_factors_have_no_composite_score_key(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['campaign_count' => 3, 'unlock_family_count' => 3]),
        ]);

        foreach ($verdict['ranked'][0]['factors'] as $key => $_) {
            $this->assertStringNotContainsString('composite', (string) $key);
            $this->assertStringNotContainsString('total_score', (string) $key);
        }
    }

    // ── evidence strength breaks ties before cosmetic factors ──────────────────

    public function test_stronger_evidence_breaks_a_tie_on_all_other_real_levers(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'thin-evidence', 'evidence_refs' => ['receipt:r1']]),
            $this->candidate(['candidate_id' => 'strong-evidence', 'evidence_refs' => ['receipt:r1', 'receipt:r2', 'receipt:r3']]),
        ]);

        $this->assertSame(['strong-evidence', 'thin-evidence'], array_column($verdict['ranked'], 'candidate_id'),
            'more evidence_refs must win a tie on every other real-leverage factor');
    }

    public function test_evidence_strength_is_outranked_by_autonomy_unlock(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'low-autonomy-much-evidence', 'autonomy_unlock' => 1, 'evidence_refs' => ['r1', 'r2', 'r3', 'r4']]),
            $this->candidate(['candidate_id' => 'high-autonomy-thin-evidence', 'autonomy_unlock' => 9, 'evidence_refs' => ['r1']]),
        ]);

        $this->assertSame('high-autonomy-thin-evidence', $verdict['ranked'][0]['candidate_id'],
            'evidence strength must not outrank a real autonomy_unlock advantage');
    }

    public function test_dominance_trace_names_evidence_refs_count_when_it_is_the_first_differentiator(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'thin-evidence', 'evidence_refs' => ['receipt:r1']]),
            $this->candidate(['candidate_id' => 'strong-evidence', 'evidence_refs' => ['receipt:r1', 'receipt:r2']]),
        ]);

        $this->assertStringContainsString('evidence_refs_count', $verdict['ranked'][0]['dominance_trace']);
    }

    // ── AC: compound_unlock, proof_cost, implementation_risk, simplification_gain, worker_fit, give_back_likelihood ──

    public function test_factors_include_all_six_new_score_components(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'compound_unlock' => 3,
                'proof_cost' => 2,
                'implementation_risk' => 1,
                'simplification_gain' => 4,
                'worker_fit' => 5,
                'give_back_likelihood' => 1,
            ]),
        ]);

        $factors = $verdict['ranked'][0]['factors'];
        $this->assertSame(3, $factors['compound_unlock']);
        $this->assertSame(2, $factors['proof_cost']);
        $this->assertSame(1, $factors['implementation_risk']);
        $this->assertSame(4, $factors['simplification_gain']);
        $this->assertSame(5, $factors['worker_fit']);
        $this->assertSame(1, $factors['give_back_likelihood']);

        $reasons = $verdict['ranked'][0]['reasons'];
        $this->assertContains('compound_unlock=3', $reasons);
        $this->assertContains('proof_cost=2', $reasons);
        $this->assertContains('implementation_risk=1', $reasons);
        $this->assertContains('simplification_gain=4', $reasons);
        $this->assertContains('worker_fit=5', $reasons);
        $this->assertContains('give_back_likelihood=1', $reasons);
    }

    public function test_high_unlock_winner_breaks_a_tie_on_every_original_factor(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'low-unlock', 'compound_unlock' => 1]),
            $this->candidate(['candidate_id' => 'high-unlock', 'compound_unlock' => 9]),
        ]);

        $this->assertSame('high-unlock', $verdict['ranked'][0]['candidate_id']);
        $this->assertSame('compound_unlock=9_beats_1', $verdict['ranked'][0]['dominance_trace']);
    }

    public function test_risky_candidate_is_demoted_below_a_lower_risk_tie(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'risky', 'implementation_risk' => 9]),
            $this->candidate(['candidate_id' => 'safe', 'implementation_risk' => 1]),
        ]);

        $this->assertSame('safe', $verdict['ranked'][0]['candidate_id'], 'lower implementation_risk must win a tie');
    }

    public function test_simplification_winner_breaks_a_tie(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'low-gain', 'simplification_gain' => 1]),
            $this->candidate(['candidate_id' => 'high-gain', 'simplification_gain' => 9]),
        ]);

        $this->assertSame('high-gain', $verdict['ranked'][0]['candidate_id']);
    }

    public function test_worker_mismatch_demotes_a_candidate_below_a_better_fit_tie(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'mismatch', 'worker_fit' => 0]),
            $this->candidate(['candidate_id' => 'good-fit', 'worker_fit' => 5]),
        ]);

        $this->assertSame('good-fit', $verdict['ranked'][0]['candidate_id']);
    }

    public function test_give_back_likelihood_and_proof_cost_are_lowest_priority_tiebreaks(): void
    {
        // Proof of ordering: proof_cost only differentiates when give_back_likelihood already ties.
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'expensive-proof', 'proof_cost' => 9, 'give_back_likelihood' => 0]),
            $this->candidate(['candidate_id' => 'cheap-proof', 'proof_cost' => 1, 'give_back_likelihood' => 0]),
        ]);

        $this->assertSame('cheap-proof', $verdict['ranked'][0]['candidate_id'], 'lower proof_cost wins when give_back_likelihood ties');
    }

    public function test_new_factors_never_override_a_pre_existing_higher_priority_factor(): void
    {
        // autonomy_unlock (a pre-existing, higher-priority factor) must still decide the winner even
        // when the loser has every new factor maxed out — proves the new factors are strictly
        // lowest-priority and cannot hijack the established ranking philosophy.
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id' => 'low-autonomy-max-new-factors',
                'autonomy_unlock' => 1,
                'compound_unlock' => 9,
                'simplification_gain' => 9,
                'worker_fit' => 9,
                'proof_cost' => 0,
                'implementation_risk' => 0,
                'give_back_likelihood' => 0,
            ]),
            $this->candidate([
                'candidate_id' => 'high-autonomy-zero-new-factors',
                'autonomy_unlock' => 9,
            ]),
        ]);

        $this->assertSame('high-autonomy-zero-new-factors', $verdict['ranked'][0]['candidate_id']);
    }

    public function test_deterministic_tie_break_still_holds_when_all_new_factors_are_default(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate(['candidate_id' => 'charlie']),
            $this->candidate(['candidate_id' => 'alpha']),
            $this->candidate(['candidate_id' => 'bravo']),
        ]);

        $this->assertSame(['alpha', 'bravo', 'charlie'], array_column($verdict['ranked'], 'candidate_id'));
    }

    public function test_structural_dependency_bottleneck_beats_high_volume_shallow_work(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id' => 'high-volume-shallow',
                'proxy_signals' => ['task_count'],
                'dependency_reach' => 1,
                'recurrence' => 1,
                'outcome_gap' => 1,
                'evidence_freshness' => 0.95,
            ]),
            $this->candidate([
                'candidate_id' => 'dependency-bottleneck',
                'dependency_reach' => 9,
                'recurrence' => 8,
                'outcome_gap' => 9,
                'evidence_freshness' => 0.95,
            ]),
        ]);

        $this->assertSame('dependency-bottleneck', $verdict['ranked'][0]['candidate_id']);
        $this->assertSame(9, $verdict['ranked'][0]['factors']['dependency_reach']);
        $this->assertContains('outcome_gap=9', $verdict['ranked'][0]['reasons']);
    }

    public function test_stale_or_missing_world_outcome_evidence_lowers_confidence_without_becoming_success(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id' => 'stale',
                'evidence_freshness' => 0.20,
            ]),
            $this->candidate([
                'candidate_id' => 'fresh',
                'evidence_freshness' => 0.95,
            ]),
            $this->candidate(['candidate_id' => 'missing']),
        ]);

        $this->assertSame(['fresh', 'stale', 'missing'], array_column($verdict['ranked'], 'candidate_id'));
        $this->assertSame(0.20, $verdict['ranked'][1]['factors']['evidence_freshness']);
        $this->assertSame(0.0, $verdict['ranked'][2]['factors']['evidence_freshness']);
        $this->assertContains('evidence_freshness=0', $verdict['ranked'][2]['reasons']);
    }

    public function test_stale_domain_facts_and_outcome_free_work_receive_no_freshness_credit(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id' => 'stale-domain',
                'world_freshness' => 0.10,
                'outcome_freshness' => 0.90,
                'outcome_confidence' => 0.0,
            ]),
            $this->candidate([
                'candidate_id' => 'fresh-domain',
                'world_freshness' => 0.90,
                'outcome_freshness' => 0.90,
                'outcome_confidence' => 0.8,
                'outcome_signal' => 'positive',
            ]),
            $this->candidate(['candidate_id' => 'outcome-free']),
        ]);

        $this->assertSame(['fresh-domain', 'stale-domain', 'outcome-free'], array_column($verdict['ranked'], 'candidate_id'));
        $this->assertSame(0.0, $verdict['ranked'][2]['factors']['outcome_confidence']);
        $this->assertSame('unknown', $verdict['ranked'][2]['factors']['outcome_signal']);
    }

    public function test_repeated_failures_remain_negative_and_do_not_become_success(): void
    {
        $verdict = (new AtlasStrategyCouncilLeverageRanker)->rank([
            $this->candidate([
                'candidate_id' => 'repeated-failure',
                'outcome_signal' => 'negative',
                'outcome_confidence' => 0.0,
                'failure_recurrence' => 4,
            ]),
            $this->candidate([
                'candidate_id' => 'unknown-work',
                'outcome_signal' => 'unknown',
                'outcome_confidence' => 0.0,
            ]),
        ]);

        $this->assertSame('unknown-work', $verdict['ranked'][0]['candidate_id']);
        $this->assertSame('negative', $verdict['ranked'][1]['factors']['outcome_signal']);
        $this->assertSame(4, $verdict['ranked'][1]['factors']['failure_recurrence']);
        $this->assertContains('outcome_signal=negative', $verdict['ranked'][1]['reasons']);
    }
}
