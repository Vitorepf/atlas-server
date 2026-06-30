<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainComprehensionDeepeningMap;
use Tests\TestCase;

final class AtlasExternalBrainComprehensionDeepeningMapTest extends TestCase
{
    private function svc(): AtlasExternalBrainComprehensionDeepeningMap
    {
        return new AtlasExternalBrainComprehensionDeepeningMap;
    }

    private function domain(string $id, array $overrides = []): array
    {
        return $overrides + [
            'domain_id' => $id,
            'has_owner_docs' => true,
            'context_pack_age_days' => 5,
            'code_coverage' => 80.0,
            'unresolved_contradictions' => 0,
            'recent_failed_assumptions' => 0,
        ];
    }

    private function domainFor(array $result, string $id): ?array
    {
        foreach ($result['ranked_domains'] as $d) {
            if ($d['domain_id'] === $id) {
                return $d;
            }
        }

        return null;
    }

    // ── context levels ────────────────────────────────────────────────────────

    public function test_clean_domain_is_adequate_context(): void
    {
        $r = $this->svc()->map(['domains' => [$this->domain('good')]]);

        $d = $this->domainFor($r, 'good');
        $this->assertSame('adequate_context', $d['context_level']);
        $this->assertSame(0, $d['risk_score']);
    }

    public function test_missing_owner_docs_gives_shallow_context(): void
    {
        $r = $this->svc()->map([
            'domains' => [$this->domain('shallow', ['has_owner_docs' => false, 'context_pack_age_days' => 35])],
        ]);

        $d = $this->domainFor($r, 'shallow');
        $this->assertSame('shallow_context', $d['context_level']);
        $this->assertGreaterThanOrEqual(AtlasExternalBrainComprehensionDeepeningMap::SHALLOW_THRESHOLD, $d['risk_score']);
    }

    public function test_stale_context_pack_contributes_risk(): void
    {
        $r = $this->svc()->map([
            'domains' => [$this->domain('stale', ['context_pack_age_days' => 60])],
        ]);

        $d = $this->domainFor($r, 'stale');
        $this->assertSame(2, $d['risk_score']);
        $this->assertContains('stale_context_pack', $d['risk_signals']);
        $this->assertSame('adequate_context', $d['context_level']);
    }

    public function test_stale_plus_missing_docs_gives_shallow(): void
    {
        // missing docs (+3) + stale pack (+2) = 5 = shallow
        $r = $this->svc()->map([
            'domains' => [$this->domain('both', ['has_owner_docs' => false, 'context_pack_age_days' => 40])],
        ]);

        $d = $this->domainFor($r, 'both');
        $this->assertSame('shallow_context', $d['context_level']);
        $this->assertSame(5, $d['risk_score']);
    }

    public function test_low_code_coverage_contributes_risk(): void
    {
        $r = $this->svc()->map([
            'domains' => [$this->domain('low-cov', ['code_coverage' => 30.0])],
        ]);

        $d = $this->domainFor($r, 'low-cov');
        $this->assertContains('low_code_coverage', $d['risk_signals']);
        $this->assertSame(2, $d['risk_score']);
    }

    // ── architecture blocking ─────────────────────────────────────────────────

    public function test_shallow_domain_blocks_architecture_target(): void
    {
        $r = $this->svc()->map([
            'domains' => [$this->domain('loop', ['has_owner_docs' => false, 'context_pack_age_days' => 40])],
            'architecture_targets' => ['loop'],
        ]);

        $this->assertContains('loop', $r['blocked_architecture_targets']);
    }

    public function test_adequate_domain_does_not_block_architecture_target(): void
    {
        $r = $this->svc()->map([
            'domains' => [$this->domain('good')],
            'architecture_targets' => ['good'],
        ]);

        $this->assertSame([], $r['blocked_architecture_targets']);
    }

    public function test_unknown_target_not_in_blocked(): void
    {
        // target not in domains → not blocked (no knowledge = not blocked, just unknown)
        $r = $this->svc()->map([
            'domains' => [$this->domain('good')],
            'architecture_targets' => ['unknown-domain'],
        ]);

        $this->assertSame([], $r['blocked_architecture_targets']);
    }

    // ── ranking ───────────────────────────────────────────────────────────────

    public function test_domains_ranked_by_risk_score_descending(): void
    {
        $r = $this->svc()->map([
            'domains' => [
                $this->domain('low'),
                $this->domain('high', ['has_owner_docs' => false, 'context_pack_age_days' => 40]),
                $this->domain('mid', ['context_pack_age_days' => 40, 'code_coverage' => 30.0]),
            ],
        ]);

        $scores = array_column($r['ranked_domains'], 'risk_score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
    }

    // ── next context actions ──────────────────────────────────────────────────

    public function test_next_context_actions_for_shallow_domain(): void
    {
        $r = $this->svc()->map([
            'domains' => [$this->domain('loop', ['has_owner_docs' => false, 'context_pack_age_days' => 40])],
        ]);

        $this->assertNotEmpty($r['next_context_actions']);
        $actions = $r['next_context_actions'][0]['actions'];
        $this->assertContains('read_owner_docs', $actions);
        $this->assertContains('refresh_context_pack', $actions);
    }

    public function test_no_domains_gives_empty_result(): void
    {
        $r = $this->svc()->map([]);

        $this->assertSame([], $r['ranked_domains']);
        $this->assertSame([], $r['blocked_architecture_targets']);
        $this->assertSame([], $r['next_context_actions']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->map([]);

        $this->assertSame(AtlasExternalBrainComprehensionDeepeningMap::SCHEMA, $r['schema_version']);
    }
}
