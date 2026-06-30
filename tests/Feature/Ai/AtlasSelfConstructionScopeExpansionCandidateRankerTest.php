<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ScopeExpansion\AtlasSelfConstructionScopeExpansionCandidateRanker;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionScopeExpansionCandidateRankerTest extends TestCase
{
    private AtlasSelfConstructionScopeExpansionCandidateRanker $ranker;

    protected function setUp(): void
    {
        $this->ranker = new AtlasSelfConstructionScopeExpansionCandidateRanker;
    }

    private function rank(array $candidates, array $budget = ['max_risk' => 5]): array
    {
        return $this->ranker->rank(['candidates' => $candidates, 'risk_budget' => $budget]);
    }

    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'scope_id'                   => 'scope-a',
            'evidence_refs'              => ['ref-1'],
            'atlas_native_owner'         => true,
            'requires_operator'          => false,
            'requires_human'             => false,
            'requires_external_provider' => false,
            'proven_leverage_tier'       => 3,
            'autonomy_readiness_tier'    => 3,
            'risk'                       => 2,
        ], $overrides);
    }

    // ── AC2: missing evidence_refs / structural_leverage_refs / required facts ─

    public function test_missing_evidence_refs_is_rejected(): void
    {
        $r = $this->rank([$this->candidate(['evidence_refs' => []])]);

        $this->assertCount(1, $r['rejected_candidates']);
        $this->assertContains(
            AtlasSelfConstructionScopeExpansionCandidateRanker::REASON_MISSING_EVIDENCE_REFS,
            $r['rejected_candidates'][0]['reasons'],
        );
    }

    public function test_empty_structural_leverage_refs_is_rejected(): void
    {
        $r = $this->rank([$this->candidate(['structural_leverage_refs' => []])]);

        $reasons = $r['rejected_candidates'][0]['reasons'];
        $this->assertContains(
            AtlasSelfConstructionScopeExpansionCandidateRanker::REASON_MISSING_STRUCTURAL_LEVERAGE_REFS,
            $reasons,
        );
    }

    public function test_missing_required_key_is_rejected(): void
    {
        $c = $this->candidate();
        unset($c['risk']);

        $r = $this->rank([$c]);

        $this->assertCount(1, $r['rejected_candidates']);
        $this->assertContains(
            AtlasSelfConstructionScopeExpansionCandidateRanker::REASON_MISSING_REQUIRED_FACT,
            $r['rejected_candidates'][0]['reasons'],
        );
    }

    public function test_scalar_only_proxy_is_rejected(): void
    {
        $r = $this->rank([$this->candidate(['scalar_only_proxy' => true])]);

        $this->assertContains(
            AtlasSelfConstructionScopeExpansionCandidateRanker::REASON_SCALAR_ONLY_PROXY,
            $r['rejected_candidates'][0]['reasons'],
        );
    }

    // ── AC3: operator / human / provider / non-atlas rejected before ranking ──

    public function test_requires_operator_is_rejected(): void
    {
        $r = $this->rank([$this->candidate(['requires_operator' => true])]);

        $this->assertContains(
            AtlasSelfConstructionScopeExpansionCandidateRanker::REASON_OPERATOR_DEPENDENCY,
            $r['rejected_candidates'][0]['reasons'],
        );
    }

    public function test_requires_human_is_rejected(): void
    {
        $r = $this->rank([$this->candidate(['requires_human' => true])]);

        $this->assertContains(
            AtlasSelfConstructionScopeExpansionCandidateRanker::REASON_HUMAN_DEPENDENCY,
            $r['rejected_candidates'][0]['reasons'],
        );
    }

    public function test_requires_external_provider_is_rejected(): void
    {
        $r = $this->rank([$this->candidate(['requires_external_provider' => true])]);

        $this->assertContains(
            AtlasSelfConstructionScopeExpansionCandidateRanker::REASON_EXTERNAL_PROVIDER_DEPENDENCY,
            $r['rejected_candidates'][0]['reasons'],
        );
    }

    public function test_non_atlas_native_owner_is_rejected(): void
    {
        $r = $this->rank([$this->candidate(['atlas_native_owner' => false])]);

        $this->assertContains(
            AtlasSelfConstructionScopeExpansionCandidateRanker::REASON_NON_ATLAS_NATIVE_OWNER,
            $r['rejected_candidates'][0]['reasons'],
        );
    }

    public function test_risk_exceeds_budget_is_rejected(): void
    {
        $r = $this->rank([$this->candidate(['risk' => 10])], ['max_risk' => 5]);

        $this->assertContains(
            AtlasSelfConstructionScopeExpansionCandidateRanker::REASON_RISK_BUDGET_EXCEEDED,
            $r['rejected_candidates'][0]['reasons'],
        );
    }

    // ── AC4: dedup by scope_id, sort by leverage → readiness → risk → scope_id

    public function test_higher_leverage_tier_ranks_first(): void
    {
        $r = $this->rank([
            $this->candidate(['scope_id' => 'low', 'proven_leverage_tier' => 1]),
            $this->candidate(['scope_id' => 'high', 'proven_leverage_tier' => 5]),
        ]);

        $this->assertSame('high', $r['accepted_candidates'][0]['scope_id']);
        $this->assertSame('low',  $r['accepted_candidates'][1]['scope_id']);
    }

    public function test_duplicate_scope_id_second_entry_is_rejected(): void
    {
        $r = $this->rank([
            $this->candidate(['scope_id' => 'dup', 'proven_leverage_tier' => 3]),
            $this->candidate(['scope_id' => 'dup', 'proven_leverage_tier' => 1]),
        ]);

        $this->assertCount(1, $r['accepted_candidates']);
        $this->assertSame('dup', $r['accepted_candidates'][0]['scope_id']);

        $dupRejected = array_filter(
            $r['rejected_candidates'],
            fn($c) => $c['scope_id'] === 'dup'
                   && in_array(AtlasSelfConstructionScopeExpansionCandidateRanker::REASON_DUPLICATE_SCOPE_ID, $c['reasons'], true),
        );
        $this->assertCount(1, $dupRejected);
    }

    public function test_accepted_candidate_has_score_components(): void
    {
        $r = $this->rank([$this->candidate()]);

        $this->assertArrayHasKey('score_components', $r['accepted_candidates'][0]);
        $this->assertArrayHasKey('leverage', $r['accepted_candidates'][0]['score_components']);
    }

    // ── deterministic ─────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $candidates = [$this->candidate(), $this->candidate(['scope_id' => 'scope-b', 'proven_leverage_tier' => 5])];

        $a = $this->rank($candidates);
        $b = $this->rank($candidates);

        $this->assertSame($a['ranker_hash'], $b['ranker_hash']);
    }

    public function test_schema_is_set(): void
    {
        $r = $this->rank([]);

        $this->assertSame(AtlasSelfConstructionScopeExpansionCandidateRanker::SCHEMA, $r['schema_version']);
    }
}
