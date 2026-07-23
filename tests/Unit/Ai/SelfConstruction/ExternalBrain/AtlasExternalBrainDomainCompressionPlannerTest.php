<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDomainCompressionPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDomainCompressionPlannerTest extends TestCase
{
    private function svc(): AtlasExternalBrainDomainCompressionPlanner
    {
        return new AtlasExternalBrainDomainCompressionPlanner;
    }

    private function matureDomain(array $overrides = []): array
    {
        return array_merge([
            'domain_id' => 'domain-a',
            'entropy_score' => 0.5,
            'maturity' => 'mature',
            'risk_level' => 'low',
            'capability_owned' => true,
            'proof_debt_count' => 0,
            'expected_line_reduction' => 100,
        ], $overrides);
    }

    // ── AC: domain_ranking_case ────────────────────────────────────────────────

    public function test_domain_ranking_case_orders_domains_by_score_descending(): void
    {
        $r = $this->svc()->plan(['domains' => [
            $this->matureDomain(['domain_id' => 'low-value', 'entropy_score' => 0.1, 'expected_line_reduction' => 10, 'capability_owned' => false]),
            $this->matureDomain(['domain_id' => 'high-value', 'entropy_score' => 0.9, 'expected_line_reduction' => 500]),
        ]]);

        $ids = array_column($r['domains'], 'domain_id');
        $this->assertSame(['high-value', 'low-value'], $ids);
        $this->assertGreaterThan($r['domains'][1]['score'], $r['domains'][0]['score']);
    }

    public function test_domain_ranking_uses_entropy_maturity_ownership_proof_debt_and_line_reduction(): void
    {
        // Two domains identical except proof_debt_count -- higher debt must rank lower.
        $r = $this->svc()->plan(['domains' => [
            $this->matureDomain(['domain_id' => 'clean', 'proof_debt_count' => 0]),
            $this->matureDomain(['domain_id' => 'indebted', 'proof_debt_count' => 5]),
        ]]);

        $byId = [];
        foreach ($r['domains'] as $d) {
            $byId[$d['domain_id']] = $d;
        }
        $this->assertGreaterThan($byId['indebted']['score'], $byId['clean']['score']);
    }

    public function test_ties_broken_by_domain_id_ascending(): void
    {
        $r = $this->svc()->plan(['domains' => [
            $this->matureDomain(['domain_id' => 'zeta']),
            $this->matureDomain(['domain_id' => 'alpha']),
        ]]);

        $this->assertSame(['alpha', 'zeta'], array_column($r['domains'], 'domain_id'));
    }

    // ── AC: immature_domain_proof_first_case ──────────────────────────────────

    public function test_immature_domain_proof_first_case(): void
    {
        $r = $this->svc()->plan(['domains' => [
            $this->matureDomain(['domain_id' => 'young', 'maturity' => 'immature', 'entropy_score' => 0.95]),
        ]]);

        $this->assertSame(AtlasExternalBrainDomainCompressionPlanner::PLAN_PROOF_FIRST, $r['domains'][0]['plan_type']);
        $this->assertContains('domain_immature', $r['domains'][0]['reasons']);
        $this->assertSame(1, $r['summary']['proof_first_count']);
        $this->assertSame(0, $r['summary']['compress_count']);
    }

    public function test_high_risk_domain_gets_proof_first_even_when_mature(): void
    {
        $r = $this->svc()->plan(['domains' => [
            $this->matureDomain(['domain_id' => 'risky', 'maturity' => 'mature', 'risk_level' => 'high']),
        ]]);

        $this->assertSame(AtlasExternalBrainDomainCompressionPlanner::PLAN_PROOF_FIRST, $r['domains'][0]['plan_type']);
        $this->assertContains('domain_high_risk', $r['domains'][0]['reasons']);
    }

    public function test_immature_domain_can_still_rank_first_despite_proof_first_plan_type(): void
    {
        // High entropy/line-reduction immature domain still surfaces at the top of the ranking --
        // it just gets proof_first instead of direct compression.
        $r = $this->svc()->plan(['domains' => [
            $this->matureDomain(['domain_id' => 'low-value-mature', 'entropy_score' => 0.1, 'expected_line_reduction' => 10]),
            $this->matureDomain(['domain_id' => 'high-value-immature', 'maturity' => 'immature', 'entropy_score' => 1.0, 'expected_line_reduction' => 500]),
        ]]);

        $this->assertSame('high-value-immature', $r['domains'][0]['domain_id']);
        $this->assertSame(AtlasExternalBrainDomainCompressionPlanner::PLAN_PROOF_FIRST, $r['domains'][0]['plan_type']);
    }

    public function test_mature_low_risk_domain_gets_compress_plan(): void
    {
        $r = $this->svc()->plan(['domains' => [$this->matureDomain()]]);

        $this->assertSame(AtlasExternalBrainDomainCompressionPlanner::PLAN_COMPRESS, $r['domains'][0]['plan_type']);
        $this->assertContains('domain_ready_for_compression', $r['domains'][0]['reasons']);
    }

    public function test_maturing_domain_with_low_risk_gets_compress_plan(): void
    {
        $r = $this->svc()->plan(['domains' => [$this->matureDomain(['maturity' => 'maturing'])]]);

        $this->assertSame(AtlasExternalBrainDomainCompressionPlanner::PLAN_COMPRESS, $r['domains'][0]['plan_type']);
    }

    // ── unknown maturity defaults safely to immature (proof_first) ───────────

    public function test_unknown_maturity_defaults_to_immature(): void
    {
        $r = $this->svc()->plan(['domains' => [$this->matureDomain(['maturity' => 'nonsense'])]]);

        $this->assertSame('immature', $r['domains'][0]['maturity']);
        $this->assertSame(AtlasExternalBrainDomainCompressionPlanner::PLAN_PROOF_FIRST, $r['domains'][0]['plan_type']);
    }

    // ── malformed / empty ──────────────────────────────────────────────────────

    public function test_malformed_domain_entry_is_skipped(): void
    {
        $r = $this->svc()->plan(['domains' => ['not-an-array', $this->matureDomain()]]);

        $this->assertCount(1, $r['domains']);
    }

    public function test_empty_domains_yields_empty_plan(): void
    {
        $r = $this->svc()->plan(['domains' => []]);

        $this->assertSame([], $r['domains']);
        $this->assertSame(0, $r['summary']['total']);
    }

    // ── schema / determinism ───────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->plan(['domains' => []]);

        $this->assertSame(AtlasExternalBrainDomainCompressionPlanner::SCHEMA, $r['schema']);
    }

    public function test_plan_is_deterministic(): void
    {
        $input = ['domains' => [$this->matureDomain(), $this->matureDomain(['domain_id' => 'b', 'maturity' => 'immature'])]];

        $this->assertSame(
            $this->svc()->plan($input),
            $this->svc()->plan($input),
        );
    }
}
