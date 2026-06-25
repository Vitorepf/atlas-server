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
}
