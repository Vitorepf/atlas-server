<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas System Graph · Domain Profile Flow decider.
 *
 * Pure, deterministic runtime for the Domain Profile Flow step of the AI kernel
 * pipeline. This step sits AFTER Domain Plane (which exposes the catalog of
 * cognitive domains) and Business Context, and BEFORE Context Builder. It is NOT
 * the catalog (that is Domain Plane) and it is NOT a router/model picker. It is
 * the SELECTION step: given a business context, an intent and a domain, it picks
 * the executive flow and emits the gates that Context Builder will apply.
 *
 * Six documented rules are enforced as code:
 *
 *  1. Selection contract (doc "Contratos": "Entrada: contexto de negocio e
 *     intent. Saida: dominio, perfil vertical e fluxo executivo."). `select()`
 *     takes business_context + intent + domain and returns the domain, the
 *     vertical profile and the executive flow, plus the gates that flow defines.
 *
 *  2. Flow defines gates (doc "Fluxo": "O flow selecionado define contexto e
 *     gates."). Each executive flow carries an explicit, deterministic gate set;
 *     selecting a flow yields exactly that flow's gates — no more, no fewer.
 *
 *  3. Worked example (doc "Exemplos": "Atlas Code usa dominio `programming` e
 *     fluxos SDD/Forge para transformar intent em obra executavel."). The
 *     `programming` domain offers the `sdd` and `forge` executive flows.
 *
 *  4. Domain-is-not-provider invariant (doc "Contratos": "Invariante: dominio
 *     nao e provider"; forbidden_changes: "Usar dominio como atalho para
 *     escolher provider"; scope: "Proibido: bypassar Policy Profile"). EVERY
 *     selection carries `decides_provider = false` and `bypasses_policy_profile
 *     = false`, and routes through Policy Profile (it never replaces it).
 *
 *  5. Wrong-flow / wrong-gates guard (doc "Riscos": "Fluxo errado aplicar gates
 *     errados."). A flow that does not belong to the selected domain is REJECTED
 *     — the step refuses to attach a foreign flow's gates to a domain. An unknown
 *     domain or flow does not silently fall through to a default profile.
 *
 *  6. Declare-the-domain rule (doc "Regras para IA": "IA deve declarar o dominio
 *     usado quando a tarefa virar implementacao ou decisao operacional."). Every
 *     resolved selection emits a `declared_domain` the caller must surface; an
 *     implementation/decision intent with no declared domain is flagged.
 *
 * This service NEVER reads a doc, runs a command, touches git, or calls a model.
 * It only maps (business_context, intent, domain) -> (vertical profile, flow,
 * gates), exactly as the doc states. Its output flows to `context-builder`.
 *
 * @see docs/engineering-knowledge-base/system-graph/domain-profile-flow.md
 */
final class AtlasSystemGraphDomainProfileFlowService
{
    /** Receipt schema ids (doc "Contratos"). */
    public const RECEIPT_SELECTION = 'atlas.domain_profile_flow.selection.v1';
    public const RECEIPT_GUARD = 'atlas.domain_profile_flow.flow_gate_guard.v1';

    /** Closed set of selection verdicts. */
    public const SELECTED = 'profile_flow_selected';
    public const UNRESOLVED_DOMAIN = 'unresolved_unknown_domain';
    public const UNRESOLVED_FLOW = 'unresolved_unknown_flow';
    public const REJECTED_FOREIGN_FLOW = 'rejected_flow_not_in_domain';
    public const REJECTED_UNDECLARED = 'rejected_undeclared_domain_for_implementation';

    /** Where this step's output flows next (doc "Fluxo" / frontmatter flows_to). */
    public const FLOWS_TO = 'context-builder';

    /** Upstream dependencies (doc "Onde Se Encaixa" / frontmatter depends_on). */
    public const DEPENDS_ON = ['business-context', 'domain-plane'];

    /**
     * Intents that turn the task into "implementation or operational decision"
     * (doc "Regras para IA") and therefore require a declared domain.
     *
     * @var list<string>
     */
    private const IMPLEMENTATION_INTENTS = ['implement', 'build', 'ship', 'deploy', 'decide', 'operate'];

    /**
     * Domain -> vertical profile + the executive flows it offers, each flow
     * carrying the exact gates it defines (doc "Fluxo"). The `programming`
     * entry encodes the doc worked example (SDD + Forge). Every flow's gate set
     * is closed and deterministic; a wrong flow can never inherit another
     * domain's gates (doc "Riscos").
     *
     * @var array<string, array{
     *   vertical_profile: string,
     *   flows: array<string, array{gates: list<string>, summary: string}>
     * }>
     */
    private const PROFILES = [
        'programming' => [
            'vertical_profile' => 'software_engineering',
            'flows' => [
                // Doc "Exemplos": Atlas Code uses programming + SDD/Forge.
                'sdd' => [
                    'gates' => ['spec_approved', 'diff_review', 'tests_green', 'evidence_recorded'],
                    'summary' => 'spec-driven development: spec before code, reviewed diff, green tests, recorded evidence',
                ],
                'forge' => [
                    'gates' => ['plan_approved', 'workspace_certified', 'parallel_diff_review', 'merge_authorization', 'evidence_recorded'],
                    'summary' => 'multi-agent forge work: certified workspace, reviewed parallel diffs, authorized merge, evidence',
                ],
            ],
        ],
        'research' => [
            'vertical_profile' => 'knowledge_research',
            'flows' => [
                'inquiry' => [
                    'gates' => ['sources_cited', 'curation_passed', 'evidence_recorded'],
                    'summary' => 'cited sources curated before any claim is used',
                ],
            ],
        ],
        'marketing' => [
            'vertical_profile' => 'marketing_company',
            'flows' => [
                'campaign' => [
                    'gates' => ['audience_defined', 'message_review', 'spend_approval'],
                    'summary' => 'campaign with defined audience, reviewed messaging, explicit spend approval',
                ],
            ],
        ],
        'finance' => [
            'vertical_profile' => 'finance_operations',
            'flows' => [
                'ledger' => [
                    'gates' => ['reconciliation', 'money_move_approval', 'evidence_recorded'],
                    'summary' => 'reconciled ledger work; money-moving actions need explicit approval',
                ],
            ],
        ],
        'strategic_decision' => [
            'vertical_profile' => 'strategic_decision',
            'flows' => [
                'decision' => [
                    'gates' => ['options_framed', 'tradeoffs_explicit', 'decision_recorded'],
                    'summary' => 'options and tradeoffs surfaced before a recorded decision',
                ],
            ],
        ],
        'writing' => [
            'vertical_profile' => 'editorial',
            'flows' => [
                'authoring' => [
                    'gates' => ['voice_aligned', 'editorial_review'],
                    'summary' => 'drafting in operator voice with editorial review',
                ],
            ],
        ],
    ];

    /**
     * The selectable domains for profile-flow (doc "Contratos" input domain set).
     *
     * @return list<string>
     */
    public function domains(): array
    {
        $keys = array_keys(self::PROFILES);
        sort($keys);

        return $keys;
    }

    /**
     * The executive flows a domain offers (doc "Exemplos"). Unknown domain -> [].
     *
     * @return list<string>
     */
    public function flowsFor(string $domain): array
    {
        $domain = $this->normalize($domain);
        if (! isset(self::PROFILES[$domain])) {
            return [];
        }
        $keys = array_keys(self::PROFILES[$domain]['flows']);
        sort($keys);

        return $keys;
    }

    /**
     * Select the domain profile and executive flow for a task, and emit the gates
     * that flow defines (doc "Contratos" / "Fluxo").
     *
     * Invariants enforced on every resolved result:
     *  - `decides_provider = false` (doc "Contratos": dominio nao e provider).
     *  - `bypasses_policy_profile = false` (doc scope: proibido bypassar Policy
     *    Profile) — the selection routes through Policy Profile.
     *  - the chosen flow MUST belong to the chosen domain, otherwise the result
     *    is `rejected_flow_not_in_domain` with NO gates (doc "Riscos": fluxo
     *    errado aplica gates errados).
     *  - an implementation/decision intent with no domain is rejected as
     *    `rejected_undeclared_domain_for_implementation` (doc "Regras para IA").
     *
     * An unknown domain or flow does NOT silently fall through to a default
     * profile — it returns the matching `unresolved` verdict and lists choices.
     *
     * @param array{business_context?: string, intent?: string, domain?: string, flow?: string, selection_ref?: string} $input
     * @return array{
     *   schema: string, verdict: string, business_context: string, intent: string,
     *   declared_domain: ?string, vertical_profile: ?string, executive_flow: ?string,
     *   gates: list<string>, decides_provider: bool, bypasses_policy_profile: bool,
     *   routes_through_policy_profile: bool, flows_to: string, depends_on: list<string>,
     *   available_domains: list<string>, available_flows: list<string>,
     *   selection_ref: string, reason: string, next_action: string
     * }
     */
    public function select(array $input): array
    {
        $businessContext = (string) ($input['business_context'] ?? 'unspecified');
        $intent = $this->normalize((string) ($input['intent'] ?? ''));
        $domain = $this->normalize((string) ($input['domain'] ?? ''));
        $flow = $this->normalize((string) ($input['flow'] ?? ''));
        $ref = (string) ($input['selection_ref'] ?? ($input['domain'] ?? 'inline'));

        $base = [
            'schema' => self::RECEIPT_SELECTION,
            'business_context' => $businessContext,
            'intent' => $intent,
            'declared_domain' => null,
            'vertical_profile' => null,
            'executive_flow' => null,
            'gates' => [],
            'decides_provider' => false,
            'bypasses_policy_profile' => false,
            'routes_through_policy_profile' => true,
            'flows_to' => self::FLOWS_TO,
            'depends_on' => self::DEPENDS_ON,
            'available_domains' => $this->domains(),
            'available_flows' => [],
            'selection_ref' => $ref,
        ];

        // Doc "Regras para IA": an implementation/decision task with no declared
        // domain is rejected — the IA must declare the domain used.
        if ($domain === '' && in_array($intent, self::IMPLEMENTATION_INTENTS, true)) {
            return array_merge($base, [
                'verdict' => self::REJECTED_UNDECLARED,
                'reason' => "intent '{$intent}' turns the task into implementation/decision but no domain was declared; the IA must declare the domain used",
                'next_action' => 'declare_an_explicit_domain_from_available_domains',
            ]);
        }

        // Unknown / empty domain -> unresolved (never default to a profile).
        if ($domain === '' || ! isset(self::PROFILES[$domain])) {
            return array_merge($base, [
                'verdict' => self::UNRESOLVED_DOMAIN,
                'reason' => $domain === ''
                    ? 'no domain supplied; profile-flow never defaults to a profile'
                    : "unknown domain '{$domain}'; profile-flow never defaults to a profile",
                'next_action' => 'choose_an_explicit_domain_from_available_domains',
            ]);
        }

        $profile = self::PROFILES[$domain];
        $availableFlows = $this->flowsFor($domain);

        // A flow must be chosen and must belong to this domain (doc "Riscos":
        // wrong flow applies wrong gates). No flow supplied -> unresolved flow.
        if ($flow === '') {
            return array_merge($base, [
                'verdict' => self::UNRESOLVED_FLOW,
                'declared_domain' => $domain,
                'vertical_profile' => $profile['vertical_profile'],
                'available_flows' => $availableFlows,
                'reason' => "domain '{$domain}' resolved but no executive flow chosen; profile-flow never guesses a flow",
                'next_action' => 'choose_an_executive_flow_from_available_flows',
            ]);
        }

        // Foreign flow (exists in another domain or is unknown) -> rejected, NO
        // gates attached. This is the core "fluxo errado / gates errados" guard.
        if (! isset($profile['flows'][$flow])) {
            return array_merge($base, [
                'verdict' => self::REJECTED_FOREIGN_FLOW,
                'declared_domain' => $domain,
                'vertical_profile' => $profile['vertical_profile'],
                'available_flows' => $availableFlows,
                'reason' => "flow '{$flow}' does not belong to domain '{$domain}'; attaching its gates would apply the wrong gates",
                'next_action' => 'choose_an_executive_flow_from_available_flows',
            ]);
        }

        // Resolved: emit exactly this flow's gates (doc "Fluxo").
        return array_merge($base, [
            'verdict' => self::SELECTED,
            'declared_domain' => $domain,
            'vertical_profile' => $profile['vertical_profile'],
            'executive_flow' => $flow,
            'gates' => $profile['flows'][$flow]['gates'],
            'available_flows' => $availableFlows,
            'reason' => "domain '{$domain}' -> vertical '{$profile['vertical_profile']}' -> flow '{$flow}' ({$profile['flows'][$flow]['summary']}); gates defined by the flow, provider not decided, Policy Profile not bypassed",
            'next_action' => 'pass_profile_flow_and_gates_to_context_builder',
        ]);
    }

    /**
     * Guard the load-bearing matrix invariant: a (domain, flow) pair only yields
     * gates when the flow belongs to the domain (doc "Riscos": "Fluxo errado
     * aplicar gates errados"). This is the standalone check Context Builder can
     * call before applying any gate set.
     *
     * @param array{domain?: string, flow?: string} $input
     * @return array{
     *   schema: string, domain: string, flow: string, valid: bool,
     *   gates: list<string>, reason: string
     * }
     */
    public function guardFlowGates(array $input): array
    {
        $domain = $this->normalize((string) ($input['domain'] ?? ''));
        $flow = $this->normalize((string) ($input['flow'] ?? ''));

        $valid = isset(self::PROFILES[$domain]['flows'][$flow]);

        return [
            'schema' => self::RECEIPT_GUARD,
            'domain' => $domain,
            'flow' => $flow,
            'valid' => $valid,
            'gates' => $valid ? self::PROFILES[$domain]['flows'][$flow]['gates'] : [],
            'reason' => $valid
                ? "flow '{$flow}' belongs to domain '{$domain}'; its gates may be applied"
                : "flow '{$flow}' does not belong to domain '{$domain}'; no gates may be applied",
        ];
    }

    /** Lowercase + trim, with hyphens normalized to underscores. */
    private function normalize(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
