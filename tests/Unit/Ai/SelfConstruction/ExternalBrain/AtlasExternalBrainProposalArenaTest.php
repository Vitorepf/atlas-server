<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProposalArena;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainProposalArenaTest extends TestCase
{
    private AtlasExternalBrainProposalArena $arena;

    protected function setUp(): void
    {
        $this->arena = new AtlasExternalBrainProposalArena;
    }

    private function proposal(string $id, array $overrides = []): array
    {
        return array_merge([
            'proposal_id'               => $id,
            'objective'                 => 'Implement '.$id,
            'leverage'                  => 0.7,
            'evidence_strength'         => 0.7,
            'implementability'          => 0.8,
            'anti_goodhart_risk'        => 0.1,
            'queue_pressure'            => 0.1,
            'compression_opportunity'   => 0.3,
            'is_proxy'                  => false,
            'is_duplicate'              => false,
            'has_runnable_evidence_path' => true,
            'evidence_refs'             => ['receipt:default-a', 'receipt:default-b'],
        ], $overrides);
    }

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->arena->compete(['proposals' => [$this->proposal('p1')]]);

        foreach (['schema', 'verdict', 'winner', 'rejected', 'arena_hash'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainProposalArena::SCHEMA, $result['schema']);
        $this->assertStringStartsWith('arena_', $result['arena_hash']);
    }

    // ── AC1: deterministic ranking, winner + rejected with reasons ───────────

    public function test_single_valid_proposal_becomes_winner(): void
    {
        $result = $this->arena->compete(['proposals' => [$this->proposal('p1')]]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_WINNER_SELECTED, $result['verdict']);
        $this->assertSame('p1', $result['winner']['proposal_id']);
        $this->assertSame([], $result['rejected']);
    }

    public function test_required_competition_rejects_single_proposal(): void
    {
        $result = $this->arena->compete([
            'proposals' => [$this->proposal('only')],
            'require_competition' => true,
        ]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
        $this->assertNull($result['winner']);
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_COMPETITION_QUORUM, $result['rejected'][0]['reason']);
    }

    public function test_required_competition_still_selects_best_independent_proposal(): void
    {
        $result = $this->arena->compete([
            'proposals' => [
                $this->proposal('low', ['leverage' => 0.2]),
                $this->proposal('high', ['leverage' => 0.9]),
            ],
            'require_competition' => true,
        ]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_WINNER_SELECTED, $result['verdict']);
        $this->assertSame('high', $result['winner']['proposal_id']);
    }

    public function test_required_quality_contract_rejects_proposal_without_finding_baseline_delta_rollback_and_test(): void
    {
        $result = $this->arena->compete([
            'proposals' => [$this->proposal('missing-contract')],
            'require_quality_contract' => true,
        ]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_QUALITY_CONTRACT, $result['rejected'][0]['reason']);
    }

    public function test_highest_leverage_wins_over_lower_leverage(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('low',  ['leverage' => 0.2]),
            $this->proposal('high', ['leverage' => 0.9]),
        ]]);

        $this->assertSame('high', $result['winner']['proposal_id']);
        $this->assertCount(1, $result['rejected']);
        $this->assertSame('low', $result['rejected'][0]['proposal_id']);
        $this->assertSame('outscored_by_winner', $result['rejected'][0]['reason']);
    }

    public function test_ranking_is_deterministic_for_same_input(): void
    {
        $proposals = ['proposals' => [
            $this->proposal('alpha', ['leverage' => 0.6]),
            $this->proposal('beta',  ['leverage' => 0.8]),
        ]];

        $a = $this->arena->compete($proposals);
        $b = $this->arena->compete($proposals);

        $this->assertSame($a['winner']['proposal_id'], $b['winner']['proposal_id']);
        $this->assertSame($a['arena_hash'], $b['arena_hash']);
    }

    public function test_tie_broken_by_proposal_id_lexicographic(): void
    {
        // Identical scores — lexicographic tie-break.
        $p = ['leverage' => 0.5, 'evidence_strength' => 0.5, 'implementability' => 0.5,
              'anti_goodhart_risk' => 0.0, 'queue_pressure' => 0.0, 'compression_opportunity' => 0.0];

        $result = $this->arena->compete(['proposals' => [
            $this->proposal('zzz', $p),
            $this->proposal('aaa', $p),
        ]]);

        $this->assertSame('aaa', $result['winner']['proposal_id']);
    }

    public function test_winner_includes_arena_score(): void
    {
        $result = $this->arena->compete(['proposals' => [$this->proposal('p1')]]);
        $this->assertArrayHasKey('arena_score', $result['winner']);
        $this->assertIsFloat($result['winner']['arena_score']);
    }

    // ── AC2: all rejected when every proposal fails disqualification ─────────

    public function test_all_rejected_when_all_proposals_are_proxy(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('p1', ['is_proxy' => true]),
            $this->proposal('p2', ['is_proxy' => true]),
        ]]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
        $this->assertNull($result['winner']);
        $this->assertCount(2, $result['rejected']);
    }

    public function test_proxy_heavy_proposal_is_disqualified(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('proxy', ['is_proxy' => true]),
            $this->proposal('real'),
        ]]);

        $this->assertSame('real', $result['winner']['proposal_id']);
        $reasons = array_column($result['rejected'], 'reason', 'proposal_id');
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_PROXY, $reasons['proxy']);
    }

    public function test_duplicate_proposal_is_disqualified(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('dup', ['is_duplicate' => true]),
            $this->proposal('fresh'),
        ]]);

        $reasons = array_column($result['rejected'], 'reason', 'proposal_id');
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_DUPLICATE, $reasons['dup']);
    }

    public function test_unimplementable_proposal_is_disqualified(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('vague', ['implementability' => 0.10]),
            $this->proposal('clear'),
        ]]);

        $reasons = array_column($result['rejected'], 'reason', 'proposal_id');
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_UNIMPLEMENTABLE, $reasons['vague']);
    }

    public function test_no_runnable_evidence_path_is_disqualified(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('blind', ['has_runnable_evidence_path' => false]),
            $this->proposal('proven'),
        ]]);

        $reasons = array_column($result['rejected'], 'reason', 'proposal_id');
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_NO_EVIDENCE_PATH, $reasons['blind']);
    }

    public function test_empty_proposals_returns_all_rejected_with_no_winner(): void
    {
        $result = $this->arena->compete(['proposals' => []]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
        $this->assertNull($result['winner']);
    }

    // ── AC1: evidence quorum ─────────────────────────────────────────────────

    public function test_no_evidence_refs_is_rejected_as_evidence_quorum(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('blind', ['evidence_refs' => []]),
        ]]);

        $reasons = array_column($result['rejected'], 'reason', 'proposal_id');
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_EVIDENCE_QUORUM, $reasons['blind']);
    }

    public function test_single_evidence_ref_is_rejected_as_evidence_quorum(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('single-src', ['evidence_refs' => ['receipt:a']]),
        ]]);

        $reasons = array_column($result['rejected'], 'reason', 'proposal_id');
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_EVIDENCE_QUORUM, $reasons['single-src']);
    }

    public function test_self_asserted_proposal_is_rejected_even_with_two_evidence_refs(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('self', [
                'self_asserted'  => true,
                'evidence_refs'  => ['receipt:a', 'receipt:b'],
            ]),
        ]]);

        $reasons = array_column($result['rejected'], 'reason', 'proposal_id');
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_EVIDENCE_QUORUM, $reasons['self']);
    }

    public function test_two_evidence_refs_and_not_self_asserted_passes_quorum(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('p1', ['evidence_refs' => ['receipt:a', 'receipt:b']]),
        ]]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_WINNER_SELECTED, $result['verdict']);
        $this->assertSame('p1', $result['winner']['proposal_id']);
        $this->assertSame([], $result['rejected']);
    }

    public function test_high_scoring_proposal_rejected_when_self_asserted(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('top-scores', [
                'leverage'           => 1.0,
                'evidence_strength'  => 1.0,
                'self_asserted'       => true,
                'evidence_refs'       => ['receipt:a', 'receipt:b'],
            ]),
            $this->proposal('modest-but-real', [
                'leverage'           => 0.5,
                'evidence_strength'  => 0.5,
                'evidence_refs'       => ['receipt:x', 'receipt:y'],
            ]),
        ]]);

        $this->assertSame('modest-but-real', $result['winner']['proposal_id']);
        $reasons = array_column($result['rejected'], 'reason', 'proposal_id');
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_EVIDENCE_QUORUM, $reasons['top-scores']);
    }

    // ── Anti-Goodhart risk reduces score (bad) more than queue pressure ──────

    public function test_high_anti_goodhart_risk_reduces_score_below_high_queue_pressure(): void
    {
        $highRisk = $this->arena->compete(['proposals' => [
            $this->proposal('x', ['anti_goodhart_risk' => 1.0, 'queue_pressure' => 0.0]),
        ]])['winner']['arena_score'];

        $highQueue = $this->arena->compete(['proposals' => [
            $this->proposal('x', ['anti_goodhart_risk' => 0.0, 'queue_pressure' => 1.0]),
        ]])['winner']['arena_score'];

        $this->assertLessThan($highQueue, $highRisk, 'anti_goodhart_risk must penalize more than queue_pressure');
    }

    // ── AC1: template_farm disqualification ───────────────────────────────────

    public function test_template_farm_proposal_is_rejected_despite_high_leverage(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('farm', [
                'leverage'               => 1.0,
                'template_similarity'    => 0.9,
                'repeated_pattern_count' => 5,
            ]),
            $this->proposal('real'),
        ]]);

        $this->assertSame('real', $result['winner']['proposal_id']);
        $reasons = array_column($result['rejected'], 'reason', 'proposal_id');
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_TEMPLATE_FARM, $reasons['farm']);
    }

    public function test_high_similarity_alone_does_not_trigger_template_farm(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('p', ['template_similarity' => 0.9, 'repeated_pattern_count' => 1]),
        ]]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_WINNER_SELECTED, $result['verdict']);
    }

    public function test_high_repeat_count_alone_does_not_trigger_template_farm(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('p', ['template_similarity' => 0.2, 'repeated_pattern_count' => 10]),
        ]]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_WINNER_SELECTED, $result['verdict']);
    }

    public function test_all_template_farm_returns_all_rejected(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('f1', ['template_similarity' => 0.8, 'repeated_pattern_count' => 4]),
            $this->proposal('f2', ['template_similarity' => 0.9, 'repeated_pattern_count' => 3]),
        ]]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
        $this->assertNull($result['winner']);
    }

    // ── AC2: operator_rank_priority + give_back / quarantine rates ────────────

    public function test_operator_rank_priority_breaks_close_call(): void
    {
        $base = [
            'leverage' => 0.5, 'evidence_strength' => 0.5, 'implementability' => 0.5,
            'compression_opportunity' => 0.0, 'anti_goodhart_risk' => 0.0, 'queue_pressure' => 0.0,
        ];

        $result = $this->arena->compete(['proposals' => [
            $this->proposal('plain',   array_merge($base, ['operator_rank_priority' => 0.0, 'historical_give_back_rate' => 0.5, 'quarantine_rate' => 0.5])),
            $this->proposal('favored', array_merge($base, ['operator_rank_priority' => 1.0, 'historical_give_back_rate' => 0.0, 'quarantine_rate' => 0.0])),
        ]]);

        $this->assertSame('favored', $result['winner']['proposal_id']);
    }

    public function test_high_give_back_rate_penalizes_score(): void
    {
        $clean = $this->arena->compete(['proposals' => [
            $this->proposal('c', ['historical_give_back_rate' => 0.0]),
        ]])['winner']['arena_score'];

        $risky = $this->arena->compete(['proposals' => [
            $this->proposal('r', ['historical_give_back_rate' => 1.0]),
        ]])['winner']['arena_score'];

        $this->assertGreaterThan($risky, $clean);
    }

    public function test_high_quarantine_rate_penalizes_score(): void
    {
        $clean = $this->arena->compete(['proposals' => [
            $this->proposal('c', ['quarantine_rate' => 0.0]),
        ]])['winner']['arena_score'];

        $risky = $this->arena->compete(['proposals' => [
            $this->proposal('r', ['quarantine_rate' => 1.0]),
        ]])['winner']['arena_score'];

        $this->assertGreaterThan($risky, $clean);
    }

    public function test_operator_rank_priority_does_not_rescue_template_farm(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('farm', [
                'operator_rank_priority' => 1.0,
                'template_similarity'    => 0.8,
                'repeated_pattern_count' => 5,
            ]),
        ]]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
    }

    // ── named scoring dimensions (AC2) ──────────────────────────────────────────

    public function test_structural_leverage_alias_feeds_the_leverage_dimension(): void
    {
        $highStructuralLeverage = $this->arena->compete(['proposals' => [
            $this->proposal('h', ['leverage' => null, 'structural_leverage' => 0.9]),
        ]])['winner']['arena_score'];

        $lowStructuralLeverage = $this->arena->compete(['proposals' => [
            $this->proposal('l', ['leverage' => null, 'structural_leverage' => 0.1]),
        ]])['winner']['arena_score'];

        $this->assertGreaterThan($lowStructuralLeverage, $highStructuralLeverage);
    }

    public function test_novelty_increases_arena_score(): void
    {
        $novel = $this->arena->compete(['proposals' => [
            $this->proposal('n', ['novelty' => 1.0]),
        ]])['winner']['arena_score'];

        $stale = $this->arena->compete(['proposals' => [
            $this->proposal('s', ['novelty' => 0.0]),
        ]])['winner']['arena_score'];

        $this->assertGreaterThan($stale, $novel);
    }

    public function test_anti_proxy_quality_increases_arena_score(): void
    {
        $highQuality = $this->arena->compete(['proposals' => [
            $this->proposal('q', ['anti_proxy_quality' => 1.0]),
        ]])['winner']['arena_score'];

        $lowQuality = $this->arena->compete(['proposals' => [
            $this->proposal('p', ['anti_proxy_quality' => 0.0]),
        ]])['winner']['arena_score'];

        $this->assertGreaterThan($lowQuality, $highQuality);
    }

    public function test_evidence_strength_increases_arena_score(): void
    {
        $strong = $this->arena->compete(['proposals' => [
            $this->proposal('e1', ['evidence_strength' => 1.0]),
        ]])['winner']['arena_score'];

        $weak = $this->arena->compete(['proposals' => [
            $this->proposal('e2', ['evidence_strength' => 0.0]),
        ]])['winner']['arena_score'];

        $this->assertGreaterThan($weak, $strong);
    }

    public function test_implementability_increases_arena_score(): void
    {
        $easy = $this->arena->compete(['proposals' => [
            $this->proposal('i1', ['implementability' => 1.0]),
        ]])['winner']['arena_score'];

        $hard = $this->arena->compete(['proposals' => [
            $this->proposal('i2', ['implementability' => 0.4]),
        ]])['winner']['arena_score'];

        $this->assertGreaterThan($hard, $easy);
    }

    // ── all-weak / all-template-variant batches refuse a winner (AC4) ─────────

    public function test_all_weak_implementability_batch_has_no_winner(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('w1', ['implementability' => 0.1]),
            $this->proposal('w2', ['implementability' => 0.05]),
        ]]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
        $this->assertNull($result['winner']);
        $this->assertCount(2, $result['rejected']);
    }

    public function test_all_template_variant_batch_has_no_winner(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('t1', ['template_similarity' => 0.9, 'repeated_pattern_count' => 4]),
            $this->proposal('t2', ['template_similarity' => 0.85, 'repeated_pattern_count' => 6]),
        ]]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
        foreach ($result['rejected'] as $rejection) {
            $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_TEMPLATE_FARM, $rejection['reason']);
        }
    }

    // ── AC2: rhetorical "confidence" never scores; concrete evidence decides ──

    public function test_high_confidence_without_code_or_evidence_loses_to_lower_confidence_evidence_backed(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('confident_but_blind', [
                'confidence'                 => 0.95,
                'evidence_strength'          => 0.0,
                'has_runnable_evidence_path' => false,
                'leverage'                   => 0.9,
            ]),
            $this->proposal('humble_but_proven', [
                'confidence'                 => 0.3,
                'evidence_strength'          => 0.7,
                'has_runnable_evidence_path' => true,
                'leverage'                   => 0.5,
            ]),
        ]]);

        $this->assertSame('humble_but_proven', $result['winner']['proposal_id']);
        $reasons = array_column($result['rejected'], 'reason', 'proposal_id');
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_NO_EVIDENCE_PATH, $reasons['confident_but_blind']);
    }

    // ── AC3: template-farm penalty ignores which class names are mentioned ────

    public function test_repeated_template_shaped_proposals_are_penalized_despite_different_class_names(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('widget_a', [
                'objective'              => 'Harden AtlasFooWidgetService so it validates input',
                'template_similarity'    => 0.8,
                'repeated_pattern_count' => 4,
            ]),
            $this->proposal('widget_b', [
                'objective'              => 'Harden AtlasBarGadgetHandler so it validates input',
                'template_similarity'    => 0.8,
                'repeated_pattern_count' => 4,
            ]),
        ]]);

        $this->assertSame(AtlasExternalBrainProposalArena::VERDICT_ALL_REJECTED, $result['verdict']);
        $reasons = array_column($result['rejected'], 'reason', 'proposal_id');
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_TEMPLATE_FARM, $reasons['widget_a']);
        $this->assertSame(AtlasExternalBrainProposalArena::DISQUALIFY_TEMPLATE_FARM, $reasons['widget_b']);
    }

    // ── AC4: deletion-first simplification leverage can outscore a feature-add ──

    public function test_simplification_leverage_wins_over_feature_addition_without_losing_capability(): void
    {
        $result = $this->arena->compete(['proposals' => [
            $this->proposal('simplify_and_delete', [
                'leverage'                => 0.9,
                'compression_opportunity' => 1.0,
                'evidence_strength'       => 0.6,
                'implementability'        => 0.8,
                'novelty'                 => 0.0,
            ]),
            $this->proposal('add_new_feature', [
                'leverage'                => 0.3,
                'compression_opportunity' => 0.0,
                'evidence_strength'       => 0.7,
                'implementability'        => 0.8,
                'novelty'                 => 1.0,
            ]),
        ]]);

        $this->assertSame('simplify_and_delete', $result['winner']['proposal_id']);
    }
}
