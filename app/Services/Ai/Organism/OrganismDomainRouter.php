<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;

/**
 * AOBG N4.F3 — the per-node DOMAIN ROUTER (the cross-domain mission's routing seam).
 *
 * N4.F3 is THE CROSS-DOMAIN MISSION SPINE: an operator intent SPANS domains. The mission
 * service reuses the N3 plan-DAG decomposition to break the intent into nodes, then asks
 * THIS router which canonical domain each node belongs to — finance, marketing, cyber, … —
 * so the node can be routed to the right {@see DomainProposer} via the
 * {@see AtlasOrganismRegistry}.
 *
 * COST: deterministic + cost-free. It is keyword-over-the-node-text routing that resolves
 * to a canonical domain via {@see CrossDomainTaxonomyMap} (the M-8 reconciled 21-domain
 * superset). It NEVER calls a provider, never reaches a network. A future provider-backed
 * router (richer NL classification) would sit behind the SAME interface, GATED + stubbed —
 * the mission service only consumes a canonical domain id, never knows how it was derived.
 *
 * NEVER INVENT A DOMAIN: every routed id is resolved through the taxonomy; an unroutable
 * node falls back to a single configurable default domain (the mission's anchor / engineering)
 * rather than guessing. The router returns a canonical id the registry can resolve OR the
 * fallback — it does not fabricate a non-canonical label.
 */
final class OrganismDomainRouter
{
    /**
     * Keyword → canonical-domain hints. Ordered most-specific first; the first canonical
     * domain whose any keyword appears in the node text wins. Every value is a CANONICAL id
     * from {@see CrossDomainTaxonomyMap} (asserted at construction). Provider-safe, pure data.
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        // sensitive domains first (their crossing is the most consequential to route right).
        // NB: keywords are matched on WORD BOUNDARIES (see route()), so "market" does NOT
        // match "marketing" — finance is for markets/trading, not the marketing domain.
        'finance' => ['finance', 'financial', 'trade', 'trading', 'portfolio', 'sharpe', 'btc', 'eth', 'crypto', 'market', 'markets', 'hedge', 'invest', 'investment', 'pnl'],
        'health' => ['health', 'medical', 'clinical', 'patient', 'diagnosis', 'symptom'],
        'cyber' => ['cyber', 'security', 'vulnerability', 'exploit', 'pentest', 'threat', 'malware', 'cve'],
        'legal' => ['legal', 'contract', 'compliance', 'gdpr', 'lgpd', 'lawsuit', 'regulatory'],
        'personal' => ['personal', 'diary'],
        // non-sensitive
        'marketing' => ['marketing', 'campaign', 'campaigns', 'ad', 'ads', 'advert', 'advertising', 'audience', 'brand', 'seo', 'funnel', 'copywriting'],
        'sales' => ['sales', 'sell', 'lead', 'leads', 'pipeline', 'crm', 'quota', 'prospect'],
        'design' => ['design', 'ux', 'ui', 'mockup', 'wireframe', 'prototype', 'figma'],
        'research' => ['research', 'experiment', 'hypothesis'],
        'learning' => ['learn', 'learning', 'course', 'tutorial', 'curriculum'],
        'governance' => ['governance', 'policy', 'oversight', 'approval'],
        'infra' => ['infra', 'infrastructure', 'kubernetes', 'terraform', 'provisioning', 'cluster'],
        'ops' => ['operations', 'oncall', 'incident', 'runbook', 'sre'],
        'qa' => ['qa', 'quality assurance'],
        'writing' => ['writing', 'blog', 'article', 'documentation', 'docs'],
        'engineering' => ['engineering', 'code', 'refactor', 'service', 'api', 'migration', 'class', 'function', 'deploy', 'bug', 'feature', 'implement', 'build'],
    ];

    public function __construct(private readonly CrossDomainTaxonomyMap $taxonomy = new CrossDomainTaxonomyMap) {}

    /**
     * Route a node's request text to its canonical domain.
     *
     * @param  string  $nodeText  the node's request/title text (provider-safe label)
     * @param  string  $fallbackDomain  canonical domain id to use when nothing matches
     * @return array{domain:string, matched:bool, by:string} the resolved canonical domain,
     *         whether a keyword matched (vs the fallback), and the keyword/'fallback' that
     *         decided it — recorded so a reader knows HOW the node was routed.
     */
    public function route(string $nodeText, string $fallbackDomain = 'engineering'): array
    {
        $text = mb_strtolower(trim($nodeText));
        $fallback = $this->taxonomy->canonical($fallbackDomain) ?? 'engineering';

        if ($text !== '') {
            foreach (self::KEYWORDS as $canonical => $keywords) {
                // Only consider canonical ids that actually resolve (defensive; the table is
                // canonical by construction, but never trust a typo into a fabricated domain).
                if ($this->taxonomy->canonical($canonical) === null) {
                    continue;
                }
                foreach ($keywords as $kw) {
                    if ($this->matchesWord($text, mb_strtolower($kw))) {
                        return ['domain' => $canonical, 'matched' => true, 'by' => $kw];
                    }
                }
            }
        }

        return ['domain' => $fallback, 'matched' => false, 'by' => 'fallback'];
    }

    /**
     * WORD-BOUNDARY match: the keyword (which may be a multi-word phrase) must appear as whole
     * word(s) in the text, so "market" does NOT match "marketing" and "ad" does NOT match
     * "ready". Deterministic, pure-string (no provider). Falls back to substring on a regex
     * compile fault (never throws — routing must never break a mission).
     */
    private function matchesWord(string $text, string $keyword): bool
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return false;
        }
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($keyword, '/').'(?![\p{L}\p{N}])/u';
        $res = @preg_match($pattern, $text);
        if ($res === false) {
            return mb_strpos($text, $keyword) !== false;
        }

        return $res === 1;
    }
}
