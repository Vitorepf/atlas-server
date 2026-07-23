<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Dev Efficient Programming Flow v1 · Parte 1 — pure, deterministic
 * decider for the normative slice covering sections 1–8 of the flow doc
 * (Papel no Atlas, Posicionamento, Escopo Canonico, Surface-Agnostic principle,
 * Pipeline Completo).
 *
 * This service does NOT execute anything: no provider call, no command, no
 * codebase mutation, no database. It encodes the documented high-level contract
 * as a closed set of typed decisions so an agent (or any caller) can ask:
 *   - is this request inside Atlas Dev, or must it be delegated/escalated, and to
 *     which flow? (§1.3 Escopo Canonico + boundary rules)
 *   - which Atlas AI flow owns a given out-of-scope request kind? (§1.2 fan-out)
 *   - may a core (non-Surface) service know about a surface, and may ui_hints
 *     decide route/risk/provider/scope/gate/completion? (§5.2 Surface-Agnostic)
 *   - what is the canonical pipeline stage order, and which RoutingDecision
 *     branches exist? (§8 Pipeline Completo)
 *
 * Documented rules this code actually enforces (one-to-one with the doc):
 *   - §1.3: the "Dentro do Atlas Dev (executa)" column is a closed set of
 *     workspace-bound request kinds; anything else is delegated via
 *     `delegate_to_other_flow` (to the documented destination flow) or, for the
 *     forge-preview rows, escalated via `escalate_forge`. A workspace-bound
 *     request needs BOTH a resolved workspace AND a concrete file/symbol/test
 *     intent; a request with no workspace can never be `atlas_dev_fast_path`.
 *   - §1.3: a workspace-bound QUESTION stays in Atlas Dev even with no patch
 *     (it depends on Code Discovery + real repo); a conceptual question with no
 *     workspace delegates to Atlas Research/Explain.
 *   - §1.3: when `flow_origin=atlas_ai_router` the request arrived pre-classified
 *     and Atlas Dev only validates that the kind falls in "Dentro"; when
 *     `flow_origin=direct` (legacy CLI/API) Atlas Dev must itself check the table
 *     and delegate if it is not workspace development.
 *   - §5.2: no service under
 *     AtlasDev/{Schemas,Discovery,PromptProjection,Pipeline,Provider,Gate,Repair,
 *     Escalation,Persistence,Telemetry} may know Desktop/CLI/App/API — only
 *     AtlasDev/Surface/ may; the core accepts only OperationEnvelope and returns
 *     only PlanOnlyResult|PatchResult; `ui_hints` is a derived projection that
 *     NEVER decides route, risk, provider, scope, gate or completion.
 *   - §8: the canonical pipeline stage order, with exactly three RoutingDecision
 *     branches (read_only_answer, atlas_dev_fast_path, forge_promotion_preview)
 *     and a Sonnet provider lock with no fallback at the ProviderDecision stage.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-01.md
 */
final class AtlasDevEfficientProgrammingFlowV1Part01Service
{
    /** Stable decision kind this decider emits. */
    public const DECISION_KIND = 'atlas_dev.efficient_programming_flow.v1.part_01';

    /** §1.3 — routing verdicts this decider can emit. */
    public const ROUTE_FAST_PATH = 'atlas_dev_fast_path';

    public const ROUTE_DELEGATE = 'delegate_to_other_flow';

    public const ROUTE_ESCALATE_FORGE = 'escalate_forge';

    /** §1.3 — the only two recognised request origins. */
    public const ORIGIN_ROUTER = 'atlas_ai_router';

    public const ORIGIN_DIRECT = 'direct';

    /**
     * §5.2 — the core (surface-agnostic) namespace segments. A service living in
     * any of these may NEVER know about a concrete surface.
     *
     * @var list<string>
     */
    public const CORE_SEGMENTS = [
        'Schemas',
        'Discovery',
        'PromptProjection',
        'Pipeline',
        'Provider',
        'Gate',
        'Repair',
        'Escalation',
        'Persistence',
        'Telemetry',
    ];

    /** §5.2 — the only namespace segment allowed to know about a surface. */
    public const SURFACE_SEGMENT = 'Surface';

    /**
     * §5.2 — the closed set of decisions `ui_hints` is forbidden from making. It
     * is always a derived projection of canonical artefacts.
     *
     * @var list<string>
     */
    public const UI_HINTS_FORBIDDEN_DECISIONS = [
        'route',
        'risk',
        'provider',
        'scope',
        'gate',
        'completion',
    ];

    /**
     * §1.3 — the "Escopo Canonico" boundary table. Each request kind maps to the
     * route it takes and, when delegated/escalated, the destination flow.
     *
     * `in_scope` rows are executed by Atlas Dev (`atlas_dev_fast_path`); their
     * `target_flow` is the Atlas Dev flow itself. Out-of-scope rows carry the
     * documented destination flow and route either to `delegate_to_other_flow`
     * (a sibling Atlas AI flow) or `escalate_forge` (the forge-preview rows).
     *
     * @return array<string, array{in_scope:bool, route:string, target_flow:string, note:string}>
     */
    public function scopeTable(): array
    {
        return [
            // Dentro do Atlas Dev (executa) — workspace-bound.
            'patch' => [
                'in_scope' => true,
                'route' => self::ROUTE_FAST_PATH,
                'target_flow' => 'atlas_dev',
                'note' => 'patch em workspace (1-6 arquivos, scope contract)',
            ],
            'repair' => [
                'in_scope' => true,
                'route' => self::ROUTE_FAST_PATH,
                'target_flow' => 'atlas_dev',
                'note' => 'repair de teste/gate falhando com workspace ativo',
            ],
            'refactor_light' => [
                'in_scope' => true,
                'route' => self::ROUTE_FAST_PATH,
                'target_flow' => 'atlas_dev',
                'note' => 'refactor leve com scope contract',
            ],
            'code_generation' => [
                'in_scope' => true,
                'route' => self::ROUTE_FAST_PATH,
                'target_flow' => 'atlas_dev',
                'note' => 'code generation criando/alterando arquivos no workspace',
            ],
            'review_in_workspace' => [
                'in_scope' => true,
                'route' => self::ROUTE_FAST_PATH,
                'target_flow' => 'atlas_dev',
                'note' => 'review de diff/codigo existente ligado ao workspace',
            ],
            'frontend_pontual' => [
                'in_scope' => true,
                'route' => self::ROUTE_FAST_PATH,
                'target_flow' => 'atlas_dev',
                'note' => 'frontend pontual com componente/arquivo identificavel',
            ],
            'workspace_question' => [
                'in_scope' => true,
                'route' => self::ROUTE_FAST_PATH,
                'target_flow' => 'atlas_dev',
                'note' => 'pergunta workspace-bound ("onde esta X no repo?")',
            ],
            'multi_file_edit_in_scope' => [
                'in_scope' => true,
                'route' => self::ROUTE_FAST_PATH,
                'target_flow' => 'atlas_dev',
                'note' => 'multi-file edit dentro de scope contract (max 5-6 arquivos)',
            ],

            // Fora do Atlas Dev (delega) — sibling Atlas AI flows.
            'conceptual_research' => [
                'in_scope' => false,
                'route' => self::ROUTE_DELEGATE,
                'target_flow' => 'atlas_research',
                'note' => 'pesquisa conceitual sem workspace',
            ],
            'broad_explanation' => [
                'in_scope' => false,
                'route' => self::ROUTE_DELEGATE,
                'target_flow' => 'atlas_explain',
                'note' => 'explicacao ampla sem patch alvo no repo',
            ],
            'standalone_debug' => [
                'in_scope' => false,
                'route' => self::ROUTE_DELEGATE,
                'target_flow' => 'atlas_debug',
                'note' => 'debug standalone sem alvo de codigo concreto',
            ],
            'exploratory_chat' => [
                'in_scope' => false,
                'route' => self::ROUTE_DELEGATE,
                'target_flow' => 'atlas_conversation',
                'note' => 'chat exploratorio amplo / brainstorm',
            ],
            'deep_pr_review' => [
                'in_scope' => false,
                'route' => self::ROUTE_DELEGATE,
                'target_flow' => 'atlas_review',
                'note' => 'review profundo de PR/diff fora de workspace ativo',
            ],

            // Fora do Atlas Dev — Obra / Forge preview rows escalate.
            'obra_long' => [
                'in_scope' => false,
                'route' => self::ROUTE_ESCALATE_FORGE,
                'target_flow' => 'atlas_forge',
                'note' => 'Obra longa, multiagente, semanas/mes',
            ],
            'architecture_redesign' => [
                'in_scope' => false,
                'route' => self::ROUTE_ESCALATE_FORGE,
                'target_flow' => 'atlas_forge_preview',
                'note' => 'redesenho arquitetural amplo sem patch imediato',
            ],
            'sensitive_change' => [
                'in_scope' => false,
                'route' => self::ROUTE_ESCALATE_FORGE,
                'target_flow' => 'atlas_forge_preview',
                'note' => 'mudanca em auth/billing/migration/security/production',
            ],
        ];
    }

    /**
     * §1.3 — classify a request against the Escopo Canonico table and the
     * boundary rules.
     *
     * Boundary rules enforced:
     *   - An in-scope kind is `atlas_dev_fast_path` ONLY when it is workspace-bound
     *     (a resolved workspace AND a concrete file/symbol/test intent). An
     *     in-scope kind without a workspace cannot fast-path; it delegates to
     *     Atlas Research (conceptual questions) — never silently executes.
     *   - A `workspace_question` is in scope even with no patch, but still requires
     *     the workspace to be resolved; without a workspace it is a conceptual
     *     question and delegates to Atlas Research.
     *   - An out-of-scope kind keeps its documented route/target from the table.
     *   - `flow_origin=atlas_ai_router` means the request is pre-classified: Atlas
     *     Dev only validates the kind is "Dentro" and trusts the routing.
     *   - `flow_origin=direct` (legacy CLI/API) means Atlas Dev must check the
     *     table itself.
     *
     * @param  string  $requestKind   one of the scopeTable() keys
     * @param  bool  $workspaceResolved  is a workspace resolved for this request?
     * @param  bool  $concreteCodeIntent  is there a concrete file/symbol/test intent?
     * @param  string  $flowOrigin    'atlas_ai_router' (pre-classified) or 'direct'
     * @return array{
     *   request_kind:string, known:bool, in_scope:bool, route:string,
     *   target_flow:string, workspace_bound:bool, flow_origin:string,
     *   trusted_preclassified:bool, reason:string
     * }
     */
    public function classifyRequest(
        string $requestKind,
        bool $workspaceResolved,
        bool $concreteCodeIntent,
        string $flowOrigin = self::ORIGIN_DIRECT
    ): array {
        $table = $this->scopeTable();
        $known = array_key_exists($requestKind, $table);
        $origin = in_array($flowOrigin, [self::ORIGIN_ROUTER, self::ORIGIN_DIRECT], true)
            ? $flowOrigin
            : self::ORIGIN_DIRECT;
        $trusted = $origin === self::ORIGIN_ROUTER;
        $workspaceBound = $workspaceResolved && $concreteCodeIntent;

        // Unknown kind fails safe: never execute, delegate the broadest sibling.
        if (! $known) {
            return [
                'request_kind' => 'unknown',
                'known' => false,
                'in_scope' => false,
                'route' => self::ROUTE_DELEGATE,
                'target_flow' => 'atlas_ai_router',
                'workspace_bound' => $workspaceBound,
                'flow_origin' => $origin,
                'trusted_preclassified' => $trusted,
                'reason' => 'unknown_request_kind_defers_to_router',
            ];
        }

        $row = $table[$requestKind];

        // Out-of-scope row: keep its documented route/target.
        if (! $row['in_scope']) {
            return [
                'request_kind' => $requestKind,
                'known' => true,
                'in_scope' => false,
                'route' => $row['route'],
                'target_flow' => $row['target_flow'],
                'workspace_bound' => $workspaceBound,
                'flow_origin' => $origin,
                'trusted_preclassified' => $trusted,
                'reason' => $row['route'] === self::ROUTE_ESCALATE_FORGE
                    ? 'out_of_scope_escalates_to_forge'
                    : 'out_of_scope_delegates_to_sibling_flow',
            ];
        }

        // In-scope row but NOT workspace-bound: a conceptual request with no real
        // repo target must delegate to Research, never fast-path.
        if (! $workspaceBound) {
            return [
                'request_kind' => $requestKind,
                'known' => true,
                'in_scope' => true,
                'route' => self::ROUTE_DELEGATE,
                'target_flow' => 'atlas_research',
                'workspace_bound' => false,
                'flow_origin' => $origin,
                'trusted_preclassified' => $trusted,
                'reason' => 'in_scope_kind_but_not_workspace_bound_delegates_to_research',
            ];
        }

        // In-scope AND workspace-bound: Atlas Dev executes the fast path.
        return [
            'request_kind' => $requestKind,
            'known' => true,
            'in_scope' => true,
            'route' => self::ROUTE_FAST_PATH,
            'target_flow' => 'atlas_dev',
            'workspace_bound' => true,
            'flow_origin' => $origin,
            'trusted_preclassified' => $trusted,
            'reason' => $trusted
                ? 'preclassified_in_scope_workspace_bound_validated'
                : 'direct_in_scope_workspace_bound_executes_fast_path',
        ];
    }

    /**
     * §5.2 — Surface-Agnostic invariant for a service path. A service in any core
     * segment may NOT know about a surface; only a service in the Surface segment
     * may. Knowledge of a surface in a core service is a violation.
     *
     * @param  string  $serviceSegment  the AtlasDev sub-namespace segment (e.g. 'Pipeline', 'Surface')
     * @param  bool  $knowsSurface      does the service reference Desktop/CLI/App/API?
     * @return array{
     *   segment:string, is_core:bool, surface_knowledge_allowed:bool,
     *   knows_surface:bool, violates:bool, reason:string
     * }
     */
    public function surfaceAgnosticCheck(string $serviceSegment, bool $knowsSurface): array
    {
        $isCore = in_array($serviceSegment, self::CORE_SEGMENTS, true);
        $isSurface = $serviceSegment === self::SURFACE_SEGMENT;
        $allowed = $isSurface;
        $violates = $knowsSurface && ! $allowed;

        if ($isSurface) {
            $reason = 'surface_segment_may_know_surface';
        } elseif ($isCore) {
            $reason = $violates
                ? 'core_segment_must_not_know_surface'
                : 'core_segment_correctly_surface_agnostic';
        } else {
            // Unlisted segment: still not allowed to know a surface unless it is Surface.
            $reason = $violates
                ? 'non_surface_segment_must_not_know_surface'
                : 'segment_outside_documented_set_treated_as_core';
        }

        return [
            'segment' => $serviceSegment,
            'is_core' => $isCore,
            'surface_knowledge_allowed' => $allowed,
            'knows_surface' => $knowsSurface,
            'violates' => $violates,
            'reason' => $reason,
        ];
    }

    /**
     * §5.2 — may `ui_hints` make this decision? Never, for any of the six
     * forbidden decisions; ui_hints is a derived render projection only.
     *
     * @return array{decision:string, allowed:bool, reason:string}
     */
    public function uiHintsMayDecide(string $decision): array
    {
        $forbidden = in_array($decision, self::UI_HINTS_FORBIDDEN_DECISIONS, true);

        return [
            'decision' => $decision,
            'allowed' => ! $forbidden,
            'reason' => $forbidden
                ? 'ui_hints_is_derived_projection_and_never_decides_'.$decision
                : 'decision_not_in_ui_hints_forbidden_set',
        ];
    }

    /**
     * §5.2 — the core contract surface: the single input type and the closed set
     * of output result types of the Atlas Dev core.
     *
     * @return array{input:string, outputs:list<string>}
     */
    public function coreContract(): array
    {
        return [
            'input' => 'OperationEnvelope',
            'outputs' => ['PlanOnlyResult', 'PatchResult'],
        ];
    }

    /**
     * §8 — the canonical ordered pipeline stages (Pipeline Completo). The order
     * is normative; downstream callers must not reorder it.
     *
     * @return list<string>
     */
    public function pipelineStages(): array
    {
        return [
            'operation_envelope',
            'intake_normalizado',
            'workspace_permission_preflight',
            'classificacao_de_tarefa',
            'risk_level_r0_r5',
            'scope_mode_compact_or_structural',
            'doc_context_tier_selector',
            'code_discovery_manifest',
            'open_brain_programming_projection',
            'compact_sdd',
            'mini_programming_spec',
            'light_task_contract',
            'provider_prompt_projection',
            'routing_decision',
            'provider_decision',
            'short_plan',
            'scoped_execution',
            'patch_or_no_patch_reason',
            'scope_guard_receipt',
            'focused_verification',
            'verification_receipt',
            'cheap_repair',
            'completion_state',
            'escalation_decision',
            'fast_path_telemetry',
            'fast_path_error_ledger_entry',
            'learning_cartography_proposal',
        ];
    }

    /**
     * §8 — the three RoutingDecision branches that fan out of the
     * `routing_decision` stage.
     *
     * @return list<string>
     */
    public function routingBranches(): array
    {
        return [
            'read_only_answer',
            self::ROUTE_FAST_PATH,
            'forge_promotion_preview',
        ];
    }

    /**
     * §8 — is `before` immediately followed by `after` in the canonical pipeline?
     * Also reports the documented index of each stage. An unknown stage is -1.
     *
     * @return array{before:string, after:string, before_index:int, after_index:int, ordered:bool, reason:string}
     */
    public function pipelineOrder(string $before, string $after): array
    {
        $stages = $this->pipelineStages();
        $beforeIndex = array_search($before, $stages, true);
        $afterIndex = array_search($after, $stages, true);
        $bi = $beforeIndex === false ? -1 : $beforeIndex;
        $ai = $afterIndex === false ? -1 : $afterIndex;

        if ($bi === -1 || $ai === -1) {
            $reason = 'unknown_stage';
            $ordered = false;
        } elseif ($ai === $bi + 1) {
            $reason = 'stages_are_adjacent_in_documented_order';
            $ordered = true;
        } else {
            $reason = 'stages_not_adjacent_in_documented_order';
            $ordered = false;
        }

        return [
            'before' => $before,
            'after' => $after,
            'before_index' => $bi,
            'after_index' => $ai,
            'ordered' => $ordered,
            'reason' => $reason,
        ];
    }

    /**
     * §8 — provider lock at the ProviderDecision stage: Sonnet, no fallback, fixed
     * per run (Atlas Decide may change the lock between runs, never within a run).
     *
     * @return array{provider_lock:string, fallback_allowed:bool, lock_scope:string, may_change_between_runs:bool}
     */
    public function providerLock(): array
    {
        return [
            'provider_lock' => 'sonnet',
            'fallback_allowed' => false,
            'lock_scope' => 'per_run',
            'may_change_between_runs' => true,
        ];
    }

    /**
     * Stable manifest of the slice this decider governs (for the command/probe).
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return [
            'decision_kind' => self::DECISION_KIND,
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-01.md',
            'sections' => [
                '1_papel_no_atlas',
                '1_2_posicionamento_dentro_do_atlas_ai',
                '1_3_escopo_canonico_do_fluxo',
                '5_2_principio_surface_agnostic',
                '8_pipeline_completo',
            ],
            'routes' => [self::ROUTE_FAST_PATH, self::ROUTE_DELEGATE, self::ROUTE_ESCALATE_FORGE],
            'in_scope_kinds' => array_values(array_keys(array_filter(
                $this->scopeTable(),
                static fn (array $row): bool => $row['in_scope'] === true
            ))),
            'core_contract' => $this->coreContract(),
            'core_segments' => self::CORE_SEGMENTS,
            'ui_hints_forbidden_decisions' => self::UI_HINTS_FORBIDDEN_DECISIONS,
            'pipeline_stage_count' => count($this->pipelineStages()),
            'routing_branches' => $this->routingBranches(),
            'provider_lock' => $this->providerLock(),
        ];
    }
}
