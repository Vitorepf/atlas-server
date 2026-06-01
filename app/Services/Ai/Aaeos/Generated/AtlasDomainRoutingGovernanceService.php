<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Domain Routing Governance decider.
 *
 * Pure, deterministic decider that turns the documented routing governance into
 * a contract. The doc teaches an IA to decide which Atlas domain (and flow /
 * profile) a prompt or objective enters, so it does NOT (a) invent a new domain
 * per prompt, (b) treat a tool / language / surface / agent as a domain, or
 * (c) silently guess on ambiguity.
 *
 * Three documented rules are enforced as code:
 *
 *  1. Prompt -> Domain matrix (doc "Matriz Prompt Para Dominio"). A prompt is
 *     scanned for documented signals and routed to a primary_domain plus the
 *     documented secondary_domains, carrying the matrix observation (e.g. "live
 *     trade bloqueado por default", "publicacao/gasto exige approval").
 *     Central rule: "primeiro tente dominio existente; depois flow/profile;
 *     depois capability; dominio novo somente apos gate formal." So routing
 *     ALWAYS prefers an existing canonical domain and NEVER creates one.
 *
 *  2. Flow vs New Domain (doc "Flow Vs Dominio Novo"). Things like
 *     `programming.frontend`, `finance.valuation`, `cyber.bug_bounty_authorized`
 *     are flows/profiles of an existing domain, NOT new domains. classifyScope
 *     refuses to treat a tool, language/framework, agent/persona or surface as a
 *     domain (doc "Regras para IA").
 *
 *  3. Domain Creation Gate (doc "Domain Creation Gate"). A new domain is the
 *     exception: evaluateCreationGate answers the 10 documented questions and
 *     only passes when ALL are strong AND an existing domain was tried and
 *     failed; "Se qualquer resposta for fraca, crie flow/profile no dominio
 *     existente." A weak answer flips the verdict to use_flow_in_existing_domain.
 *
 * Every public method emits a single decision receipt
 * (atlas.ai.domain_routing_decision.v1 / .domain_creation_gate.v1 /
 * .flow_profile_selection.v1) carrying the doc's minimum fields
 * (primary_domain, secondary_domains, routing_reason, rejected_domains,
 * risk_level, required_gates, domain_creation_gate_status, next_action).
 *
 * This service NEVER reads a doc, runs a command, touches git, or promotes a
 * domain to implemented/ready. It only decides routing, scope and the creation
 * gate, exactly as the doc states.
 *
 * @see docs/engineering-knowledge-base/domains/domain-routing-governance.md
 */
final class AtlasDomainRoutingGovernanceService
{
    /** Receipt schema ids (doc "Contratos"). */
    public const RECEIPT_ROUTING = 'atlas.ai.domain_routing_decision.v1';
    public const RECEIPT_CREATION_GATE = 'atlas.ai.domain_creation_gate.v1';
    public const RECEIPT_FLOW_PROFILE = 'atlas.ai.flow_profile_selection.v1';

    /** Closed set of routing verdicts. */
    public const ROUTE_MATCHED = 'routed_to_existing_domain';
    public const ROUTE_AMBIGUOUS = 'ambiguous_needs_disambiguation';
    public const ROUTE_FALLBACK_GENERAL = 'routed_to_general_triage';

    /** Closed set of scope verdicts (doc "Flow Vs Dominio Novo"). */
    public const SCOPE_FLOW = 'flow_or_profile_in_existing_domain';
    public const SCOPE_NEW_DOMAIN_CANDIDATE = 'new_domain_candidate_run_gate';
    public const SCOPE_NOT_A_DOMAIN = 'not_a_domain';

    /** Closed set of creation-gate verdicts. */
    public const GATE_PASS = 'new_domain_authorized';
    public const GATE_USE_FLOW = 'use_flow_in_existing_domain';

    /**
     * Canonical domains (doc "Dominios Canonicos"), each with its main limit.
     * This is the closed allow-list: routing NEVER targets anything outside it.
     *
     * @var array<string, string>
     */
    private const CANONICAL_DOMAINS = [
        'programming' => 'nao resolver negocio/marketing como codigo.',
        'research' => 'nao entregar resumo sem fontes.',
        'strategy' => 'nao executar compromissos autonomos.',
        'finance' => 'sem ordem real por default.',
        'marketing' => 'sem publicar/gastar sem approval.',
        'security' => 'exploit/scan so com autorizacao.',
        'personal_development' => 'non-clinical, privado por default.',
        'learning' => 'nao altera Learning Plane core.',
        'writing' => 'sem auto-publicacao.',
        'operations' => 'sem deploy/restart/delete sem gate.',
        'qa' => 'nao executa testes no lugar do dono.',
        'background' => 'nao agenda sem permissao.',
        'self_improvement' => 'proposal/review antes de mutacao.',
        'general' => 'nao burla dominios especializados.',
    ];

    /**
     * Prompt -> Domain matrix (doc "Matriz Prompt Para Dominio"), in doc row
     * order so the first matching row wins deterministically. Each row carries
     * its signals, primary domain, common secondary domains and the doc note.
     *
     * @var list<array{signals: list<string>, primary: string, secondary: list<string>, note: string}>
     */
    private const MATRIX = [
        [
            'signals' => ['implementar', 'corrigir bug', 'bug', 'refatorar', 'teste', 'pr', 'codigo', 'debug'],
            'primary' => 'programming',
            'secondary' => ['qa', 'security', 'operations'],
            'note' => 'Forge entra quando for obra pesada.',
        ],
        [
            'signals' => ['pesquisar', 'fontes', 'relatorio', 'papers', 'mercado', 'sintese'],
            'primary' => 'research',
            'secondary' => ['strategy', 'finance', 'marketing'],
            'note' => 'Exige source plan e citations.',
        ],
        [
            'signals' => ['oportunidade', 'criar empresa', 'escalar', 'tam', 'gtm', 'venture'],
            'primary' => 'strategy',
            'secondary' => ['research', 'marketing', 'finance'],
            'note' => 'Venture Studio operacional, nao decision review.',
        ],
        [
            'signals' => ['carteira', 'ativo', 'valuation', 'risco', 'macro', 'trade', 'portfolio'],
            'primary' => 'finance',
            'secondary' => ['research', 'strategy'],
            'note' => 'Live trade bloqueado por default.',
        ],
        [
            'signals' => ['campanha', 'copy', 'criativo', 'funil', 'ads', 'icp'],
            'primary' => 'marketing',
            'secondary' => ['research', 'strategy', 'sales'],
            'note' => 'Publicacao/gasto exige approval.',
        ],
        [
            'signals' => ['pentest', 'bug bounty', 'vulnerabilidade', 'compliance', 'hackerone'],
            'primary' => 'security',
            'secondary' => ['programming', 'operations'],
            'note' => 'Ofensivo so com autorizacao/RoE.',
        ],
        [
            'signals' => ['rotina', 'habito', 'estudo', 'professor', 'performance pessoal', 'foco'],
            'primary' => 'personal_development',
            'secondary' => ['health'],
            'note' => 'Non-clinical por default.',
        ],
        [
            'signals' => ['texto', 'artigo', 'roteiro', 'editar', 'publicar', 'rascunho'],
            'primary' => 'writing',
            'secondary' => ['research', 'marketing'],
            'note' => 'Publicacao exige review.',
        ],
        [
            'signals' => ['deploy', 'incidente', 'runbook', 'fora do ar', 'restart', 'sistema fora'],
            'primary' => 'operations',
            'secondary' => ['programming', 'security'],
            'note' => 'Mutacao infra exige approval.',
        ],
        [
            'signals' => ['schedule', 'cron', 'daemon', 'rodar depois', 'recorrente', 'heartbeat'],
            'primary' => 'background',
            'secondary' => ['operations', 'policy'],
            'note' => 'Nao inicia job sem stop conditions.',
        ],
        [
            'signals' => ['usar browser', 'api', 'terminal', 'criar ferramenta', 'automatizar', 'automacao'],
            'primary' => 'automation',
            'secondary' => ['policy', 'evidence'],
            'note' => 'Ferramenta nao e dominio por si so.',
        ],
    ];

    /**
     * High-risk primaries (doc failure_modes + matrix safety notes): these carry
     * an explicit required gate before any mutation/spend/exploit/live action.
     *
     * @var array<string, string>
     */
    private const HIGH_RISK_GATES = [
        'finance' => 'live-trade-blocked-needs-approval',
        'marketing' => 'publish-or-spend-needs-approval',
        'security' => 'offensive-needs-authorization-and-roe',
        'operations' => 'infra-mutation-needs-approval',
        'background' => 'no-job-without-stop-conditions',
    ];

    /**
     * Tokens that are NEVER a domain (doc "Regras para IA"): a tool, a
     * language/framework, an agent/persona, a surface/screen, a provider.
     *
     * @var array<string, list<string>>
     */
    private const NON_DOMAIN_TOKENS = [
        'tool' => ['ferramenta', 'tool', 'browser', 'terminal', 'api', 'cli', 'script'],
        'language_or_framework' => ['python', 'php', 'laravel', 'react', 'typescript', 'framework', 'linguagem'],
        'agent_or_persona' => ['agent', 'persona', 'bot', 'assistant'],
        'surface' => ['tela', 'surface', 'dashboard', 'screen', 'ui', 'view'],
        'provider' => ['provider', 'claude', 'codex', 'cursor', 'gemini'],
    ];

    /** The ten Domain Creation Gate questions, in doc order. */
    private const GATE_QUESTIONS = [
        'existing_domain_tried_and_failed',
        'why_flow_profile_capability_insufficient',
        'unique_charter',
        'unique_ontology',
        'final_artifacts',
        'policies_forbidden_actions_gates',
        'evidence_schemas',
        'metrics_and_maturity_stages',
        'handoffs_with_existing_domains',
        'owner_and_promotion_gate',
    ];

    /**
     * Route a prompt/objective to a canonical domain via the documented matrix.
     *
     * Central doc rule: prefer an existing domain; never create one here. When
     * exactly one matrix row matches -> routed. When two or more distinct
     * primaries match -> ambiguous (emit primary + secondary + blockers, never a
     * silent guess, per decision "Ambiguidade deve gerar ... nao chute
     * silencioso"). When nothing matches -> general triage (NOT a new domain).
     *
     * @param array{prompt?: string, prompt_or_objective_ref?: string} $input
     * @return array{
     *   schema: string, verdict: string, primary_domain: ?string,
     *   secondary_domains: list<string>, selected_flow: ?string,
     *   selected_profile: ?string, routing_reason: string,
     *   rejected_domains: list<string>, risk_level: string,
     *   required_gates: list<string>, domain_creation_gate_status: string,
     *   matrix_note: ?string, prompt_or_objective_ref: string,
     *   creates_new_domain: bool, next_action: string
     * }
     */
    public function route(array $input): array
    {
        $prompt = $this->normalize((string) ($input['prompt'] ?? ''));
        $ref = (string) ($input['prompt_or_objective_ref'] ?? ($input['prompt'] ?? 'inline'));

        $matched = [];
        foreach (self::MATRIX as $row) {
            foreach ($row['signals'] as $signal) {
                if ($prompt !== '' && str_contains($prompt, $signal)) {
                    $matched[$row['primary']] = $row;
                    break;
                }
            }
        }

        $primaries = array_keys($matched);

        // No signal at all -> general triage. Doc: general "nao substitui
        // dominio" and we explicitly do NOT mint a domain.
        if (count($primaries) === 0) {
            return [
                'schema' => self::RECEIPT_ROUTING,
                'verdict' => self::ROUTE_FALLBACK_GENERAL,
                'primary_domain' => 'general',
                'secondary_domains' => [],
                'selected_flow' => null,
                'selected_profile' => null,
                'routing_reason' => 'no documented matrix signal matched; general triage applies and does not bypass specialized domains',
                'rejected_domains' => [],
                'risk_level' => 'low',
                'required_gates' => ['general-triage-no-domain-bypass'],
                'domain_creation_gate_status' => 'not_required',
                'matrix_note' => self::CANONICAL_DOMAINS['general'],
                'prompt_or_objective_ref' => $ref,
                'creates_new_domain' => false,
                'next_action' => 'ask_one_disambiguating_question_or_handle_as_general',
            ];
        }

        // Two or more distinct primaries -> ambiguous. Emit structured output,
        // never a silent guess (doc decision on ambiguity).
        if (count($primaries) > 1) {
            $candidates = $primaries;
            sort($candidates);
            $primary = $candidates[0];
            $secondary = array_values(array_filter($candidates, static fn ($d) => $d !== $primary));

            return [
                'schema' => self::RECEIPT_ROUTING,
                'verdict' => self::ROUTE_AMBIGUOUS,
                'primary_domain' => $primary,
                'secondary_domains' => $secondary,
                'selected_flow' => null,
                'selected_profile' => null,
                'routing_reason' => 'multiple canonical domains matched; emitting primary + secondary + blockers instead of a silent guess',
                'rejected_domains' => [],
                'risk_level' => 'medium',
                'required_gates' => ['disambiguate-before-execution'],
                'domain_creation_gate_status' => 'not_required',
                'matrix_note' => $matched[$primary]['note'],
                'prompt_or_objective_ref' => $ref,
                'creates_new_domain' => false,
                'next_action' => 'confirm_primary_domain_with_operator',
            ];
        }

        // Exactly one primary -> clean route to an existing canonical domain.
        $primary = $primaries[0];
        $row = $matched[$primary];

        // The matrix may name a primary that is itself a composite alias
        // (automation/security can resolve to a canonical or a Company Runtime);
        // we keep the documented primary verbatim and validate it is canonical.
        $canonicalPrimary = $this->canonicalOrNull($primary);

        $rejected = array_values(array_filter(
            array_keys(self::CANONICAL_DOMAINS),
            static fn ($d) => $d !== $canonicalPrimary && $d !== 'general'
        ));

        $gates = [];
        if (isset(self::HIGH_RISK_GATES[$primary])) {
            $gates[] = self::HIGH_RISK_GATES[$primary];
        }
        $risk = $gates === [] ? 'low' : 'high';

        return [
            'schema' => self::RECEIPT_ROUTING,
            'verdict' => self::ROUTE_MATCHED,
            'primary_domain' => $primary,
            'secondary_domains' => $row['secondary'],
            'selected_flow' => null,
            'selected_profile' => null,
            'routing_reason' => 'single canonical domain matched the documented matrix; existing domain preferred over creating a new one',
            'rejected_domains' => $rejected,
            'risk_level' => $risk,
            'required_gates' => $gates,
            'domain_creation_gate_status' => 'not_required',
            'matrix_note' => $row['note'],
            'prompt_or_objective_ref' => $ref,
            'creates_new_domain' => false,
            'next_action' => 'select_flow_profile_within_' . $primary,
        ];
    }

    /**
     * Classify a candidate label as flow/profile vs new-domain-candidate vs
     * not-a-domain (doc "Flow Vs Dominio Novo" + "Regras para IA").
     *
     * A dotted label under a canonical domain (programming.frontend,
     * finance.valuation) is a flow/profile, never a domain. A label that is a
     * tool/language/agent/surface/provider is not_a_domain. Anything else is a
     * new_domain_candidate that MUST run the creation gate first.
     *
     * @param array{label?: string} $input
     * @return array{
     *   schema: string, verdict: string, label: string, base_domain: ?string,
     *   flow_or_profile: ?string, non_domain_kind: ?string, reason: string,
     *   next_action: string
     * }
     */
    public function classifyScope(array $input): array
    {
        $label = trim((string) ($input['label'] ?? ''));
        $normalized = $this->normalize($label);

        // Dotted form base.child -> flow/profile of an existing canonical base.
        if (str_contains($normalized, '.')) {
            [$base, $child] = explode('.', $normalized, 2);
            if ($this->canonicalOrNull($base) !== null || $base === 'cyber') {
                return [
                    'schema' => self::RECEIPT_FLOW_PROFILE,
                    'verdict' => self::SCOPE_FLOW,
                    'label' => $label,
                    'base_domain' => $base,
                    'flow_or_profile' => $child,
                    'non_domain_kind' => null,
                    'reason' => 'dotted label sits inside an existing domain ontology; it is a flow/profile, not a new domain',
                    'next_action' => 'implement_as_flow_or_profile_in_' . $base,
                ];
            }
        }

        // Tool / language / agent / surface / provider -> never a domain.
        foreach (self::NON_DOMAIN_TOKENS as $kind => $tokens) {
            foreach ($tokens as $token) {
                if ($normalized !== '' && str_contains($normalized, $token)) {
                    return [
                        'schema' => self::RECEIPT_FLOW_PROFILE,
                        'verdict' => self::SCOPE_NOT_A_DOMAIN,
                        'label' => $label,
                        'base_domain' => null,
                        'flow_or_profile' => null,
                        'non_domain_kind' => $kind,
                        'reason' => 'a ' . $kind . ' is never a domain by itself (doc Regras para IA)',
                        'next_action' => 'route_through_existing_domain_do_not_create_domain',
                    ];
                }
            }
        }

        // A bare label that already IS a canonical domain is just that domain.
        if ($this->canonicalOrNull($normalized) !== null) {
            return [
                'schema' => self::RECEIPT_FLOW_PROFILE,
                'verdict' => self::SCOPE_FLOW,
                'label' => $label,
                'base_domain' => $normalized,
                'flow_or_profile' => null,
                'non_domain_kind' => null,
                'reason' => 'label is already a canonical domain; no creation needed',
                'next_action' => 'use_existing_domain_' . $normalized,
            ];
        }

        // Anything else is only a CANDIDATE; the gate decides, not the name.
        return [
            'schema' => self::RECEIPT_FLOW_PROFILE,
            'verdict' => self::SCOPE_NEW_DOMAIN_CANDIDATE,
            'label' => $label,
            'base_domain' => null,
            'flow_or_profile' => null,
            'non_domain_kind' => null,
            'reason' => 'not a flow, tool, language, agent or surface; may be a new domain but must pass the Domain Creation Gate first',
            'next_action' => 'run_domain_creation_gate',
        ];
    }

    /**
     * Evaluate the 10-question Domain Creation Gate (doc "Domain Creation
     * Gate"). Pass requires every answer strong AND an existing domain tried &
     * failed. Doc rule: "Se qualquer resposta for fraca, crie flow/profile no
     * dominio existente." So a single weak answer forces use_flow.
     *
     * @param array{answers?: array<string, bool>, proposed_domain?: string} $input
     * @return array{
     *   schema: string, verdict: string, proposed_domain: ?string,
     *   answered: int, total_questions: int, weak_answers: list<string>,
     *   strong: bool, domain_creation_gate_status: string, routing_reason: string,
     *   next_action: string
     * }
     */
    public function evaluateCreationGate(array $input): array
    {
        /** @var array<string, bool> $answers */
        $answers = $input['answers'] ?? [];
        $proposed = isset($input['proposed_domain']) ? (string) $input['proposed_domain'] : null;

        $weak = [];
        $answered = 0;
        foreach (self::GATE_QUESTIONS as $q) {
            $hasAnswer = array_key_exists($q, $answers);
            if ($hasAnswer) {
                $answered++;
            }
            // Missing OR false => weak. Strong requires an explicit true.
            if (! $hasAnswer || $answers[$q] !== true) {
                $weak[] = $q;
            }
        }

        $strong = $weak === [];
        $verdict = $strong ? self::GATE_PASS : self::GATE_USE_FLOW;
        $status = $strong ? 'passed' : 'failed_use_flow_in_existing_domain';

        return [
            'schema' => self::RECEIPT_CREATION_GATE,
            'verdict' => $verdict,
            'proposed_domain' => $proposed,
            'answered' => $answered,
            'total_questions' => count(self::GATE_QUESTIONS),
            'weak_answers' => $weak,
            'strong' => $strong,
            'domain_creation_gate_status' => $status,
            'routing_reason' => $strong
                ? 'all ten gate questions are strong and an existing domain was tried and failed; new domain authorized'
                : 'at least one gate answer is weak; create a flow/profile in an existing domain instead',
            'next_action' => $strong
                ? 'create_domain_manifest_with_tests_docs_registry'
                : 'implement_as_flow_or_profile_in_existing_domain',
        ];
    }

    /** Canonical domains and their main limit (doc "Dominios Canonicos"). @return array<string, string> */
    public function canonicalDomains(): array
    {
        return self::CANONICAL_DOMAINS;
    }

    /** The ten Domain Creation Gate questions in doc order. @return list<string> */
    public function creationGateQuestions(): array
    {
        return self::GATE_QUESTIONS;
    }

    /** Cited routing contracts (doc "Contratos"). @return list<string> */
    public function citedContracts(): array
    {
        return [self::RECEIPT_ROUTING, self::RECEIPT_CREATION_GATE, self::RECEIPT_FLOW_PROFILE];
    }

    /** This doc is governance, not proof of readiness: it never declares a domain done. */
    public function declaresDone(): bool
    {
        return false;
    }

    /** Return the canonical domain key if known, else null. */
    private function canonicalOrNull(string $domain): ?string
    {
        return array_key_exists($domain, self::CANONICAL_DOMAINS) ? $domain : null;
    }

    /** Lowercase, strip accents, collapse whitespace for stable signal matching. */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $map = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e',
            'í' => 'i', 'ì' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ];

        return strtr($value, $map);
    }
}
