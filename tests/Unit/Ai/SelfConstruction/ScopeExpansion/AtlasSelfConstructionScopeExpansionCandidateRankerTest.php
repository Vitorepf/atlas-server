<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ScopeExpansion;

use App\Services\Ai\SelfConstruction\ScopeExpansion\AtlasSelfConstructionScopeExpansionCandidateRanker;
use Tests\TestCase;

final class AtlasSelfConstructionScopeExpansionCandidateRankerTest extends TestCase
{
    private function goodCandidate(array $overrides = []): array
    {
        return array_merge([
            'scope_id' => 'scope-a',
            'evidence_refs' => ['doc:foo.md'],
            'atlas_native_owner' => true,
            'requires_operator' => false,
            'requires_human' => false,
            'requires_external_provider' => false,
            'proven_leverage_tier' => 2,
            'autonomy_readiness_tier' => 2,
            'risk' => 3,
        ], $overrides);
    }

    public function test_accepts_clean_candidate_and_emits_schema(): void
    {
        $ranker = new AtlasSelfConstructionScopeExpansionCandidateRanker;
        $out = $ranker->rank([
            'candidates' => [$this->goodCandidate()],
            'risk_budget' => ['max_risk' => 5],
        ]);

        $this->assertSame(AtlasSelfConstructionScopeExpansionCandidateRanker::SCHEMA, $out['schema_version']);
        $this->assertSame('ok', $out['status']);
        $this->assertCount(1, $out['accepted_candidates']);
        $this->assertSame('scope-a', $out['accepted_candidates'][0]['scope_id']);
        $this->assertNotEmpty($out['ranker_hash']);
    }

    public function test_rejects_human_operator_provider_dependent_candidates(): void
    {
        $ranker = new AtlasSelfConstructionScopeExpansionCandidateRanker;
        $out = $ranker->rank([
            'candidates' => [
                $this->goodCandidate(['scope_id' => 'human', 'requires_human' => true]),
                $this->goodCandidate(['scope_id' => 'op', 'requires_operator' => true]),
                $this->goodCandidate(['scope_id' => 'prov', 'requires_external_provider' => true]),
                $this->goodCandidate(['scope_id' => 'non-native', 'atlas_native_owner' => false]),
            ],
            'risk_budget' => ['max_risk' => 10],
        ]);

        $this->assertSame([], $out['accepted_candidates']);
        $byId = [];
        foreach ($out['rejected_candidates'] as $r) {
            $byId[$r['scope_id']] = $r['reasons'];
        }
        $this->assertContains('human_dependency', $byId['human']);
        $this->assertContains('operator_dependency', $byId['op']);
        $this->assertContains('external_provider_dependency', $byId['prov']);
        $this->assertContains('non_atlas_native_owner', $byId['non-native']);
    }

    public function test_rejects_missing_evidence_and_risk_budget_overflow_and_scalar_only_proxy(): void
    {
        $ranker = new AtlasSelfConstructionScopeExpansionCandidateRanker;
        $out = $ranker->rank([
            'candidates' => [
                $this->goodCandidate(['scope_id' => 'noev', 'evidence_refs' => []]),
                $this->goodCandidate(['scope_id' => 'risky', 'risk' => 99]),
                $this->goodCandidate(['scope_id' => 'proxy', 'scalar_only_proxy' => true]),
            ],
            'risk_budget' => ['max_risk' => 5],
        ]);

        $this->assertSame([], $out['accepted_candidates']);
        $byId = [];
        foreach ($out['rejected_candidates'] as $r) {
            $byId[$r['scope_id']] = $r['reasons'];
        }
        $this->assertContains('missing_evidence_refs', $byId['noev']);
        $this->assertContains('risk_budget_exceeded', $byId['risky']);
        $this->assertContains('scalar_only_proxy', $byId['proxy']);
    }

    public function test_deterministic_ranking_by_leverage_then_readiness_then_risk_then_scope_id(): void
    {
        $ranker = new AtlasSelfConstructionScopeExpansionCandidateRanker;
        $out = $ranker->rank([
            'candidates' => [
                $this->goodCandidate(['scope_id' => 'c', 'proven_leverage_tier' => 3, 'autonomy_readiness_tier' => 2, 'risk' => 2]),
                $this->goodCandidate(['scope_id' => 'a', 'proven_leverage_tier' => 3, 'autonomy_readiness_tier' => 2, 'risk' => 1]),
                $this->goodCandidate(['scope_id' => 'b', 'proven_leverage_tier' => 3, 'autonomy_readiness_tier' => 2, 'risk' => 1]),
                $this->goodCandidate(['scope_id' => 'low', 'proven_leverage_tier' => 1]),
            ],
            'risk_budget' => ['max_risk' => 10],
        ]);

        $order = array_column($out['accepted_candidates'], 'scope_id');
        // leverage 3 first; same tier sorted by readiness/risk/scope_id (a before b before c); then leverage 1 last.
        $this->assertSame(['a', 'b', 'c', 'low'], $order);
    }

    public function test_hash_is_stable_for_equivalent_input(): void
    {
        $ranker = new AtlasSelfConstructionScopeExpansionCandidateRanker;
        $a = $ranker->rank([
            'candidates' => [$this->goodCandidate(['scope_id' => 'x'])],
            'risk_budget' => ['max_risk' => 5],
        ]);
        $b = $ranker->rank([
            'candidates' => [$this->goodCandidate(['scope_id' => 'x'])],
            'risk_budget' => ['max_risk' => 5],
        ]);
        $this->assertSame($a['ranker_hash'], $b['ranker_hash']);
    }

    public function test_high_leverage_ready_low_proof_cost_ranks_above_broad_unready_scope_and_emits_score_components(): void
    {
        $ranker = new AtlasSelfConstructionScopeExpansionCandidateRanker;
        $out = $ranker->rank([
            'candidates' => [
                $this->goodCandidate([
                    'scope_id'               => 'broad-unready',
                    'proven_leverage_tier'   => 2,
                    'autonomy_readiness_tier'=> 1,
                    'proof_cost'             => 8,
                    'isolation'              => 2,
                    'risk'                   => 5,
                ]),
                $this->goodCandidate([
                    'scope_id'               => 'focused-ready',
                    'proven_leverage_tier'   => 3,
                    'autonomy_readiness_tier'=> 3,
                    'proof_cost'             => 2,
                    'isolation'              => 8,
                    'risk'                   => 2,
                ]),
            ],
            'risk_budget' => ['max_risk' => 10],
        ]);

        $order = array_column($out['accepted_candidates'], 'scope_id');
        $this->assertSame(['focused-ready', 'broad-unready'], $order);

        $top = $out['accepted_candidates'][0];
        $this->assertArrayHasKey('score_components', $top);
        $this->assertSame(3, $top['score_components']['leverage']);
        $this->assertSame(3, $top['score_components']['readiness']);
        $this->assertSame(2, $top['score_components']['proof_cost']);
        $this->assertSame(8, $top['score_components']['isolation']);
        $this->assertSame(2, $top['score_components']['risk']);
    }

    public function test_proof_cost_breaks_tie_when_leverage_and_readiness_equal(): void
    {
        $ranker = new AtlasSelfConstructionScopeExpansionCandidateRanker;
        $out = $ranker->rank([
            'candidates' => [
                $this->goodCandidate(['scope_id' => 'expensive', 'proven_leverage_tier' => 3, 'autonomy_readiness_tier' => 3, 'proof_cost' => 7, 'risk' => 3]),
                $this->goodCandidate(['scope_id' => 'cheap',    'proven_leverage_tier' => 3, 'autonomy_readiness_tier' => 3, 'proof_cost' => 2, 'risk' => 3]),
            ],
            'risk_budget' => ['max_risk' => 10],
        ]);

        $order = array_column($out['accepted_candidates'], 'scope_id');
        $this->assertSame(['cheap', 'expensive'], $order);
    }

    public function test_isolation_breaks_tie_when_leverage_readiness_and_proof_cost_equal(): void
    {
        $ranker = new AtlasSelfConstructionScopeExpansionCandidateRanker;
        $out = $ranker->rank([
            'candidates' => [
                $this->goodCandidate(['scope_id' => 'low-iso',  'proven_leverage_tier' => 3, 'autonomy_readiness_tier' => 3, 'proof_cost' => 4, 'isolation' => 2, 'risk' => 3]),
                $this->goodCandidate(['scope_id' => 'high-iso', 'proven_leverage_tier' => 3, 'autonomy_readiness_tier' => 3, 'proof_cost' => 4, 'isolation' => 9, 'risk' => 3]),
            ],
            'risk_budget' => ['max_risk' => 10],
        ]);

        $order = array_column($out['accepted_candidates'], 'scope_id');
        $this->assertSame(['high-iso', 'low-iso'], $order);
    }

    // ── hype_only + structural_leverage_refs ──────────────────────────────────

    public function test_hype_only_candidate_is_rejected_with_named_reason(): void
    {
        $out = (new AtlasSelfConstructionScopeExpansionCandidateRanker)->rank([
            'candidates' => [$this->goodCandidate(['scope_id' => 'h', 'hype_only' => true])],
            'risk_budget' => ['max_risk' => 10],
        ]);

        $this->assertSame([], $out['accepted_candidates']);
        $this->assertContains(
            AtlasSelfConstructionScopeExpansionCandidateRanker::REASON_HYPE_ONLY,
            $out['rejected_candidates'][0]['reasons'],
        );
    }

    public function test_structural_leverage_refs_present_but_empty_is_rejected(): void
    {
        $out = (new AtlasSelfConstructionScopeExpansionCandidateRanker)->rank([
            'candidates' => [$this->goodCandidate(['scope_id' => 's', 'structural_leverage_refs' => []])],
            'risk_budget' => ['max_risk' => 10],
        ]);

        $this->assertSame([], $out['accepted_candidates']);
        $this->assertContains(
            AtlasSelfConstructionScopeExpansionCandidateRanker::REASON_MISSING_STRUCTURAL_LEVERAGE_REFS,
            $out['rejected_candidates'][0]['reasons'],
        );
    }

    public function test_structural_leverage_refs_absent_does_not_reject(): void
    {
        // goodCandidate() has no structural_leverage_refs key — must still be accepted.
        $out = (new AtlasSelfConstructionScopeExpansionCandidateRanker)->rank([
            'candidates' => [$this->goodCandidate()],
            'risk_budget' => ['max_risk' => 10],
        ]);

        $this->assertCount(1, $out['accepted_candidates']);
        $this->assertSame([], $out['rejected_candidates']);
    }

    public function test_structural_leverage_refs_non_empty_does_not_reject(): void
    {
        $out = (new AtlasSelfConstructionScopeExpansionCandidateRanker)->rank([
            'candidates' => [$this->goodCandidate(['structural_leverage_refs' => ['doc:lever.md']])],
            'risk_budget' => ['max_risk' => 10],
        ]);

        $this->assertCount(1, $out['accepted_candidates']);
    }

    // ── duplicate scope_id deduplication ─────────────────────────────────────

    public function test_duplicate_scope_ids_collapsed_keeping_best_ranked(): void
    {
        // Two candidates with the same scope_id; the higher-leverage one ranks first → is kept.
        $out = (new AtlasSelfConstructionScopeExpansionCandidateRanker)->rank([
            'candidates' => [
                $this->goodCandidate(['scope_id' => 'dup', 'proven_leverage_tier' => 1]),
                $this->goodCandidate(['scope_id' => 'dup', 'proven_leverage_tier' => 3]),
            ],
            'risk_budget' => ['max_risk' => 10],
        ]);

        $this->assertCount(1, $out['accepted_candidates']);
        $this->assertSame('dup', $out['accepted_candidates'][0]['scope_id']);
        // Higher leverage wins (tier 3 ranks first, tier 1 duplicate gets rejected).
        $this->assertSame(3, $out['accepted_candidates'][0]['proven_leverage_tier']);
        $rejectedReasons = array_column($out['rejected_candidates'], 'reasons');
        $this->assertContains(
            [AtlasSelfConstructionScopeExpansionCandidateRanker::REASON_DUPLICATE_SCOPE_ID],
            $rejectedReasons,
        );
    }

    public function test_accepted_list_never_contains_duplicate_scope_ids(): void
    {
        $out = (new AtlasSelfConstructionScopeExpansionCandidateRanker)->rank([
            'candidates' => [
                $this->goodCandidate(['scope_id' => 'x']),
                $this->goodCandidate(['scope_id' => 'x']),
                $this->goodCandidate(['scope_id' => 'x']),
            ],
            'risk_budget' => ['max_risk' => 10],
        ]);

        $acceptedIds = array_column($out['accepted_candidates'], 'scope_id');
        $this->assertSame(array_unique($acceptedIds), $acceptedIds);
        $this->assertCount(1, $out['accepted_candidates']);
    }

    // ── decision_facts ────────────────────────────────────────────────────────

    public function test_accepted_candidates_have_decision_facts_with_rank_and_dimensions(): void
    {
        $out = (new AtlasSelfConstructionScopeExpansionCandidateRanker)->rank([
            'candidates' => [
                $this->goodCandidate(['scope_id' => 'a', 'proven_leverage_tier' => 3]),
                $this->goodCandidate(['scope_id' => 'b', 'proven_leverage_tier' => 1]),
            ],
            'risk_budget' => ['max_risk' => 10],
        ]);

        $this->assertCount(2, $out['accepted_candidates']);
        $top = $out['accepted_candidates'][0];
        $this->assertArrayHasKey('decision_facts', $top);
        $this->assertSame(1, $top['decision_facts']['rank']);
        $this->assertTrue($top['decision_facts']['accepted']);
        $this->assertIsArray($top['decision_facts']['sort_dimensions']);
        $this->assertNotEmpty($top['decision_facts']['sort_dimensions']);

        $second = $out['accepted_candidates'][1];
        $this->assertSame(2, $second['decision_facts']['rank']);
    }
}
