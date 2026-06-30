<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchSourceTrustRanker;
use Tests\TestCase;

final class AtlasExternalBrainResearchSourceTrustRankerTest extends TestCase
{
    // ── AC1: primary docs / repo-local rank above blog/summary ──────────────

    public function test_primary_documentation_with_claim_ranks_above_blog(): void
    {
        $ranker = new AtlasExternalBrainResearchSourceTrustRanker;

        $primary = $ranker->rank([
            'source_type' => 'primary_documentation',
            'has_concrete_claim' => true,
            'source_date' => '2026-06-01',
            'has_source_url' => true,
        ]);
        $blog = $ranker->rank([
            'source_type' => 'blog',
            'has_concrete_claim' => false,
            'source_date' => '2026-06-01',
            'has_source_url' => true,
        ]);

        $this->assertGreaterThan($blog['trust_score'], $primary['trust_score']);
        $this->assertSame(AtlasExternalBrainResearchSourceTrustRanker::TIER_HIGH, $primary['trust_tier']);
    }

    public function test_repo_local_evidence_ranks_above_generic_summary(): void
    {
        $ranker = new AtlasExternalBrainResearchSourceTrustRanker;

        $repo = $ranker->rank(['source_type' => 'repo_local_evidence', 'source_date' => '2026-06-01']);
        $summary = $ranker->rank(['source_type' => 'generic_summary', 'source_date' => '2026-06-01']);

        $this->assertGreaterThan($summary['trust_score'], $repo['trust_score']);
    }

    // ── AC2: stale/undated/hype downgraded, no adopt_directly ───────────────

    public function test_hype_heavy_input_is_downgraded(): void
    {
        $r = $this->ranker()->rank([
            'source_type' => 'primary_documentation',
            'is_hype_heavy' => true,
            'source_date' => '2026-06-01',
        ]);

        $this->assertContains('hype_heavy', $r['penalties']);
        $this->assertNotSame(AtlasExternalBrainResearchSourceTrustRanker::USE_ADOPT_DIRECTLY, $r['use_decision']);
    }

    public function test_undated_input_is_penalized(): void
    {
        $r = $this->ranker()->rank([
            'source_type' => 'measured_incident_report',
            'source_date' => '',
        ]);

        $this->assertContains('missing_source_date', $r['penalties']);
    }

    public function test_source_missing_input_rejected_or_downgraded(): void
    {
        $r = $this->ranker()->rank([
            'source_type' => 'generic_summary',
            'has_source_url' => false,
            'source_date' => '',
        ]);

        $this->assertContains('source_url_missing', $r['penalties']);
        $this->assertSame(AtlasExternalBrainResearchSourceTrustRanker::USE_REJECT, $r['use_decision']);
    }

    // ── AC3: output includes all required fields ────────────────────────────

    public function test_output_has_all_required_fields(): void
    {
        $r = $this->ranker()->rank(['source_type' => 'blog', 'source_date' => '2026-01-01']);

        foreach (['trust_score', 'trust_tier', 'use_decision', 'penalties', 'grounding_requirements'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
    }

    // ── AC4: deterministic ───────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $ranker = new AtlasExternalBrainResearchSourceTrustRanker;
        $input = ['source_type' => 'benchmarked_pattern', 'source_date' => '2026-06-01'];

        $this->assertSame(
            json_encode($ranker->rank($input)),
            json_encode($ranker->rank($input)),
        );
    }

    // ── adopt_directly for high-trust clean inputs ───────────────────────────

    public function test_clean_primary_doc_adopts_directly(): void
    {
        $r = $this->ranker()->rank([
            'source_type' => 'primary_documentation',
            'has_concrete_claim' => true,
            'source_date' => '2026-06-30',
            'has_source_url' => true,
            'grounding' => 'repo_verified',
        ]);

        $this->assertSame(AtlasExternalBrainResearchSourceTrustRanker::USE_ADOPT_DIRECTLY, $r['use_decision']);
    }

    private function ranker(): AtlasExternalBrainResearchSourceTrustRanker
    {
        return new AtlasExternalBrainResearchSourceTrustRanker;
    }

    private function rank(array $a): array { return $this->ranker()->rank($a); }
}
