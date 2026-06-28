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
}
