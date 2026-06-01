<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Core Vs Domain — founding placement decider runtime.
 *
 * Turns the founding doc "atlas-ai-core-vs-domain.md" into pure, deterministic
 * decision logic. The doc answers one question: a new capability must live in
 * the Core, in a Domain, or in a Surface? Rather than restating the prose, this
 * service enforces exactly the decidable contracts the doc states:
 *
 *  - Regra Rapida (the 3-way placement test). "Serve para mais de um domain ou
 *    surface? Core. Depende de criterios especializados de uma vertical? Domain.
 *    So coleta input ou mostra output? Surface." placeCapability() decides this:
 *    a capability that serves more than one domain OR more than one surface goes
 *    to the Core; one that only collects input / renders output is a Surface;
 *    one bound to a single vertical's specialized criteria is a Domain.
 *
 *  - Teste De Decisao, question 6 ("Se isso ficar no comando atual, outro
 *    comando vai precisar copiar depois? Se sim, a feature nao pertence ao
 *    comando."). When a capability would otherwise be Surface/Domain-scoped but
 *    a second command would have to copy it, the placement escalates to Core.
 *
 *  - Profile contract ("Um flow profile pode escolher modelos, tools e gates. Um
 *    modelo nunca deve definir o flow profile."). resolveFlowForCommand() maps
 *    `atlas dev` -> programming.dev, `atlas forge` -> programming.forge and
 *    `atlas fix` -> programming.dev|programming.forge with intent `repair`
 *    depending on risk. isModelProfile()/assertModelDoesNotDefineFlow() refuse
 *    to let a model profile (opus, codex-high, gemini-scout) act as a flow.
 *
 *  - Domain status (the "Status operacional atual" list + table). programming,
 *    finance, personal_development and self_improvement are implemented/ready;
 *    marketing, research, health, learning, writing, qa, security, operations,
 *    background and general are scaffold/catalog-ready until they have their own
 *    runtime/orchestrator. domainStatus() returns this and never reports
 *    marketing as implemented/ready.
 *
 *  - Business Context rule. blackink is a Business Context / Product Domain, not
 *    an Atlas AI cognitive domain. classifyContextOrDomain() keeps them apart.
 *
 *  - Surface prohibitions ("Surface nao deve: escolher provider fora da policy;
 *    montar context pack proprio; executar tool diretamente sem runtime quando
 *    existe registry; declarar sucesso sem evidence; implementar repair proprio;
 *    guardar memoria canonica."). auditSurfaceAction() blocks each.
 *
 *  - Domain anti-reimplementation ("Domain nao deve reimplementar input
 *    multimodal, provider routing, memory core, tool runtime ou telemetry.").
 *    auditDomainOwnership() routes any of those back to the Core owner.
 *
 *  - Regra Final. Architectural duplication is not only repeated code: it is
 *    also different metadata / gate / repair / context / evidence for the same
 *    concept. duplicationSignals() enumerates the documented signals.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
 */
final class AtlasAiCoreVsDomainService
{
    public const SCHEMA_VERSION = 'atlas.ai.core_vs_domain.v1';

    public const LAYER_CORE = 'core';

    public const LAYER_DOMAIN = 'domain';

    public const LAYER_SURFACE = 'surface';

    /**
     * Domains the doc marks implemented/ready (have their own runtime today).
     *
     * @var list<string>
     */
    public const READY_DOMAINS = [
        'programming',
        'finance',
        'personal_development',
        'self_improvement',
    ];

    /**
     * Domains the doc marks scaffold/catalog-ready (no own runtime yet). The doc
     * is explicit that marketing belongs here and must NOT be treated as ready.
     *
     * @var list<string>
     */
    public const SCAFFOLD_DOMAINS = [
        'marketing',
        'research',
        'health',
        'learning',
        'writing',
        'qa',
        'security',
        'operations',
        'background',
        'general',
    ];

    /**
     * Model profiles per the Profile table. A model profile may be SELECTED by a
     * flow profile but must never define one.
     *
     * @var list<string>
     */
    public const MODEL_PROFILES = [
        'opus',
        'codex-high',
        'gemini-scout',
    ];

    /**
     * Capabilities the Core owns; a Domain must never reimplement these
     * (doc: "Domain nao deve reimplementar input multimodal, provider routing,
     * memory core, tool runtime ou telemetry.").
     *
     * @var array<string,string>
     */
    public const CORE_OWNED = [
        'input_multimodal' => 'Atlas.Input',
        'provider_routing' => 'Atlas.Provider',
        'provider_selection' => 'Atlas.Policy',
        'memory_core' => 'Atlas.Memory',
        'tool_runtime' => 'Atlas.Tools',
        'telemetry' => 'Atlas.Evidence',
        'context_pack' => 'Atlas.Context',
    ];

    /**
     * Actions a Surface is forbidden from owning, mapped to the Core/Domain owner
     * that must own them instead (doc "Surface nao deve").
     *
     * @var array<string,string>
     */
    public const SURFACE_FORBIDDEN = [
        'choose_provider' => 'Atlas.Policy',
        'build_context_pack' => 'Atlas.Context',
        'execute_tool_directly' => 'Atlas.Tools',
        'declare_success_without_evidence' => 'Atlas.Evidence',
        'implement_repair' => 'Atlas.Executor',
        'store_canonical_memory' => 'Atlas.Memory',
    ];

    /**
     * Decide where a candidate capability belongs: Core, Domain or Surface.
     *
     * Implements the Regra Rapida plus the Teste De Decisao question 6 escalation.
     *
     * @param  array{
     *     serves_multiple_domains?: bool,
     *     serves_multiple_surfaces?: bool,
     *     domain_count?: int,
     *     surface_count?: int,
     *     only_collects_input_or_renders_output?: bool,
     *     depends_on_vertical_criteria?: bool,
     *     vertical?: string|null,
     *     another_command_would_copy?: bool,
     *     creates_state_evidence_or_memory?: bool,
     * }  $signals
     * @return array<string,mixed>
     */
    public function placeCapability(array $signals): array
    {
        $domainCount = (int) ($signals['domain_count'] ?? 0);
        $surfaceCount = (int) ($signals['surface_count'] ?? 0);
        $servesMultiDomain = (bool) ($signals['serves_multiple_domains'] ?? ($domainCount > 1));
        $servesMultiSurface = (bool) ($signals['serves_multiple_surfaces'] ?? ($surfaceCount > 1));
        $onlyIo = (bool) ($signals['only_collects_input_or_renders_output'] ?? false);
        $verticalBound = (bool) ($signals['depends_on_vertical_criteria'] ?? false);
        $wouldBeCopied = (bool) ($signals['another_command_would_copy'] ?? false);

        // Regra Rapida, line 1: serves more than one domain OR surface -> Core.
        if ($servesMultiDomain || $servesMultiSurface) {
            return $this->decision(self::LAYER_CORE, 'regra_rapida_serves_more_than_one_domain_or_surface', [
                'serves_multiple_domains' => $servesMultiDomain,
                'serves_multiple_surfaces' => $servesMultiSurface,
            ]);
        }

        // Teste De Decisao, Q6: if leaving it in the current command would force
        // another command to copy it, it does not belong to the command -> Core.
        if ($wouldBeCopied) {
            return $this->decision(self::LAYER_CORE, 'teste_de_decisao_q6_another_command_would_copy', [
                'rule' => 'feature_does_not_belong_to_the_command',
            ]);
        }

        // Regra Rapida, line 2: bound to a single vertical's specialized criteria
        // -> Domain. This outranks "only IO" because a vertical criterion is
        // semantic, not a pure input/output concern.
        if ($verticalBound) {
            $vertical = $this->normalizeVertical($signals['vertical'] ?? null);

            return $this->decision(self::LAYER_DOMAIN, 'regra_rapida_depends_on_specialized_vertical_criteria', [
                'vertical' => $vertical,
                'vertical_status' => $vertical === null ? null : $this->domainStatus($vertical)['status'],
            ]);
        }

        // Regra Rapida, line 3: only collects input or shows output -> Surface.
        if ($onlyIo) {
            return $this->decision(self::LAYER_SURFACE, 'regra_rapida_only_collects_input_or_renders_output', [
                'state_warning' => (bool) ($signals['creates_state_evidence_or_memory'] ?? false)
                    ? 'surface_must_not_create_state_evidence_or_memory'
                    : null,
            ]);
        }

        // Nothing matched: ambiguous. The doc's Teste De Decisao must be answered
        // before creating the feature; do not guess a vertical owner.
        return $this->decision(self::LAYER_CORE, 'unresolved_default_to_core_until_teste_de_decisao_answered', [
            'ambiguous' => true,
            'next' => 'answer_teste_de_decisao_questions_before_implementation',
        ]);
    }

    /**
     * Resolve the canonical flow profile for an Atlas command surface.
     *
     * doc: `atlas dev` -> programming.dev; `atlas forge` -> programming.forge;
     * `atlas fix` -> programming.dev or programming.forge with intent `repair`,
     * depending on risk.
     *
     * @return array<string,mixed>
     */
    public function resolveFlowForCommand(string $command, string $risk = 'low'): array
    {
        $command = strtolower(trim($command));
        $risk = strtolower(trim($risk));
        // High-risk repair work belongs to the heavier forge flow; otherwise dev.
        $highRisk = in_array($risk, ['high', 'critical', 'heavy'], true);

        return match ($command) {
            'dev', 'atlas dev' => [
                'flow_profile' => 'programming.dev',
                'intent' => 'develop',
                'domain' => 'programming',
            ],
            'forge', 'atlas forge' => [
                'flow_profile' => 'programming.forge',
                'intent' => 'forge',
                'domain' => 'programming',
            ],
            'fix', 'atlas fix' => [
                'flow_profile' => $highRisk ? 'programming.forge' : 'programming.dev',
                'intent' => 'repair',
                'domain' => 'programming',
                'risk' => $highRisk ? 'high' : 'low',
            ],
            default => [
                'flow_profile' => null,
                'intent' => null,
                'domain' => null,
                'note' => 'no_canonical_mapping_declared_for_command',
            ],
        };
    }

    /**
     * doc Profile rule: "Um modelo nunca deve definir o flow profile."
     * A model profile (opus, codex-high, gemini-scout) is not a flow profile.
     */
    public function isModelProfile(string $profile): bool
    {
        return in_array(strtolower(trim($profile)), self::MODEL_PROFILES, true);
    }

    /**
     * Guard: a flow profile may select a model, but a model must never define the
     * flow. Returns an allow/deny envelope.
     *
     * @return array<string,mixed>
     */
    public function assertModelDoesNotDefineFlow(string $candidateFlowProfile): array
    {
        $isModel = $this->isModelProfile($candidateFlowProfile);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'candidate' => $candidateFlowProfile,
            'is_model_profile' => $isModel,
            'allowed_as_flow' => ! $isModel,
            'reason' => $isModel
                ? 'a_model_profile_must_never_define_a_flow_profile'
                : 'candidate_is_not_a_model_profile',
            'owner_when_model' => 'Provider/Policy',
        ];
    }

    /**
     * Operational status of a domain per the doc's "Status operacional atual".
     *
     * @return array{domain:string, status:string, ready:bool}
     */
    public function domainStatus(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if (in_array($domain, self::READY_DOMAINS, true)) {
            return ['domain' => $domain, 'status' => 'implemented_ready', 'ready' => true];
        }
        if (in_array($domain, self::SCAFFOLD_DOMAINS, true)) {
            return ['domain' => $domain, 'status' => 'scaffold_catalog_ready', 'ready' => false];
        }

        return ['domain' => $domain, 'status' => 'unknown', 'ready' => false];
    }

    /**
     * Classify a name as an Atlas AI cognitive Domain or a Business Context /
     * Product Domain. doc: blackink is a Business Context, not a domain; the
     * capabilities used to work on it (programming, marketing, finance,
     * operations, strategic_decision) are the domains.
     *
     * @return array<string,mixed>
     */
    public function classifyContextOrDomain(string $name): array
    {
        $name = strtolower(trim($name));
        // Known business contexts / products / income sources are NOT domains.
        $businessContexts = ['blackink', 'black ink'];

        if (in_array($name, $businessContexts, true)) {
            return [
                'name' => $name,
                'kind' => 'business_context',
                'is_atlas_ai_domain' => false,
                'rule' => 'business_context_or_product_domain_not_atlas_ai_domain',
                'owner_doc' => 'docs/engineering-knowledge-base/atlas-ai-business-contexts.md',
            ];
        }

        $status = $this->domainStatus($name);
        if ($status['status'] !== 'unknown') {
            return [
                'name' => $name,
                'kind' => 'atlas_ai_domain',
                'is_atlas_ai_domain' => true,
                'domain_status' => $status['status'],
                'ready' => $status['ready'],
            ];
        }

        return [
            'name' => $name,
            'kind' => 'unknown',
            'is_atlas_ai_domain' => false,
            'rule' => 'classify_explicitly_before_creating_runtime',
        ];
    }

    /**
     * Audit a Surface action against the documented Surface prohibitions.
     *
     * @return array<string,mixed>
     */
    public function auditSurfaceAction(string $action): array
    {
        $action = strtolower(trim($action));
        if (array_key_exists($action, self::SURFACE_FORBIDDEN)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'action' => $action,
                'allowed' => false,
                'rule' => 'surface_must_not_'.$action,
                'correct_owner' => self::SURFACE_FORBIDDEN[$action],
            ];
        }

        // doc: Surface may collect input, pass hints, render output, show status,
        // request operator confirmation.
        $allowed = [
            'collect_input',
            'pass_hints',
            'render_output',
            'show_status',
            'request_operator_confirmation',
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'action' => $action,
            'allowed' => in_array($action, $allowed, true),
            'rule' => in_array($action, $allowed, true)
                ? 'surface_allowed_io_only_action'
                : 'unknown_action_default_deny_until_classified',
            'correct_owner' => in_array($action, $allowed, true) ? 'surface' : null,
        ];
    }

    /**
     * Audit a Domain ownership claim. If the Domain tries to own a Core-owned
     * horizontal capability, route it back to the Core owner.
     *
     * @return array<string,mixed>
     */
    public function auditDomainOwnership(string $capability): array
    {
        $capability = strtolower(trim($capability));
        if (array_key_exists($capability, self::CORE_OWNED)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'capability' => $capability,
                'domain_may_own' => false,
                'owner_layer' => self::LAYER_CORE,
                'core_owner' => self::CORE_OWNED[$capability],
                'rule' => 'domain_must_not_reimplement_core_horizontal_capability',
            ];
        }

        // Things the doc explicitly says a Domain MAY own.
        $domainOwnable = [
            'intent_classifier',
            'context_pack_specialization',
            'harness',
            'preferred_tools',
            'gates',
            'evidence_schema_addition',
            'memory_projection',
            'repair_escalation',
            'privacy_rules',
            'flow_profiles',
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capability' => $capability,
            'domain_may_own' => in_array($capability, $domainOwnable, true),
            'owner_layer' => in_array($capability, $domainOwnable, true) ? self::LAYER_DOMAIN : 'unknown',
            'rule' => in_array($capability, $domainOwnable, true)
                ? 'domain_ownable_specialized_capability'
                : 'classify_capability_before_assigning_owner',
        ];
    }

    /**
     * The documented worked examples (doc "Exemplos" table). Resolves a known
     * case to its owner layer + owner module + reason. Returns null for unknown.
     *
     * @return array<string,string>|null
     */
    public function examplePlacement(string $case): ?array
    {
        return match (strtolower(trim($case))) {
            'paste_image', 'paste de imagem' => [
                'layer' => self::LAYER_CORE,
                'owner' => 'Atlas.Input',
                'reason' => 'must_work_across_ask_dev_chat_app_and_future_surfaces',
            ],
            'bugfix_failing_test', 'bugfix com teste falhando' => [
                'layer' => self::LAYER_DOMAIN,
                'owner' => 'programming',
                'reason' => 'code_semantics_and_programming_gates',
            ],
            'choose_provider', 'escolher provider' => [
                'layer' => self::LAYER_CORE,
                'owner' => 'Atlas.Policy/Atlas.Provider',
                'reason' => 'provider_is_engine_not_domain',
            ],
            'migration_review', 'review de migration' => [
                'layer' => self::LAYER_DOMAIN,
                'owner' => 'programming',
                'reason' => 'specialized_database_gate',
            ],
            'secret_scan' => [
                'layer' => self::LAYER_CORE,
                'owner' => 'Atlas.Tools+programming_gate',
                'reason' => 'tool_is_horizontal_criteria_lives_in_domain',
            ],
            'sleep_routine', 'rotina de sono' => [
                'layer' => self::LAYER_DOMAIN,
                'owner' => 'personal_development',
                'reason' => 'personal_semantics_privacy_and_measurement',
            ],
            'finance_operation', 'operacao financeira' => [
                'layer' => self::LAYER_DOMAIN,
                'owner' => 'finance',
                'reason' => 'risk_compliance_and_data_provenance',
            ],
            'service_duplication_detection', 'detectar duplicacao de services' => [
                'layer' => self::LAYER_DOMAIN,
                'owner' => 'curation',
                'reason' => 'meta_task_over_atlas_itself',
            ],
            default => null,
        };
    }

    /**
     * doc "Regra Final": architectural duplication is not only repeated code.
     * Enumerates the documented duplication signals; each must resolve to a
     * single owner.
     *
     * @return list<string>
     */
    public function duplicationSignals(): array
    {
        return [
            'different_metadata_for_the_same_concept',
            'different_gate_for_the_same_risk',
            'different_repair_for_the_same_failure',
            'different_context_for_the_same_task',
            'different_evidence_for_the_same_result',
        ];
    }

    private function normalizeVertical(mixed $vertical): ?string
    {
        if (! is_string($vertical)) {
            return null;
        }
        $vertical = strtolower(trim($vertical));

        return $vertical === '' ? null : $vertical;
    }

    /**
     * @param  array<string,mixed>  $detail
     * @return array<string,mixed>
     */
    private function decision(string $layer, string $reason, array $detail = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'layer' => $layer,
            'reason' => $reason,
            'detail' => $detail,
        ];
    }
}
