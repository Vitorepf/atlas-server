<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Domain Plane decider.
 *
 * Pure, deterministic runtime for the Domain Plane — the lateral plane of
 * cognitive domains that feeds Domain Profile Flow. The plane is NOT a router
 * (that is Domain Routing Governance) and NOT a provider/model picker. It is a
 * catalog: it exposes the selectable domains and, for a selected domain, the
 * operational profile (activated capabilities + limits) that Domain Profile
 * Flow then consumes.
 *
 * Four documented rules are enforced as code:
 *
 *  1. Catalog of cognitive domains (doc "Resumo" / "Papel no Atlas").
 *     `catalog()` lists the canonical selectable domains, each carrying its main
 *     limit. A domain is a "fonte de perfil cognitivo", never a provider, never
 *     a product. The decision frontmatter is explicit: "Dominios sao fontes de
 *     perfil cognitivo, nao provedores e nao produtos."
 *
 *  2. Selection with profile (doc "Contratos": "Entrada: catalogo de dominios.
 *     Saida: dominio selecionavel e seus limites."). `select()` takes a domain
 *     token and returns the selected domain plus its activated capabilities and
 *     limit (doc "Exemplos": Programming activates SDD, Forge, diff, gates and
 *     evidence; Research activates search, sources and curation).
 *
 *  3. Provider invariant (doc "Contratos": "Invariante: dominio nao decide
 *     provider"; forbidden_changes: "Tratar dominio como decision-maker";
 *     scope: "Proibido: acoplar dominio a modelo especifico"). EVERY selection
 *     result carries `decides_provider = false` and `decides_product = false`,
 *     and `guardDecisionMaker()` rejects any attempt to make the plane decide a
 *     provider/model/product.
 *
 *  4. No-default-to-programming guard (doc "Riscos": "Tudo cair em programacao
 *     por default"). An unknown / empty token is NOT silently resolved to
 *     programming; `select()` returns an `unresolved` selection that requires an
 *     explicit domain choice and lists the catalog.
 *
 * The plane's output flows to `domain-profile-flow` (doc "Fluxo" / "Onde Se
 * Encaixa"); the plane itself never picks a provider.
 *
 * This service NEVER reads a doc, runs a command, touches git, or calls a
 * model. It only exposes the catalog and the selected profile, exactly as the
 * doc states.
 *
 * @see docs/engineering-knowledge-base/system-graph/domain-plane.md
 */
final class AtlasDomainPlaneService
{
    /** Receipt schema ids (doc "Contratos"). */
    public const RECEIPT_SELECTION = 'atlas.domain_plane.selection.v1';
    public const RECEIPT_GUARD = 'atlas.domain_plane.decision_maker_guard.v1';

    /** Closed set of selection verdicts. */
    public const SELECTED = 'domain_selected';
    public const UNRESOLVED = 'unresolved_needs_explicit_domain';

    /** Where the plane's output flows next (doc "Fluxo"). */
    public const FLOWS_TO = 'domain-profile-flow';

    /**
     * Canonical selectable cognitive domains and their main operational limit.
     *
     * Domain keys mirror the canonical set declared in
     * docs/engineering-knowledge-base/domains/README.md (decisions). Each domain
     * activates a profile (capabilities) and carries a limit; none decides a
     * provider. The "general" domain is a neutral fallback, never a bypass.
     *
     * @var array<string, array{capabilities: list<string>, limit: string}>
     */
    private const CATALOG = [
        'programming' => [
            'capabilities' => ['sdd', 'forge', 'diff', 'gates', 'evidence'],
            'limit' => 'all engineering work passes spec-driven gates and leaves evidence',
        ],
        'research' => [
            'capabilities' => ['search', 'sources', 'curation'],
            'limit' => 'claims must cite sources and pass curation before use',
        ],
        'writing' => [
            'capabilities' => ['drafting', 'editing', 'voice'],
            'limit' => 'output follows the operator voice and editorial review',
        ],
        'strategic_decision' => [
            'capabilities' => ['framing', 'options', 'tradeoffs'],
            'limit' => 'decisions emit options and tradeoffs, never a silent single answer',
        ],
        'marketing' => [
            'capabilities' => ['campaign', 'audience', 'messaging'],
            'limit' => 'publishing or spend needs explicit approval',
        ],
        'finance' => [
            'capabilities' => ['accounting', 'valuation', 'cashflow'],
            'limit' => 'money-moving actions need explicit approval',
        ],
        'learning' => [
            'capabilities' => ['curriculum', 'practice', 'recall'],
            'limit' => 'learning plans track mastery, not raw consumption',
        ],
        'qa' => [
            'capabilities' => ['test_design', 'coverage', 'regression'],
            'limit' => 'quality is proven by tests, not asserted',
        ],
        'security' => [
            'capabilities' => ['threat_model', 'audit', 'hardening'],
            'limit' => 'only authorized scope; sensitive work stays local',
        ],
        'operations' => [
            'capabilities' => ['runbook', 'monitoring', 'incident'],
            'limit' => 'operational changes follow runbooks and leave a trace',
        ],
        'personal_development' => [
            'capabilities' => ['goals', 'habits', 'reflection'],
            'limit' => 'guidance is reflective, never coercive',
        ],
        'self_improvement' => [
            'capabilities' => ['retro', 'pattern_capture', 'compounding'],
            'limit' => 'improvements are captured as durable patterns',
        ],
        'health' => [
            'capabilities' => ['tracking', 'habits', 'signals'],
            'limit' => 'informational only, never a medical decision-maker',
        ],
        'background' => [
            'capabilities' => ['scheduling', 'maintenance', 'housekeeping'],
            'limit' => 'background work never bypasses foreground gates',
        ],
        'general' => [
            'capabilities' => ['triage', 'clarify'],
            'limit' => 'neutral triage that never bypasses a specialized domain',
        ],
    ];

    /**
     * The catalog of selectable cognitive domains (doc "Contratos" input).
     *
     * @return array<string, array{capabilities: list<string>, limit: string}>
     */
    public function catalog(): array
    {
        return self::CATALOG;
    }

    /** The canonical selectable domain keys, sorted. @return list<string> */
    public function domains(): array
    {
        $keys = array_keys(self::CATALOG);
        sort($keys);

        return $keys;
    }

    /**
     * Select a domain and return its operational profile (doc "Contratos":
     * "Saida: dominio selecionavel e seus limites").
     *
     * Invariant enforced on every result: the domain does NOT decide provider
     * and does NOT decide product (doc "Contratos" / forbidden_changes / scope).
     *
     * An unknown / empty token does NOT default to programming (doc "Riscos":
     * "Tudo cair em programacao por default") — it returns an `unresolved`
     * selection that requires an explicit domain and lists the catalog.
     *
     * @param array{domain?: string, selection_ref?: string} $input
     * @return array{
     *   schema: string, verdict: string, selected_domain: ?string,
     *   activated_capabilities: list<string>, limit: ?string,
     *   decides_provider: bool, decides_product: bool, flows_to: string,
     *   available_domains: list<string>, selection_ref: string,
     *   defaulted_to_programming: bool, reason: string, next_action: string
     * }
     */
    public function select(array $input): array
    {
        $token = $this->normalize((string) ($input['domain'] ?? ''));
        $ref = (string) ($input['selection_ref'] ?? ($input['domain'] ?? 'inline'));

        // Empty or unknown token -> unresolved. We explicitly refuse to fall
        // back to programming (doc "Riscos"). The plane never guesses a domain.
        if ($token === '' || ! isset(self::CATALOG[$token])) {
            return [
                'schema' => self::RECEIPT_SELECTION,
                'verdict' => self::UNRESOLVED,
                'selected_domain' => null,
                'activated_capabilities' => [],
                'limit' => null,
                'decides_provider' => false,
                'decides_product' => false,
                'flows_to' => self::FLOWS_TO,
                'available_domains' => $this->domains(),
                'selection_ref' => $ref,
                'defaulted_to_programming' => false,
                'reason' => $token === ''
                    ? 'no domain token supplied; the plane never defaults to programming'
                    : "unknown domain token '{$token}'; the plane never defaults to programming",
                'next_action' => 'choose_an_explicit_domain_from_available_domains',
            ];
        }

        $profile = self::CATALOG[$token];

        return [
            'schema' => self::RECEIPT_SELECTION,
            'verdict' => self::SELECTED,
            'selected_domain' => $token,
            'activated_capabilities' => $profile['capabilities'],
            'limit' => $profile['limit'],
            'decides_provider' => false,
            'decides_product' => false,
            'flows_to' => self::FLOWS_TO,
            'available_domains' => $this->domains(),
            'selection_ref' => $ref,
            'defaulted_to_programming' => false,
            'reason' => "domain '{$token}' selected as a source of cognitive profile; it carries capabilities and a limit but does not decide provider or product",
            'next_action' => 'pass_profile_to_domain_profile_flow',
        ];
    }

    /**
     * Guard the load-bearing invariant: a domain is never a decision-maker for
     * a provider / model / product (doc forbidden_changes "Tratar dominio como
     * decision-maker"; scope "Proibido: acoplar dominio a modelo especifico").
     *
     * Returns whether the requested decision is allowed by the plane. Anything
     * that asks the plane to decide a provider, model or product is rejected;
     * only profile/limit decisions are allowed.
     *
     * @param array{domain?: string, decision?: string} $input
     * @return array{
     *   schema: string, domain: string, decision: string, allowed: bool,
     *   reason: string, redirect_to: ?string
     * }
     */
    public function guardDecisionMaker(array $input): array
    {
        $domain = $this->normalize((string) ($input['domain'] ?? ''));
        $decision = $this->normalize((string) ($input['decision'] ?? ''));

        $forbidden = ['provider', 'model', 'product'];
        $isForbidden = in_array($decision, $forbidden, true);

        if ($isForbidden) {
            return [
                'schema' => self::RECEIPT_GUARD,
                'domain' => $domain,
                'decision' => $decision,
                'allowed' => false,
                'reason' => "a domain is a source of cognitive profile, not a {$decision} decision-maker",
                'redirect_to' => $decision === 'provider' || $decision === 'model'
                    ? 'atlas-decide-or-provider-topology'
                    : 'product-layer',
            ];
        }

        $profileDecisions = ['profile', 'capability', 'limit', 'gates'];
        $allowed = in_array($decision, $profileDecisions, true);

        return [
            'schema' => self::RECEIPT_GUARD,
            'domain' => $domain,
            'decision' => $decision,
            'allowed' => $allowed,
            'reason' => $allowed
                ? "a domain may decide its own {$decision}"
                : "unknown decision '{$decision}'; the plane only decides profile, capability, limit or gates",
            'redirect_to' => null,
        ];
    }

    /** Lowercase + trim, with hyphens normalized to underscores. */
    private function normalize(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
