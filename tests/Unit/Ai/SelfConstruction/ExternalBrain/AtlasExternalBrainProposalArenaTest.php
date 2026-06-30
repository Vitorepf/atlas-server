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
}
