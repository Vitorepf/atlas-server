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

    /** tier => trust_tier — grounded (paper) beats verified (real repo) beats exploratory (trend signal). */
    private const TIER_TRUST = [
        'ground' => 'grounded',
        'read' => 'verified',
        'discover' => 'exploratory',
    ];

    /** @var array<string,int> trust_tier => ordering rank, higher preferred first. */
    private const TRUST_RANK = ['grounded' => 2, 'verified' => 1, 'exploratory' => 0];

    /** @var array<string,list<string>> tier => the knowledge domains that tier is applicable to. */
    private const TIER_APPLICABILITY_DOMAINS = [
        'discover' => ['tooling_trends', 'ecosystem_scan'],
        'read' => ['implementation_patterns', 'real_code_evidence'],
        'ground' => ['research_grounding', 'theoretical_basis'],
    ];

    /** @var array<string,string> tier => the caution every source of that tier carries. */
    private const TIER_ANTI_HYPE_NOTE = [
        'discover' => 'a trending signal is not adoption proof — treat it as a lead to investigate, never as evidence alone',
        'read' => 'stars/forks are not a quality proof — verify tests, real usage and maintenance before trusting the pattern',
        'ground' => 'a paper claim is not automatically reproducible — check evidence and real-world replication before acting',
    ];

    /** @var array<string,int> cadence => days a source stays fresh before freshness demotes it to stale. */
    private const CADENCE_FRESH_WINDOW_DAYS = ['weekly' => 14, 'monthly' => 45, 'on_demand' => 365];

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
     * Provider-safe sources enriched with trust tier, freshness bucket, applicability domains and
     * an anti-hype note — all derived deterministically from the existing discover/read/ground
     * tier, so the originator can prefer grounded sources over generic inspiration without any
     * new per-source data entry.
     *
     * @param  array<string,int>  $lastVerifiedDaysAgoByUrl  url_pattern => days since last verified;
     *                                                         missing ⇒ freshness_bucket='unknown', never assumed fresh.
     * @return list<array{url_pattern:string, tier:string, cadence:string, trust_tier:string, freshness_bucket:string, applicability_domains:list<string>, anti_hype_note:string}>
     */
    public function sourcesEnriched(array $lastVerifiedDaysAgoByUrl = []): array
    {
        $enriched = [];
        foreach ($this->sources as $s) {
            $daysAgo = $lastVerifiedDaysAgoByUrl[$s['url_pattern']] ?? null;
            $window = self::CADENCE_FRESH_WINDOW_DAYS[$s['cadence']] ?? 365;
            $freshness = match (true) {
                $daysAgo === null => 'unknown',
                $daysAgo <= $window => 'fresh',
                default => 'stale',
            };

            $enriched[] = array_merge($s, [
                'trust_tier' => self::TIER_TRUST[$s['tier']] ?? 'unknown',
                'freshness_bucket' => $freshness,
                'applicability_domains' => self::TIER_APPLICABILITY_DOMAINS[$s['tier']] ?? [],
                'anti_hype_note' => self::TIER_ANTI_HYPE_NOTE[$s['tier']] ?? '',
            ]);
        }

        return $enriched;
    }

    /**
     * @param  array<string,int>  $lastVerifiedDaysAgoByUrl
     * @return list<array<string,mixed>>
     */
    public function enrichedForTier(string $tier, array $lastVerifiedDaysAgoByUrl = []): array
    {
        return array_values(array_filter(
            $this->sourcesEnriched($lastVerifiedDaysAgoByUrl),
            static fn (array $s): bool => $s['tier'] === $tier,
        ));
    }

    /**
     * @param  array<string,int>  $lastVerifiedDaysAgoByUrl
     * @return list<array<string,mixed>>
     */
    public function forDomain(string $domain, array $lastVerifiedDaysAgoByUrl = []): array
    {
        return array_values(array_filter(
            $this->sourcesEnriched($lastVerifiedDaysAgoByUrl),
            static fn (array $s): bool => in_array($domain, $s['applicability_domains'], true),
        ));
    }

    /**
     * Deterministically orders sources so grounded beats verified beats exploratory, and within
     * the same trust tier a confirmed-fresh source beats one that is stale OR has no freshness
     * evidence at all (unknown is never treated as good as confirmed-fresh).
     *
     * @param  array<string,int>  $lastVerifiedDaysAgoByUrl
     * @return list<array<string,mixed>>
     */
    public function preferredSources(array $lastVerifiedDaysAgoByUrl = []): array
    {
        $enriched = $this->sourcesEnriched($lastVerifiedDaysAgoByUrl);

        usort($enriched, static function (array $a, array $b): int {
            $trustCmp = (self::TRUST_RANK[$b['trust_tier']] ?? -1) <=> (self::TRUST_RANK[$a['trust_tier']] ?? -1);
            if ($trustCmp !== 0) {
                return $trustCmp;
            }
            $freshA = $a['freshness_bucket'] === 'fresh' ? 1 : 0;
            $freshB = $b['freshness_bucket'] === 'fresh' ? 1 : 0;
            $freshCmp = $freshB <=> $freshA;
            if ($freshCmp !== 0) {
                return $freshCmp;
            }

            return strcmp($a['url_pattern'], $b['url_pattern']);
        });

        return array_values($enriched);
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
