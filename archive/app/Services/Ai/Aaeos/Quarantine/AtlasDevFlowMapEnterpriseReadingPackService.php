<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Atlas Dev Flow Map And Product Options v1 · Parte 2 — pure, deterministic
 * decider for the "Pacote De Leitura Enterprise" contract.
 *
 * This service does NOT execute anything: no provider call, no command, no
 * filesystem touch, no database. It encodes the documented reading-pack contract
 * so any caller (Atlas Dev / Open Brain context-pack builder) can ask:
 *   - which named docs belong to each reading pack A..F (the canonical membership
 *     of each context tier core/sdd/interface/forge/code_intelligence/obras)?
 *   - given a task's risk level, WHICH packs must be loaded (selection by risk,
 *     not "load everything always")?
 *   - is a cited doc path a verified GAP, and if so what is the documented
 *     fallback (related existing path) — so a broken reference never silently
 *     becomes a real source in a prompt?
 *
 * Documented rules this code actually enforces (one-to-one with the doc):
 *   - "Pacote A: Nucleo Obrigatorio" .. "Pacote F: Obras" — each pack maps to a
 *     context tier and carries the exact doc list the recorte preserves.
 *   - "Atlas Dev nao deve carregar tudo sempre; deve selecionar por risco":
 *       * tarefas simples  -> Nucleo minimo + Code Intelligence compacto;
 *       * tarefas estruturais -> incluir Spec/SDD detalhado;
 *       * tarefas longas/UI/Obra -> incluir Atlas Code/Interface;
 *       * risco alto -> preparar escalada para Forge.
 *   - Verified gap: `atlas-code-work-intake-spec-governance-v1.md` is a gap /
 *     old name and MUST NOT be treated as an existing file; the related existing
 *     path is `atlas-code-forge-work-intake-spec-governance-v1.md`. The decider
 *     resolves the citation to that fallback and flags the gap, implementing the
 *     backlog item "fallback quando doc citado nao existe: related existing path
 *     + gap".
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-02.md
 */
final class AtlasDevFlowMapEnterpriseReadingPackService
{
    /** Stable decision kind this decider emits. */
    public const DECISION_KIND = 'atlas_dev.flow_map.v1.part_02.enterprise_reading_pack';

    /** Stable manifest id for the enterprise reading pack (backlog: atlas_dev_enterprise_reading_pack). */
    public const MANIFEST_ID = 'atlas_dev_enterprise_reading_pack';

    /** Pack id -> context tier id. The six tiers named in the doc's experiments. */
    public const TIER_CORE = 'core';

    public const TIER_SDD = 'sdd';

    public const TIER_INTERFACE = 'interface';

    public const TIER_FORGE = 'forge';

    public const TIER_CODE_INTELLIGENCE = 'code_intelligence';

    public const TIER_OBRAS = 'obras';

    private const DOC_ROOT = 'docs/engineering-knowledge-base/';

    /**
     * The verified gap from the doc: cited path -> documented fallback.
     * `atlas-code-work-intake-spec-governance-v1.md` does not exist at this path;
     * the related existing file is the `forge` variant.
     */
    private const GAP_CITED_PATH = 'docs/engineering-knowledge-base/atlas-code-work-intake-spec-governance-v1.md';

    private const GAP_FALLBACK_PATH = 'docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md';

    /**
     * Pack A..F. Each pack is `{tier, label, role, docs[]}`. The `docs` lists are
     * the exact membership the recorte preserves (paths relative to repo root).
     *
     * @return array<string, array{tier:string, label:string, role:string, docs:list<string>}>
     */
    public function packs(): array
    {
        $d = self::DOC_ROOT;

        return [
            'A' => [
                'tier' => self::TIER_CORE,
                'label' => 'Nucleo Obrigatorio',
                'role' => 'extract_laws_and_invariants; decide_when_spec_light_vs_forge; keep_sonnet_inside_canonical_governance',
                'docs' => [
                    $d.'atlas-ai-spec-operating-system.md',
                    $d.'atlas-programming-governance-system.md',
                    $d.'atlas-programming-governance-system-contracts.md',
                    $d.'atlas-programming-governance-system-runbook.md',
                    $d.'atlas-forge-operating-system.md',
                    $d.'atlas-forge-operating-system-contracts.md',
                    $d.'atlas-forge-operating-system-runbook.md',
                ],
            ],
            'B' => [
                'tier' => self::TIER_SDD,
                'label' => 'Specs/SDD Detalhado',
                'role' => 'human_prompt_to_mini_spec; plan_and_task_contract; traceability; drift_detection',
                'docs' => [
                    $d.'spec-operating-system/context-discovery-and-business-context.md',
                    $d.'spec-operating-system/spec-compiler-and-critic.md',
                    $d.'spec-operating-system/plan-task-and-receipt-contract.md',
                    $d.'spec-operating-system/spec-graph-and-traceability.md',
                    $d.'spec-operating-system/templates-and-schemas.md',
                    $d.'spec-operating-system/data-model-and-services.md',
                    $d.'spec-operating-system/context-packages-and-projections.md',
                    $d.'spec-operating-system/drift-detector-and-learning.md',
                ],
            ],
            'C' => [
                'tier' => self::TIER_INTERFACE,
                'label' => 'Atlas Code / Interface',
                'role' => 'cli_runtime_artifacts_visible_in_cockpit; honest_states_not_mock; checkpoint_resume_gates_evidence_scope_guard_as_real_data',
                'docs' => [
                    $d.'atlas-desktop-code-surface.md',
                    $d.'atlas-desktop-backend-contract.md',
                    $d.'atlas-code-long-session-programming-cockpit.md',
                    $d.'atlas-code-scor-1-implementation-contract.md',
                    $d.'atlas-code-programming-obras-operating-system.md',
                    $d.'atlas-code-obra-command-center-v1.md',
                    $d.'atlas-code-forge-work-intake-spec-governance-v1.md',
                ],
            ],
            'D' => [
                'tier' => self::TIER_FORGE,
                'label' => 'Forge / Execucao Avancada',
                'role' => 'escalation_criteria; avoid_recreating_multiagent_in_light_mode; reuse_completion_review_patterns_when_risk_justifies',
                'docs' => [
                    $d.'atlas-programming-forge-flow.md',
                    $d.'atlas-programming-self-construction-forge-map-v1.md',
                    $d.'atlas-code-forge-live-execution-surface-contract.md',
                    $d.'atlas-code-forge-operator-cockpit-v1.md',
                    $d.'atlas-code-forge-review-completion-gate-v1.md',
                    $d.'atlas-forge-runtime-certification-one-shot.md',
                    $d.'atlas-forge-live-execution-e2e-v1.md',
                ],
            ],
            'E' => [
                'tier' => self::TIER_CODE_INTELLIGENCE,
                'label' => 'Code Intelligence / Ferramentas',
                'role' => 'find_right_files_symbols_before_call; choose_probable_tests_commands; generate_verifiable_evidence',
                'docs' => [
                    $d.'code-intelligence.md',
                    $d.'code-intelligence/README.md',
                    $d.'code-intelligence/external-graph-harness.md',
                    $d.'programming-power-tools-catalog.md',
                    $d.'tool-runtime/programming-tool-families.md',
                    $d.'tool-runtime/evidence-gates.md',
                ],
            ],
            'F' => [
                'tier' => self::TIER_OBRAS,
                'label' => 'Obras / Workspace Compartilhado',
                'role' => 'long_session_not_dependent_on_chat_memory; handoff_to_scor_forge; persist_context_decisions_artifacts_evidence',
                'docs' => [
                    $d.'obras/shared-workspace-and-forge.md',
                    $d.'atlas-code-multi-project-workspace-os.md',
                ],
            ],
        ];
    }

    /**
     * Tier id -> the pack id that provides it.
     *
     * @return array<string, string>
     */
    public function tierToPack(): array
    {
        $map = [];
        foreach ($this->packs() as $packId => $pack) {
            $map[$pack['tier']] = $packId;
        }

        return $map;
    }

    /**
     * The documented rule: "Atlas Dev nao deve carregar tudo sempre; deve
     * selecionar por risco." Resolves which packs/tiers to load for a task.
     *
     * Risk ladder (R0..R5). Cumulative selection:
     *   - always (any non-trivial task): CORE (A) — minimum spine. With a resolved
     *     workspace, also CODE_INTELLIGENCE (E) — "Nucleo minimo + Code
     *     Intelligence compacto" for tarefas simples.
     *   - structural task OR risk >= R2: add SDD (B) — Spec/SDD detalhado.
     *   - frontend/UI/long task OR an interface surface: add INTERFACE (C).
     *   - risk >= R4: add FORGE (D) — preparar escalada para Forge.
     *   - obras requested (persistent production unit) OR risk >= R4 with obras
     *     declared: add OBRAS (F).
     *
     * Output preserves canonical pack order A..F and never loads a tier twice.
     *
     * @param  array<string,mixed>  $task
     *         risk_level         : string  R0..R5 (default R1)
     *         structural         : bool    touches multiple modules / structural change
     *         frontend           : bool    frontend / UI work
     *         long_session       : bool    long auditable session
     *         workspace_resolved : bool    a certified workspace is resolved
     *         obras              : bool    persistent Obra/workspace requested
     *         interface_surface  : bool    surface needs visual/interface verification
     * @return array{
     *   risk_level:string, selected_packs:list<string>, selected_tiers:list<string>,
     *   load_all:bool, reasons:list<string>
     * }
     */
    public function selectByRisk(array $task): array
    {
        $risk = $this->normalizeRisk($task['risk_level'] ?? 'R1');
        $structural = ($task['structural'] ?? false) === true;
        $frontend = ($task['frontend'] ?? false) === true;
        $longSession = ($task['long_session'] ?? false) === true;
        $workspaceResolved = ($task['workspace_resolved'] ?? false) === true;
        $obras = ($task['obras'] ?? false) === true;
        $interfaceSurface = ($task['interface_surface'] ?? false) === true;

        $tiers = [];
        $reasons = [];

        // Always load the core spine.
        $tiers[] = self::TIER_CORE;
        $reasons[] = 'core_is_minimum_spine';

        // Compact code intelligence whenever a workspace is resolved (simple tasks).
        if ($workspaceResolved) {
            $tiers[] = self::TIER_CODE_INTELLIGENCE;
            $reasons[] = 'workspace_resolved->code_intelligence_compact';
        }

        // Structural work or R2+ pulls in detailed Spec/SDD.
        if ($structural || $this->riskAtLeast($risk, 'R2')) {
            $tiers[] = self::TIER_SDD;
            $reasons[] = $structural
                ? 'structural_task->sdd_detailed'
                : 'risk_at_least_r2->sdd_detailed';
        }

        // Frontend / UI / long session / interface surface pulls in Atlas Code.
        if ($frontend || $longSession || $interfaceSurface) {
            $tiers[] = self::TIER_INTERFACE;
            $reasons[] = 'frontend_or_long_or_interface_surface->atlas_code_interface';
        }

        // High risk prepares Forge escalation.
        if ($this->riskAtLeast($risk, 'R4')) {
            $tiers[] = self::TIER_FORGE;
            $reasons[] = 'risk_at_least_r4->forge_escalation';
        }

        // Persistent production unit when Obras is requested.
        if ($obras) {
            $tiers[] = self::TIER_OBRAS;
            $reasons[] = 'obras_requested->persistent_workspace';
        }

        $orderedTiers = $this->orderTiers(array_values(array_unique($tiers)));
        $tierToPack = $this->tierToPack();
        $packs = [];
        foreach ($orderedTiers as $tier) {
            if (isset($tierToPack[$tier])) {
                $packs[] = $tierToPack[$tier];
            }
        }
        sort($packs);

        return [
            'risk_level' => $risk,
            'selected_packs' => array_values($packs),
            'selected_tiers' => $orderedTiers,
            // "nao-decisao: nao transformar essa lista em dependencia obrigatoria
            //  para toda tarefa simples" — load_all is never forced by this decider.
            'load_all' => count($orderedTiers) === count($this->packs()),
            'reasons' => $reasons,
        ];
    }

    /**
     * Resolve a cited doc path against the verified gap rule. A path equal to the
     * known broken citation is reported as a GAP and rewritten to the related
     * existing fallback path; any other path passes through unchanged.
     *
     * @return array{cited:string, exists:bool, is_gap:bool, resolved_path:string, reason:string}
     */
    public function resolveCitedPath(string $citedPath): array
    {
        $cited = trim($citedPath);

        if ($cited === self::GAP_CITED_PATH) {
            return [
                'cited' => $cited,
                'exists' => false,
                'is_gap' => true,
                'resolved_path' => self::GAP_FALLBACK_PATH,
                'reason' => 'verified_gap_old_name_use_related_existing_forge_variant',
            ];
        }

        return [
            'cited' => $cited,
            'exists' => true,
            'is_gap' => false,
            'resolved_path' => $cited,
            'reason' => 'path_passes_through',
        ];
    }

    /**
     * Flatten the reading pack into the ordered list of source docs for a given
     * selection of packs (A..F), de-duplicated and gap-resolved. Useful when a
     * caller turns a risk selection into the actual Open Brain context source set.
     *
     * @param  list<string>  $packIds
     * @return array{packs:list<string>, sources:list<string>, gaps_resolved:list<string>}
     */
    public function sourcesForPacks(array $packIds): array
    {
        $all = $this->packs();
        $sources = [];
        $gapsResolved = [];

        foreach ($packIds as $packId) {
            $pack = $all[$packId] ?? null;
            if ($pack === null) {
                continue;
            }
            foreach ($pack['docs'] as $doc) {
                $resolved = $this->resolveCitedPath($doc);
                if ($resolved['is_gap']) {
                    $gapsResolved[] = $resolved['resolved_path'];
                    $sources[] = $resolved['resolved_path'];

                    continue;
                }
                $sources[] = $doc;
            }
        }

        return [
            'packs' => array_values($packIds),
            'sources' => array_values(array_unique($sources)),
            'gaps_resolved' => array_values(array_unique($gapsResolved)),
        ];
    }

    /**
     * Stable manifest of the slice this decider governs (for the command/probe).
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $packs = $this->packs();
        $summary = [];
        foreach ($packs as $id => $pack) {
            $summary[$id] = [
                'tier' => $pack['tier'],
                'label' => $pack['label'],
                'doc_count' => count($pack['docs']),
            ];
        }

        return [
            'decision_kind' => self::DECISION_KIND,
            'manifest_id' => self::MANIFEST_ID,
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-02.md',
            'tiers' => array_keys($this->tierToPack()),
            'packs' => $summary,
            'verified_gap' => [
                'cited' => self::GAP_CITED_PATH,
                'fallback' => self::GAP_FALLBACK_PATH,
            ],
        ];
    }

    private function normalizeRisk(mixed $value): string
    {
        return AtlasAaeosValueNormalizer::riskCodeR0ToR5($value, 'R1');
    }

    private function riskAtLeast(string $level, string $threshold): bool
    {
        $rank = ['R0' => 0, 'R1' => 1, 'R2' => 2, 'R3' => 3, 'R4' => 4, 'R5' => 5];

        return ($rank[$level] ?? 0) >= ($rank[$threshold] ?? 0);
    }

    /**
     * @param  list<string>  $tiers
     * @return list<string>
     */
    private function orderTiers(array $tiers): array
    {
        $canonical = [
            self::TIER_CORE,
            self::TIER_CODE_INTELLIGENCE,
            self::TIER_SDD,
            self::TIER_INTERFACE,
            self::TIER_FORGE,
            self::TIER_OBRAS,
        ];

        $ordered = [];
        foreach ($canonical as $tier) {
            if (in_array($tier, $tiers, true)) {
                $ordered[] = $tier;
            }
        }

        return $ordered;
    }
}
