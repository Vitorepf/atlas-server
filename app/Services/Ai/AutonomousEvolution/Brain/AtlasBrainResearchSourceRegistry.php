<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * FRONTIER-HARVEST source registry — the typed DATA substrate the frontier-harvest path consumes so the brain
 * has a PROACTIVE material supply instead of starving (grounded by the live signal: `atlas:brain:next
 * autonomous` returning refused/no_proposal repeatedly because origination had no proactive source). Encodes
 * the operator-seeded sources from docs/brain-research-source-registry.md as a validated, typed data set.
 *
 * Each entry is {url_pattern, tier ∈ discover|read|ground, cadence}. The 3-tier pipeline: DISCOVER what is
 * trending (trendshift) → READ the real repo (github) → GROUND in the paper (arxiv). Pure: no provider, no DB,
 * no network, no mutation, deterministic — it is data + validation only.
 */
final class AtlasBrainResearchSourceRegistry
{
    /** The closed tier enum; an entry with any other tier is dropped by validation. */
    public const TIERS = ['discover', 'read', 'ground'];

    /**
     * Operator-seeded defaults (docs/brain-research-source-registry.md): trendshift discover, github read,
     * arxiv ground.
     *
     * @var list<array{url_pattern:string, tier:string, cadence:string}>
     */
    private const DEFAULT_SOURCES = [
        ['url_pattern' => 'https://trendshift.io/', 'tier' => 'discover', 'cadence' => 'weekly'],
        ['url_pattern' => 'https://trendshift.io/yearly', 'tier' => 'discover', 'cadence' => 'monthly'],
        ['url_pattern' => 'https://trendshift.io/weekly', 'tier' => 'discover', 'cadence' => 'weekly'],
        ['url_pattern' => 'https://trendshift.io/topics/ai-agent', 'tier' => 'discover', 'cadence' => 'weekly'],
        ['url_pattern' => 'https://trendshift.io/github-trending-repositories?trending-limit=100', 'tier' => 'discover', 'cadence' => 'weekly'],
        ['url_pattern' => 'https://trendshift.io/trending/developers', 'tier' => 'discover', 'cadence' => 'monthly'],
        ['url_pattern' => 'https://github.com/*', 'tier' => 'read', 'cadence' => 'on_demand'],
        ['url_pattern' => 'https://arxiv.org/*', 'tier' => 'ground', 'cadence' => 'on_demand'],
    ];

    /** @var list<array{url_pattern:string, tier:string, cadence:string}> */
    private array $sources;

    /** @param  list<array<string,mixed>>|null  $sources  null ⇒ the operator-seeded defaults */
    public function __construct(?array $sources = null)
    {
        $this->sources = self::validate($sources ?? self::DEFAULT_SOURCES);
    }

    /** @return list<array{url_pattern:string, tier:string, cadence:string}> validated entries */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * Entries whose tier equals $tier — an empty list for an unknown tier.
     *
     * @return list<array{url_pattern:string, tier:string, cadence:string}>
     */
    public function forTier(string $tier): array
    {
        return array_values(array_filter($this->sources, static fn (array $s): bool => $s['tier'] === $tier));
    }

    /**
     * Drop any entry whose url_pattern is empty or whose tier is not one of the three enum values.
     *
     * @param  list<array<string,mixed>>  $raw
     * @return list<array{url_pattern:string, tier:string, cadence:string}>
     */
    private static function validate(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            $url = trim((string) ($entry['url_pattern'] ?? ''));
            $tier = (string) ($entry['tier'] ?? '');
            if ($url === '' || ! in_array($tier, self::TIERS, true)) {
                continue;
            }
            $cadence = trim((string) ($entry['cadence'] ?? '')) ?: 'on_demand';
            $out[] = ['url_pattern' => $url, 'tier' => $tier, 'cadence' => $cadence];
        }

        return $out;
    }
}
