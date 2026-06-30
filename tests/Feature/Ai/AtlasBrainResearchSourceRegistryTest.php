<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainResearchSourceRegistry;
use PHPUnit\Framework\TestCase;

final class AtlasBrainResearchSourceRegistryTest extends TestCase
{
    private AtlasBrainResearchSourceRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new AtlasBrainResearchSourceRegistry;
    }

    // ── AC2: default sources cover all three tiers with non-empty cadence ─────

    public function test_default_sources_include_discover_tier(): void
    {
        $discover = $this->registry->forTier('discover');

        $this->assertNotEmpty($discover);
    }

    public function test_default_sources_include_read_tier(): void
    {
        $read = $this->registry->forTier('read');

        $this->assertNotEmpty($read);
    }

    public function test_default_sources_include_ground_tier(): void
    {
        $ground = $this->registry->forTier('ground');

        $this->assertNotEmpty($ground);
    }

    public function test_all_default_sources_have_non_empty_cadence(): void
    {
        foreach ($this->registry->sources() as $src) {
            $this->assertNotEmpty($src['cadence'], "cadence empty for {$src['url_pattern']}");
        }
    }

    // ── AC3: invalid sources are dropped by validation ────────────────────────

    public function test_empty_url_pattern_is_dropped(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry([
            ['url_pattern' => '', 'tier' => 'discover', 'cadence' => 'weekly'],
        ]);

        $this->assertEmpty($reg->sources());
    }

    public function test_unsupported_tier_is_dropped(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry([
            ['url_pattern' => 'https://example.com/', 'tier' => 'invalid_tier', 'cadence' => 'weekly'],
        ]);

        $this->assertEmpty($reg->sources());
    }

    public function test_valid_source_is_kept(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry([
            ['url_pattern' => 'https://example.com/', 'tier' => 'read', 'cadence' => 'daily'],
        ]);

        $this->assertCount(1, $reg->sources());
        $this->assertSame('https://example.com/', $reg->sources()[0]['url_pattern']);
    }

    // ── AC4: forTier returns only matching entries; empty for unknown tier ────

    public function test_for_tier_returns_only_matching_entries(): void
    {
        $reg = new AtlasBrainResearchSourceRegistry([
            ['url_pattern' => 'https://a.com/', 'tier' => 'discover', 'cadence' => 'weekly'],
            ['url_pattern' => 'https://b.com/', 'tier' => 'read', 'cadence' => 'on_demand'],
        ]);

        $discover = $reg->forTier('discover');

        $this->assertCount(1, $discover);
        $this->assertSame('https://a.com/', $discover[0]['url_pattern']);
    }

    public function test_for_unknown_tier_returns_empty(): void
    {
        $this->assertEmpty($this->registry->forTier('unknown_tier'));
    }

    public function test_for_tier_does_not_modify_internal_sources(): void
    {
        $before = count($this->registry->sources());
        $this->registry->forTier('discover');
        $after = count($this->registry->sources());

        $this->assertSame($before, $after);
    }

    // ── no network or provider calls (structural) ─────────────────────────────

    public function test_sources_are_deterministic(): void
    {
        $a = (new AtlasBrainResearchSourceRegistry)->sources();
        $b = (new AtlasBrainResearchSourceRegistry)->sources();

        $this->assertSame($a, $b);
    }
}
