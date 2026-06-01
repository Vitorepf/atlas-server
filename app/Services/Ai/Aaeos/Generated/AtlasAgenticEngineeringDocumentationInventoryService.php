<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Agentic Engineering Documentation Inventory decider.
 *
 * Pure, deterministic runtime for the inventory doc that answers "how do I read
 * the scattered Agentic Engineering / Dev / Forge / Atlas Code / TEOS /
 * comparison-battery / provider / research families without getting lost" —
 * distinct from the Authority Map (which answers *who governs*). This service
 * turns the doc's two tables + Fluxo + "Regras para IA" into an enforceable
 * contract:
 *
 *   1. classifyClass()  — apply the "Classes" table to one documentation class
 *                          and report whether it may govern implementation, with
 *                          the documented governance scope.
 *   2. classifyFile()   — apply the "Familias Canonicas" table to one repo file
 *                          name: resolve its family, class, owner doc and whether
 *                          it may govern implementation. The most specific pattern
 *                          wins (a `part-`/`session-handoff-` suffix demotes the
 *                          base family to part/handoff, exactly as the doc states).
 *   3. readingPlan()    — emit the 6-step Fluxo as an ordered plan, gated by the
 *                          class verdict so a non-authority file never reaches
 *                          "implement".
 *   4. checkAiRules()   — enforce the 7 "Regras para IA" as invariants, flagging
 *                          any proposed read/use that breaks one (never start by
 *                          part/handoff, never a comparison battery as primary
 *                          architecture, never provider dossier as default,
 *                          never Atlas Code as runtime-mae, never TEOS-as-current
 *                          when north-star, always escalate conflict to the
 *                          Authority Map).
 *
 * It NEVER touches the filesystem, git, Obsidian or any doc. It consumes a file
 * name / class id + already-gathered facts and emits verdicts + reasons.
 *
 * Rules grounded in the canonical doc:
 *   Classes (13) and their "Pode governar implementacao?" verdict:
 *     mother=yes, authority-map=yes, index=partial, contract=yes,
 *     runbook=within-contract, surface=ux-only, visual-projection=no-alone,
 *     comparison-battery=not-for-architecture, north-star=not-as-current-runtime,
 *     research=no, handoff=no, part=no-alone, prompt=no.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md
 */
final class AtlasAgenticEngineeringDocumentationInventoryService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.aaeos.agentic_engineering_documentation_inventory.v1';

    /** The 13 documentation classes (closed set from the "Classes" table). */
    public const CLASS_MOTHER = 'mother';
    public const CLASS_AUTHORITY_MAP = 'authority-map';
    public const CLASS_INDEX = 'index';
    public const CLASS_CONTRACT = 'contract';
    public const CLASS_RUNBOOK = 'runbook';
    public const CLASS_SURFACE = 'surface';
    public const CLASS_VISUAL_PROJECTION = 'visual-projection';
    /**
     * The doc's measurement/comparison class. Its rule is "Mede, compara,
     * reporta ou desenha bateria" — a comparison battery that measures and
     * reports but never governs architecture.
     */
    public const CLASS_COMPARISON_BATTERY = 'comparison-battery';
    public const CLASS_NORTH_STAR = 'north-star';
    public const CLASS_RESEARCH = 'research';
    public const CLASS_HANDOFF = 'handoff';
    public const CLASS_PART = 'part';
    public const CLASS_PROMPT = 'prompt';

    /** Governance verdicts from the "Pode governar implementacao?" column. */
    public const GOVERN_YES = 'yes';
    public const GOVERN_PARTIAL = 'partial';
    public const GOVERN_WITHIN_CONTRACT = 'within_contract';
    public const GOVERN_UX_ONLY = 'ux_only';
    public const GOVERN_NOT_ALONE = 'not_alone';
    public const GOVERN_NOT_FOR_ARCHITECTURE = 'not_for_architecture';
    public const GOVERN_NOT_AS_CURRENT_RUNTIME = 'not_as_current_runtime';
    public const GOVERN_NO = 'no';

    /**
     * The "Classes" table verbatim: each class -> its governance verdict + the
     * one-line rule the doc assigns.
     *
     * @var array<string,array{govern:string,rule:string}>
     */
    private const CLASS_TABLE = [
        self::CLASS_MOTHER => [
            'govern' => self::GOVERN_YES,
            'rule' => 'defines_name_area_or_mother_system',
        ],
        self::CLASS_AUTHORITY_MAP => [
            'govern' => self::GOVERN_YES,
            'rule' => 'resolves_hierarchy_between_docs',
        ],
        self::CLASS_INDEX => [
            'govern' => self::GOVERN_PARTIAL,
            'rule' => 'navigates_to_owner_docs_does_not_duplicate_content',
        ],
        self::CLASS_CONTRACT => [
            'govern' => self::GOVERN_YES,
            'rule' => 'defines_invariants_schemas_gates_and_dod',
        ],
        self::CLASS_RUNBOOK => [
            'govern' => self::GOVERN_WITHIN_CONTRACT,
            'rule' => 'defines_operational_execution_within_the_contract',
        ],
        self::CLASS_SURFACE => [
            'govern' => self::GOVERN_UX_ONLY,
            'rule' => 'governs_ux_not_the_runtime_mother',
        ],
        self::CLASS_VISUAL_PROJECTION => [
            'govern' => self::GOVERN_NOT_ALONE,
            'rule' => 'compresses_docs_schemas_evidence_into_image_primary_source_stays_in_canonical_doc',
        ],
        self::CLASS_COMPARISON_BATTERY => [
            'govern' => self::GOVERN_NOT_FOR_ARCHITECTURE,
            'rule' => 'measures_compares_reports_or_designs_battery',
        ],
        self::CLASS_NORTH_STAR => [
            'govern' => self::GOVERN_NOT_AS_CURRENT_RUNTIME,
            'rule' => 'defines_future_target',
        ],
        self::CLASS_RESEARCH => [
            'govern' => self::GOVERN_NO,
            'rule' => 'external_advisory_source_until_canonical_promotion',
        ],
        self::CLASS_HANDOFF => [
            'govern' => self::GOVERN_NO,
            'rule' => 'session_snapshot_historical_context',
        ],
        self::CLASS_PART => [
            'govern' => self::GOVERN_NOT_ALONE,
            'rule' => 'fragment_of_mother_doc_read_the_mother_doc_first',
        ],
        self::CLASS_PROMPT => [
            'govern' => self::GOVERN_NO,
            'rule' => 'disposable_versioned_operational_instruction',
        ],
    ];

    /**
     * The "Familias Canonicas" routing table. Each entry: a needle matched
     * (case-insensitively) against the file name, its class, the owner doc to
     * open, plus a short note. Order is most-specific-first so that a `part-` /
     * `session-handoff-` marker demotes a base family before the base family's
     * own pattern can claim it — exactly as the doc's examples require.
     *
     * @var list<array{needle:string,class:string,owner:string,note:string}>
     */
    private const FAMILY_TABLE = [
        // --- Most specific markers first (demotions / fragments) ---
        [
            'needle' => 'session-handoff',
            'class' => self::CLASS_HANDOFF,
            'owner' => 'atlas-forge-continuum-os.md',
            'note' => 'historical_snapshot_never_primary_source',
        ],
        [
            'needle' => 'continuum-os-session-handoff',
            'class' => self::CLASS_HANDOFF,
            'owner' => 'atlas-forge-continuum-os.md',
            'note' => 'historical_snapshot_never_primary_source',
        ],
        [
            'needle' => 'one-shot-prompt',
            'class' => self::CLASS_PROMPT,
            'owner' => 'doc_owner_cited_in_prompt',
            'note' => 'instruction_for_tool_not_architecture',
        ],
        [
            'needle' => 'goal-prompt',
            'class' => self::CLASS_PROMPT,
            'owner' => 'doc_owner_cited_in_prompt',
            'note' => 'instruction_for_tool_not_architecture',
        ],
        // --- Specific Forge sub-families before the generic forge family ---
        [
            'needle' => 'atlas-forge-continuum-os',
            'class' => self::CLASS_MOTHER,
            'owner' => 'atlas-forge-continuum-os.md',
            'note' => 'heavy_continuum_obra_providers_fallback_review_evidence',
        ],
        [
            'needle' => 'atlas-forge-operating-system',
            'class' => self::CLASS_CONTRACT,
            'owner' => 'atlas-forge-operating-system.md',
            'note' => 'forge_factory_inside_the_continuum',
        ],
        [
            'needle' => 'atlas-forge-provider',
            'class' => self::CLASS_CONTRACT,
            'owner' => 'atlas-forge-continuum-os.md',
            'note' => 'provider_topology_capacity_invocation_not_a_separate_os',
        ],
        [
            'needle' => 'atlas-programming-forge-flow',
            'class' => self::CLASS_MOTHER,
            'owner' => 'atlas-programming-forge-flow.md',
            'note' => 'entry_of_the_heavy_flow',
        ],
        // --- Atlas Code surface-local OS before generic atlas-code ---
        [
            'needle' => 'workspace-os',
            'class' => self::CLASS_SURFACE,
            'owner' => 'atlas-desktop-code-surface.md',
            'note' => 'os_is_local_surface_bound_scope',
        ],
        [
            'needle' => 'atlas-code',
            'class' => self::CLASS_SURFACE,
            'owner' => 'atlas-desktop-code-surface.md',
            'note' => 'atlas_code_is_surface_cockpit_not_runtime_mother',
        ],
        // --- North-star temporal layer ---
        [
            'needle' => 'temporal-engineering-operating-system',
            'class' => self::CLASS_NORTH_STAR,
            'owner' => 'atlas-temporal-engineering-operating-system.md',
            'note' => 'temporal_layer_not_programming_runtime_mother',
        ],
        [
            'needle' => 'atlas-teos',
            'class' => self::CLASS_NORTH_STAR,
            'owner' => 'atlas-temporal-engineering-operating-system.md',
            'note' => 'temporal_layer_not_current_runtime_if_planned',
        ],
        // --- Advantage-architecture strategy (measurement battery family) ---
        // The doc's `atlas-programming-superior*` advantage-architecture family
        // and the provider-comparison battery family both classify as the
        // comparison-battery class: they measure and report but never govern
        // architecture without a battery. They are matched here by their shared
        // `atlas-programming-` advantage prefix token.
        [
            'needle' => 'atlas-programming-advantage',
            'class' => self::CLASS_COMPARISON_BATTERY,
            'owner' => 'atlas-programming-advantage-architecture.md',
            'note' => 'advantage_architecture_no_external_claim_without_battery',
        ],
        // --- Provider dossiers / research ---
        [
            'needle' => 'atlas-cursor',
            'class' => self::CLASS_RESEARCH,
            'owner' => 'provider_evolution_advisory',
            'note' => 'advisory_until_smoke_evidence_license_security_and_promotion',
        ],
        [
            'needle' => 'atlas-antigravity',
            'class' => self::CLASS_RESEARCH,
            'owner' => 'provider_evolution_advisory',
            'note' => 'advisory_until_smoke_evidence_license_security_and_promotion',
        ],
        // --- Index / authority / mother docs of the area ---
        [
            'needle' => 'agentic-software-engineering-authority-map',
            'class' => self::CLASS_AUTHORITY_MAP,
            'owner' => 'atlas-agentic-software-engineering-authority-map.md',
            'note' => 'mandatory_hierarchy',
        ],
        [
            'needle' => 'agentic-engineering-documentation-inventory',
            'class' => self::CLASS_INDEX,
            'owner' => 'atlas-agentic-engineering-documentation-inventory.md',
            'note' => 'family_classification_this_doc',
        ],
        [
            'needle' => 'atlas-agentic-engineering-os',
            'class' => self::CLASS_MOTHER,
            'owner' => 'atlas-agentic-engineering-os.md',
            'note' => 'name_and_mother_layer_of_the_area',
        ],
        [
            'needle' => 'atlas-programming-governance-system',
            'class' => self::CLASS_MOTHER,
            'owner' => 'atlas-programming-governance-system.md',
            'note' => 'law_of_programming_by_ai',
        ],
        [
            'needle' => 'atlas-dev-index',
            'class' => self::CLASS_INDEX,
            'owner' => 'atlas-dev-index.md',
            'note' => 'mandatory_entry_of_atlas_dev',
        ],
    ];

    /**
     * Classify one documentation class: does it govern implementation, and under
     * what scope? Verbatim from the "Classes" table.
     *
     * @return array<string,mixed>
     */
    public function classifyClass(string $class): array
    {
        $key = $this->normalizeClass($class);
        $known = array_key_exists($key, self::CLASS_TABLE);

        if (! $known) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'decision' => 'class_governance',
                'class' => $key,
                'known' => false,
                'can_govern_implementation' => false,
                'governance_scope' => self::GOVERN_NO,
                'rule' => 'unknown_class_requires_inventory_update_before_use',
            ];
        }

        $row = self::CLASS_TABLE[$key];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'class_governance',
            'class' => $key,
            'known' => true,
            'can_govern_implementation' => $this->classGoverns($row['govern']),
            'governance_scope' => $row['govern'],
            'rule' => $row['rule'],
        ];
    }

    /**
     * Classify one repo file name via the "Familias Canonicas" table: resolve its
     * family, class, owner doc to open and whether it may govern implementation.
     * Most-specific pattern wins, so an unrecognized file is reported as a gap
     * ("Se um doc novo nao encaixar em nenhuma familia, registre como gap").
     *
     * @return array<string,mixed>
     */
    public function classifyFile(string $fileName): array
    {
        $haystack = strtolower(trim($fileName));
        $match = $this->matchFamily($haystack);

        if ($match === null) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'decision' => 'file_classification',
                'file' => $haystack,
                'matched' => false,
                'class' => null,
                'owner_doc' => null,
                'can_govern_implementation' => false,
                'governance_scope' => self::GOVERN_NO,
                'reason' => 'unclassified_file_register_as_gap_before_implementation',
            ];
        }

        $class = $match['class'];
        $scope = self::CLASS_TABLE[$class]['govern'];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'file_classification',
            'file' => $haystack,
            'matched' => true,
            'class' => $class,
            'owner_doc' => $match['owner'],
            'can_govern_implementation' => $this->classGoverns($scope),
            'governance_scope' => $scope,
            'note' => $match['note'],
        ];
    }

    /**
     * Emit the 6-step "Fluxo" as an ordered reading plan for a given file, gated
     * by the resolved class. Step 6 ("implementar somente se doc mother / contract
     * / runbook autorizar") only flips to "may implement" when the file's class
     * actually authorizes governance.
     *
     * @return array<string,mixed>
     */
    public function readingPlan(string $fileName): array
    {
        $classification = $this->classifyFile($fileName);
        $mayImplement = $classification['matched'] === true
            && $this->classAuthorizesImplementation($classification['class']);

        $steps = [
            'identify_family_by_file_name',
            'consult_the_inventory_table',
            'open_the_indicated_owner_doc',
            'check_status_and_implementation_state',
            'use_part_handoff_research_only_as_context',
            $mayImplement
                ? 'implement_only_because_mother_contract_or_runbook_authorizes'
                : 'do_not_implement_class_is_context_only_escalate_to_authority_map_if_needed',
        ];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'reading_plan',
            'file' => $classification['file'],
            'class' => $classification['class'],
            'owner_doc' => $classification['owner_doc'],
            'may_implement' => $mayImplement,
            'steps' => $steps,
        ];
    }

    /**
     * Enforce the 7 "Regras para IA" as invariants against a proposed read/use of
     * a file and return every violation. The proposal is allowed only when no
     * rule is broken.
     *
     * @param array<string,mixed> $proposal
     *   starts_from_part           : bool  begins reading from a `part-*` file.
     *   starts_from_session_handoff : bool  begins reading from a `session-handoff-*` file.
     *   uses_comparison_battery_as_primary_architecture : bool  uses a comparison battery as primary architecture.
     *   uses_provider_dossier_as_default : bool  uses a provider dossier as an automatic default.
     *   treats_atlas_code_as_runtime_mother : bool  treats Atlas Code as the runtime-mae.
     *   treats_teos_as_current_runtime : bool  treats TEOS as current runtime...
     *   teos_status_is_north_star  : bool  ...while its status/implementation_state says north-star/planned.
     *   has_conflict               : bool  there is a conflict between docs.
     *   escalates_to_authority_map : bool  the conflict is escalated to the Authority Map.
     *
     * @return array<string,mixed>
     */
    public function checkAiRules(array $proposal): array
    {
        $violations = [];

        // "Nunca comece por part-*."
        if ((bool) ($proposal['starts_from_part'] ?? false)) {
            $violations[] = 'started_reading_from_part_fragment';
        }

        // "Nunca comece por session-handoff-*."
        if ((bool) ($proposal['starts_from_session_handoff'] ?? false)) {
            $violations[] = 'started_reading_from_session_handoff';
        }

        // "Nunca use [comparison battery] como arquitetura primaria."
        if ((bool) ($proposal['uses_comparison_battery_as_primary_architecture'] ?? false)) {
            $violations[] = 'used_comparison_battery_as_primary_architecture';
        }

        // "Nunca use provider dossier como default automatico."
        if ((bool) ($proposal['uses_provider_dossier_as_default'] ?? false)) {
            $violations[] = 'used_provider_dossier_as_automatic_default';
        }

        // "Nunca trate Atlas Code como runtime-mae."
        if ((bool) ($proposal['treats_atlas_code_as_runtime_mother'] ?? false)) {
            $violations[] = 'treated_atlas_code_as_runtime_mother';
        }

        // "Nunca trate TEOS como runtime atual se status/implementation_state
        //  disser north-star/planned."
        if ((bool) ($proposal['treats_teos_as_current_runtime'] ?? false)
            && (bool) ($proposal['teos_status_is_north_star'] ?? false)) {
            $violations[] = 'treated_north_star_teos_as_current_runtime';
        }

        // "Sempre suba conflito para o Authority Map."
        if ((bool) ($proposal['has_conflict'] ?? false)
            && ! (bool) ($proposal['escalates_to_authority_map'] ?? false)) {
            $violations[] = 'conflict_not_escalated_to_authority_map';
        }

        $allowed = $violations === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'ai_rules',
            'allowed' => $allowed,
            'violations' => array_values($violations),
            'reason' => $allowed ? 'no_ai_rule_violated' : $violations[0],
        ];
    }

    /**
     * Convenience predicate: does this proposed read/use respect every "Regra
     * para IA"?
     *
     * @param array<string,mixed> $proposal
     */
    public function isProposalAllowed(array $proposal): bool
    {
        return $this->checkAiRules($proposal)['allowed'] === true;
    }

    /**
     * A class governs implementation when its verdict is yes, partial,
     * within_contract or ux_only. Everything else (not_alone, comparison-battery,
     * north-star, research, handoff, prompt, no) does not govern on its own.
     */
    private function classGoverns(string $verdict): bool
    {
        return in_array($verdict, [
            self::GOVERN_YES,
            self::GOVERN_PARTIAL,
            self::GOVERN_WITHIN_CONTRACT,
            self::GOVERN_UX_ONLY,
        ], true);
    }

    /**
     * Step 6 of the Fluxo: implementation is authorized only by a mother,
     * contract or runbook class ("Implementar somente se doc mother / contract /
     * runbook autorizar"). authority-map resolves hierarchy and index/surface
     * navigate or scope UX but do not by themselves authorize implementing a
     * feature.
     */
    private function classAuthorizesImplementation(?string $class): bool
    {
        return in_array($class, [
            self::CLASS_MOTHER,
            self::CLASS_CONTRACT,
            self::CLASS_RUNBOOK,
        ], true);
    }

    /**
     * First-match (most-specific-first) lookup against the "Familias Canonicas"
     * table.
     *
     * @return array{needle:string,class:string,owner:string,note:string}|null
     */
    private function matchFamily(string $haystack): ?array
    {
        foreach (self::FAMILY_TABLE as $row) {
            if (str_contains($haystack, $row['needle'])) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Normalize a free-form class label to the table's lower-case hyphenated key.
     */
    private function normalizeClass(string $class): string
    {
        $key = strtolower(trim($class));
        $key = (string) preg_replace('/[\s_]+/', '-', $key);

        return trim($key, '-');
    }
}
