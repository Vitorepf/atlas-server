<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainResearchSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * FRONTIER-HARVEST source registry — the typed data substrate that gives the brain a proactive material supply
 * (kills the no_proposal starvation). Freezes: the operator-seeded sources, the tier query, and the validation
 * that drops malformed entries.
 */
final class AtlasBrainResearchSourceRegistryTest extends TestCase
{
    public function test_seeds_the_operator_sources_across_all_three_tiers(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry;
        $urls = array_column($reg->sources(), 'url_pattern');

        self::assertContains('https://trendshift.io/', $urls);
        self::assertContains('https://github.com/*', $urls);
        self::assertContains('https://arxiv.org/*', $urls);
        // every seeded entry is valid (non-empty url + tier in the enum)
        foreach ($reg->sources() as $s) {
            self::assertNotSame('', $s['url_pattern']);
            self::assertContains($s['tier'], AtlasBrainResearchSourceRegistry::TIERS);
            self::assertNotSame('', $s['cadence']);
        }
    }

    public function test_for_tier_partitions_discover_read_ground(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry;
        self::assertGreaterThanOrEqual(1, count($reg->forTier('discover')));
        self::assertSame(['https://github.com/*'], array_column($reg->forTier('read'), 'url_pattern'));
        self::assertSame(['https://arxiv.org/*'], array_column($reg->forTier('ground'), 'url_pattern'));
        foreach ($reg->forTier('discover') as $s) {
            self::assertSame('discover', $s['tier']);
        }
    }

    public function test_for_tier_returns_empty_list_for_unknown_tier(): void
    {
        self::assertSame([], (new AtlasBrainResearchSourceRegistry)->forTier('bogus'));
    }

    public function test_validation_drops_empty_url_and_invalid_tier(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry([
            ['url_pattern' => 'https://ok.example/', 'tier' => 'discover', 'cadence' => 'weekly'], // kept
            ['url_pattern' => '', 'tier' => 'discover', 'cadence' => 'weekly'],                    // dropped: empty url
            ['url_pattern' => 'https://bad.example/', 'tier' => 'sideways', 'cadence' => 'weekly'], // dropped: bad tier
        ]);
        self::assertSame(
            [['url_pattern' => 'https://ok.example/', 'tier' => 'discover', 'cadence' => 'weekly']],
            $reg->sources(),
        );
    }

    public function test_cadence_defaults_when_blank(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry([
            ['url_pattern' => 'https://x.example/', 'tier' => 'read', 'cadence' => ''],
        ]);
        self::assertSame('on_demand', $reg->sources()[0]['cadence']);
    }

    // ── AC: sourcesEnriched() carries trust tier, freshness, domains, anti-hype note ──

    public function test_enriched_sources_carry_trust_tier_freshness_domains_and_anti_hype_note(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry;
        $ground = $reg->enrichedForTier('ground')[0];

        self::assertSame('grounded', $ground['trust_tier']);
        self::assertArrayHasKey('freshness_bucket', $ground);
        self::assertNotEmpty($ground['applicability_domains']);
        self::assertNotEmpty($ground['anti_hype_note']);
    }

    public function test_trust_tier_ranks_grounded_above_verified_above_exploratory(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry;
        $byTier = [];
        foreach ($reg->sourcesEnriched() as $s) {
            $byTier[$s['tier']] = $s['trust_tier'];
        }

        self::assertSame('grounded', $byTier['ground']);
        self::assertSame('verified', $byTier['read']);
        self::assertSame('exploratory', $byTier['discover']);
    }

    // ── AC: tier filtering ───────────────────────────────────────────────────────

    public function test_enriched_for_tier_returns_only_matching_tier(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry;
        $read = $reg->enrichedForTier('read');

        self::assertNotEmpty($read);
        foreach ($read as $s) {
            self::assertSame('read', $s['tier']);
        }
    }

    // ── AC: stale source demotion ────────────────────────────────────────────────

    public function test_stale_source_is_demoted_below_fresh_source_of_same_trust_tier(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry([
            ['url_pattern' => 'https://fresh.example/a', 'tier' => 'read', 'cadence' => 'on_demand'],
            ['url_pattern' => 'https://stale.example/b', 'tier' => 'read', 'cadence' => 'on_demand'],
        ]);

        $preferred = $reg->preferredSources([
            'https://fresh.example/a' => 1,
            'https://stale.example/b' => 999,
        ]);

        self::assertSame('https://fresh.example/a', $preferred[0]['url_pattern']);
        self::assertSame('fresh', $preferred[0]['freshness_bucket']);
        self::assertSame('stale', $preferred[1]['freshness_bucket']);
    }

    public function test_missing_freshness_evidence_is_unknown_not_assumed_fresh(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry([
            ['url_pattern' => 'https://x.example/', 'tier' => 'discover', 'cadence' => 'weekly'],
        ]);

        $enriched = $reg->sourcesEnriched();

        self::assertSame('unknown', $enriched[0]['freshness_bucket']);
    }

    public function test_grounded_source_outranks_fresh_exploratory_source(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry([
            ['url_pattern' => 'https://trend.example/', 'tier' => 'discover', 'cadence' => 'weekly'],
            ['url_pattern' => 'https://paper.example/', 'tier' => 'ground', 'cadence' => 'on_demand'],
        ]);

        $preferred = $reg->preferredSources(['https://trend.example/' => 1]);

        self::assertSame('https://paper.example/', $preferred[0]['url_pattern'], 'grounded always outranks exploratory, fresh or not');
    }

    // ── AC: domain filtering ──────────────────────────────────────────────────────

    public function test_for_domain_returns_only_sources_applicable_to_that_domain(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry;
        $researchGrounding = $reg->forDomain('research_grounding');

        self::assertNotEmpty($researchGrounding);
        foreach ($researchGrounding as $s) {
            self::assertContains('research_grounding', $s['applicability_domains']);
        }
    }

    public function test_for_unknown_domain_returns_empty(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry;

        self::assertSame([], $reg->forDomain('not_a_real_domain'));
    }

    // ── AC: deterministic ordering ────────────────────────────────────────────────

    public function test_preferred_sources_ordering_is_deterministic(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry;
        $a = $reg->preferredSources(['https://github.com/*' => 1, 'https://arxiv.org/*' => 1]);
        $b = $reg->preferredSources(['https://github.com/*' => 1, 'https://arxiv.org/*' => 1]);

        self::assertSame($a, $b);
    }
}
