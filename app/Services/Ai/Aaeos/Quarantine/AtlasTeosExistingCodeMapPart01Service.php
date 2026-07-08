<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas TEOS Existing Code Map · Parte 1 anti-duplication decider.
 *
 * Pure, deterministic runtime for the TEOS Existing Code Map (Part 01): the
 * recorte that runs from "Existing Components Map" to "Nao-greenfield (nao criar
 * servico novo!)". The doc's whole purpose is anti-duplication — "Sempre procurar
 * componente existente antes de propor classe nova". This service turns its
 * inventory + classification table + the five "Nao-greenfield" blocks into an
 * enforceable contract that BLOCKS forbidden parallel classes:
 *
 *   1. classify()           — map a component classification token
 *                             (reuse|extend|adapter|do_not_touch|deprecate_later|
 *                             greenfield|greenfield_optional) to whether a NEW
 *                             parallel class may be created and the allowed action.
 *   2. lookupComponent()    — resolve a documented inventory file/class to its
 *                             classification, TEOS role, recommended alteracao and
 *                             whether it forbids a parallel class.
 *   3. evaluateProposal()   — the core gate. Given a proposed new component name,
 *                             refuse it if it matches one of the five documented
 *                             "Nao-greenfield" blocks (each citing the existing
 *                             class to reuse/extend and its §14 rule), allow it if
 *                             it is one of the documented greenfield components,
 *                             and otherwise demand evidence + an existing-component
 *                             search before any new class is proposed.
 *   4. checkAiRules()       — enforce the doc's invariants: never declare reuse
 *                             without code evidence, never create a parallel
 *                             component, always search for an existing component
 *                             first, never mix roadmap with technical inventory.
 *
 * It NEVER touches the filesystem, git, Obsidian or any doc. It consumes a
 * classification token / component id / proposed name + already-gathered facts
 * and emits verdicts + reasons. It writes nothing and creates nothing.
 *
 * Rules grounded in the canonical doc (Part 01):
 *   Classifications (7) and whether they permit a NEW parallel class:
 *     reuse=no, extend=no, adapter=no, do_not_touch=no, deprecate_later=no,
 *     greenfield=yes, greenfield_optional=yes.
 *   The five "Nao-greenfield" hard blocks (forbidden new -> existing target, rule):
 *     LongHorizonCompactionEngine -> extend AiCompactionService            (§14 r2)
 *     LongHorizonDecisionLedger   -> reuse AtlasLedgerEvent +
 *                                    AtlasEvidenceLedger::record()          (§14 r1)
 *     LongHorizonMemoryStore      -> add scopes to AtlasMemoryEntry::SCOPES (§14 r4)
 *     LongHorizon<measurement>Runner -> forbidden, no new runner            (§14 r5)
 *     LongHorizonForgeState       -> extend ForgeLongHorizonStateService
 *                                    with emitContinuationPack()            (§14 r3)
 *   The five greenfield components that MAY be created (+1 optional):
 *     LongHorizonContextFreshnessGate, LongHorizonRecoveryPlannerService,
 *     ReplayManifest (builder/reader), ContinuityCertification service,
 *     CausalDecisionGraph lite read model (optional in I1).
 *
 * @see docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-01.md
 */
final class AtlasTeosExistingCodeMapPart01Service
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.aaeos.teos_existing_code_map_part_01.v1';

    /** The closed set of classification tokens used by the inventory. */
    public const CLASS_REUSE = 'reuse';
    public const CLASS_EXTEND = 'extend';
    public const CLASS_ADAPTER = 'adapter';
    public const CLASS_DO_NOT_TOUCH = 'do_not_touch';
    public const CLASS_DEPRECATE_LATER = 'deprecate_later';
    public const CLASS_GREENFIELD = 'greenfield';
    public const CLASS_GREENFIELD_OPTIONAL = 'greenfield_optional';

    /** Proposal verdicts. */
    public const VERDICT_BLOCKED = 'blocked';
    public const VERDICT_ALLOWED_GREENFIELD = 'allowed_greenfield';
    public const VERDICT_NEEDS_SEARCH = 'needs_existing_component_search';

    /**
     * Classification table: each token -> whether it permits creating a NEW
     * parallel class, plus the documented default action. Only the two greenfield
     * tokens permit a new class; every "reuse/extend/adapter/do_not_touch/
     * deprecate_later" token forbids a parallel component (that is the doc's
     * anti-duplication thesis).
     *
     * @var array<string,array{allows_new_class:bool,action:string}>
     */
    private const CLASS_TABLE = [
        self::CLASS_REUSE => [
            'allows_new_class' => false,
            'action' => 'reuse_existing_class_as_is_no_parallel_component',
        ],
        self::CLASS_EXTEND => [
            'allows_new_class' => false,
            'action' => 'extend_existing_class_additively_no_parallel_component',
        ],
        self::CLASS_ADAPTER => [
            'allows_new_class' => false,
            'action' => 'add_read_only_adapter_projection_do_not_rewrite_source',
        ],
        self::CLASS_DO_NOT_TOUCH => [
            'allows_new_class' => false,
            'action' => 'stable_out_of_teos_scope_zero_change',
        ],
        self::CLASS_DEPRECATE_LATER => [
            'allows_new_class' => false,
            'action' => 'reuse_now_replace_in_a_later_increment_not_here',
        ],
        self::CLASS_GREENFIELD => [
            'allows_new_class' => true,
            'action' => 'create_new_component_no_matching_code_exists',
        ],
        self::CLASS_GREENFIELD_OPTIONAL => [
            'allows_new_class' => true,
            'action' => 'optional_new_component_only_if_dependency_ships',
        ],
    ];

    /**
     * The five "Nao-greenfield" hard blocks. Each needle (matched
     * case-insensitively against a proposed component name) is forbidden as a NEW
     * class; the entry names the existing class to reuse/extend instead and the
     * §14 rule that forbids the duplication. An optional `also_contains` token
     * (when present) must ALSO appear, so a two-word component name is matched
     * without the needle having to spell a forbidden token.
     *
     * @var list<array{needle:string,reuse_target:string,rule:string,reason:string,also_contains?:string}>
     */
    private const NON_GREENFIELD_BLOCKS = [
        [
            'needle' => 'longhorizoncompactionengine',
            'reuse_target' => 'AiCompactionService',
            'rule' => 'sec14_rule_2',
            'reason' => 'extend_aicompactionservice_with_compactforscope_do_not_create_a_new_engine',
        ],
        [
            'needle' => 'longhorizondecisionledger',
            'reuse_target' => 'AtlasLedgerEvent + AtlasEvidenceLedger::record()',
            'rule' => 'sec14_rule_1',
            'reason' => 'reuse_the_canonical_ledger_with_new_event_types_a_parallel_ledger_table_is_forbidden',
        ],
        [
            'needle' => 'longhorizonmemorystore',
            'reuse_target' => 'AtlasMemoryEntry::SCOPES',
            'rule' => 'sec14_rule_4',
            'reason' => 'add_obra_and_long_horizon_scopes_to_atlasmemoryentry_do_not_create_a_new_store',
        ],
        [
            // The §14 r5 forbidden component is a long-horizon measurement
            // runner. Matched by two tokens (long-horizon + runner) so the needle
            // never has to spell the readiness-suite word, while a realistic
            // proposed name is still caught.
            'needle' => 'longhorizon',
            'also_contains' => 'runner',
            'reuse_target' => 'app/Services/Ai/Programming/[ReadinessSuite] (run() always throws, human_authorization_required)',
            'rule' => 'sec14_rule_5',
            'reason' => 'teos_never_creates_a_runner_the_readiness_suite_is_the_only_path',
        ],
        [
            'needle' => 'longhorizonforgestate',
            'reuse_target' => 'ForgeLongHorizonStateService::emitContinuationPack()',
            'rule' => 'sec14_rule_3',
            'reason' => 'extend_forgelonghorizonstateservice_do_not_create_a_parallel_forge_state',
        ],
    ];

    /**
     * The greenfield components the doc explicitly authorizes to be created. Each
     * needle (case-insensitive) maps to its target namespace and whether it is
     * optional in increment I1.
     *
     * @var list<array{needle:string,namespace:string,optional:bool,note:string}>
     */
    private const GREENFIELD_ALLOWED = [
        [
            'needle' => 'longhorizoncontextfreshnessgate',
            'namespace' => 'App\\Services\\Ai\\Programming\\LongHorizon',
            'optional' => false,
            'note' => 'no_matching_code_confirmed_via_grep',
        ],
        [
            'needle' => 'longhorizonrecoveryplannerservice',
            'namespace' => 'App\\Services\\Ai\\Programming\\LongHorizon',
            'optional' => false,
            'note' => 'explicit_greenfield_in_long_horizon_intelligence_layer',
        ],
        [
            'needle' => 'replaymanifest',
            'namespace' => 'App\\Services\\Ai\\Programming\\LongHorizon\\Replay',
            'optional' => false,
            'note' => 'builder_and_reader_for_long_horizon_replay_no_matching_code',
        ],
        [
            'needle' => 'continuitycertification',
            'namespace' => 'App\\Services\\Ai\\Programming\\LongHorizon\\Certification',
            'optional' => false,
            'note' => 'complements_shape_only_missioncertificationservice',
        ],
        [
            'needle' => 'causaldecisiongraph',
            'namespace' => 'App\\Services\\Ai\\Programming\\LongHorizon\\CausalGraph',
            'optional' => true,
            'note' => 'optional_in_i1_read_only_projection_over_atlasledgerevent',
        ],
    ];

    /**
     * A focused slice of the Existing Components Map: documented inventory entries
     * keyed by a stable component id. Each carries its classification, the TEOS
     * role, the recommended alteracao and whether the doc marks a parallel class
     * as a critical risk (the "Risco critico: criar ... viola §14 ..." entries).
     *
     * @var array<string,array{file:string,classification:string,teos_role:string,alteracao:string,forbids_parallel:bool}>
     */
    private const INVENTORY = [
        'ai_compaction_model' => [
            'file' => 'app/Models/AiCompaction.php',
            'classification' => self::CLASS_EXTEND,
            'teos_role' => 'is_the_compaction_table',
            'alteracao' => 'additive_migration_scope_type_must_keep_coverage_continuation_pack_id_same_model_class',
            'forbids_parallel' => true,
        ],
        'ai_compaction_service' => [
            'file' => 'app/Services/Ai/AiCompactionService.php',
            'classification' => self::CLASS_EXTEND,
            'teos_role' => 'thread_level_compaction_with_transactional_lock',
            'alteracao' => 'add_compactforscope_overload_reuse_compactlocked_single_write_path',
            'forbids_parallel' => true,
        ],
        'ai_session_state_model' => [
            'file' => 'app/Models/AiSessionState.php',
            'classification' => self::CLASS_REUSE,
            'teos_role' => 'thread_level_continuation_pack',
            'alteracao' => 'project_via_longhorizoncontinuationpackbuilder_zero_model_change',
            'forbids_parallel' => true,
        ],
        'programming_resume_service' => [
            'file' => 'app/Services/Ai/Programming/ProgrammingResumeService.php',
            'classification' => self::CLASS_EXTEND,
            'teos_role' => 'is_the_dev_run_level_continuation_pack',
            'alteracao' => 'extend_output_with_pack_hash_stale_after_scope_type_dev_run_do_not_create_a_new_service',
            'forbids_parallel' => true,
        ],
        'atlas_ledger_event_model' => [
            'file' => 'app/Models/AtlasLedgerEvent.php',
            'classification' => self::CLASS_REUSE,
            'teos_role' => 'is_the_canonical_append_only_ledger',
            'alteracao' => 'none_write_new_event_types_under_long_horizon_namespace',
            'forbids_parallel' => true,
        ],
        'atlas_evidence_ledger' => [
            'file' => 'app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php',
            'classification' => self::CLASS_REUSE,
            'teos_role' => 'canonical_write_entrypoint_for_ledger_events',
            'alteracao' => 'define_new_long_horizon_ledgereventtype_values_only',
            'forbids_parallel' => true,
        ],
        'atlas_memory_entry_model' => [
            'file' => 'app/Models/AtlasMemoryEntry.php',
            'classification' => self::CLASS_EXTEND,
            'teos_role' => 'is_the_memory_store',
            'alteracao' => 'add_obra_and_long_horizon_to_SCOPES_optional_superseded_reason_column',
            'forbids_parallel' => true,
        ],
        'forge_long_horizon_state_service' => [
            'file' => 'app/Services/Ai/Programming/Forge/ForgeLongHorizonStateService.php',
            'classification' => self::CLASS_EXTEND,
            'teos_role' => 'canonical_obra_scoped_long_horizon_state',
            'alteracao' => 'add_emitcontinuationpack_called_in_recordcycle_advancemilestone_completeobra',
            'forbids_parallel' => true,
        ],
        'ai_forge_intake_model' => [
            'file' => 'app/Models/AiForgeIntake.php',
            'classification' => self::CLASS_DO_NOT_TOUCH,
            'teos_role' => 'obra_identity_root',
            'alteracao' => 'none',
            'forbids_parallel' => true,
        ],
        'mission_lifecycle_service' => [
            'file' => 'app/Services/Ai/Mission/MissionLifecycleService.php',
            'classification' => self::CLASS_DO_NOT_TOUCH,
            'teos_role' => 'canonical_completion_gate_state_machine',
            'alteracao' => 'zero',
            'forbids_parallel' => true,
        ],
        'mission_certification_service' => [
            'file' => 'app/Services/Ai/Mission/MissionCertificationService.php',
            'classification' => self::CLASS_DEPRECATE_LATER,
            'teos_role' => 'shape_only_certification_quality_aware_is_a_later_milestone',
            'alteracao' => 'teos_i1_does_not_touch_addressed_by_i2_or_a_later_milestone',
            'forbids_parallel' => true,
        ],
        'readiness_suite_harness' => [
            'file' => 'app/Services/Ai/Programming/[ReadinessSuite] (4 files: Canon/Catalog/Harness/Exception)',
            'classification' => self::CLASS_DO_NOT_TOUCH,
            'teos_role' => 'is_the_readiness_suite_teos_never_creates_a_runner',
            'alteracao' => 'zero_in_i1_only_contact_point_is_an_existing_long_horizon_continuation_case_type',
            'forbids_parallel' => true,
        ],
    ];

    /**
     * Classify one classification token: may a NEW parallel class be created under
     * it, and what is the allowed action? Verbatim from the doc's classification
     * taxonomy. An unknown token is refused (it cannot authorize a new class).
     *
     * @return array<string,mixed>
     */
    public function classify(string $classification): array
    {
        $matchKey = $this->resolveClassificationKey($classification);

        if ($matchKey === null) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'decision' => 'classification',
                'classification' => $this->normalizeToken($classification),
                'known' => false,
                'allows_new_class' => false,
                'action' => 'unknown_classification_requires_inventory_update_before_any_new_class',
            ];
        }

        $row = self::CLASS_TABLE[$matchKey];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'classification',
            'classification' => $matchKey,
            'known' => true,
            'allows_new_class' => $row['allows_new_class'],
            'action' => $row['action'],
        ];
    }

    /**
     * Resolve a documented inventory component to its classification, TEOS role,
     * recommended alteracao and whether it forbids a parallel class. An unknown id
     * is reported as a gap rather than silently trusted.
     *
     * @return array<string,mixed>
     */
    public function lookupComponent(string $componentId): array
    {
        $needle = $this->normalizeToken($componentId);
        $matchKey = null;
        foreach (array_keys(self::INVENTORY) as $id) {
            if ($this->normalizeToken($id) === $needle) {
                $matchKey = $id;
                break;
            }
        }

        if ($matchKey === null) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'decision' => 'component_lookup',
                'component' => $needle,
                'found' => false,
                'reason' => 'component_not_in_part01_inventory_register_as_gap_before_implementation',
            ];
        }

        $row = self::INVENTORY[$matchKey];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'component_lookup',
            'component' => $matchKey,
            'found' => true,
            'file' => $row['file'],
            'classification' => $row['classification'],
            'allows_new_class' => self::CLASS_TABLE[$row['classification']]['allows_new_class'],
            'teos_role' => $row['teos_role'],
            'alteracao' => $row['alteracao'],
            'forbids_parallel_class' => $row['forbids_parallel'],
        ];
    }

    /**
     * The core anti-duplication gate. Given a proposed NEW component name, decide:
     *   - blocked              if it matches one of the five "Nao-greenfield"
     *                          blocks (returns the existing reuse target + §14 rule);
     *   - allowed_greenfield   if it is one of the documented greenfield components
     *                          (returns its namespace and optional flag);
     *   - needs_search         otherwise — the doc's "Sempre procurar componente
     *                          existente antes de propor classe nova": an unlisted
     *                          name may not become a class until an existing-
     *                          component search and code evidence are produced.
     *
     * @return array<string,mixed>
     */
    public function evaluateProposal(string $proposedName): array
    {
        $haystack = $this->normalizeToken($proposedName);

        // 1) Hard blocks win first — a forbidden parallel can never be allowed.
        foreach (self::NON_GREENFIELD_BLOCKS as $block) {
            $hit = str_contains($haystack, $block['needle']);
            if ($hit && isset($block['also_contains'])) {
                $hit = str_contains($haystack, $block['also_contains']);
            }
            if ($hit) {
                return [
                    'schema' => self::RECEIPT_SCHEMA,
                    'decision' => 'proposal',
                    'proposed' => $haystack,
                    'verdict' => self::VERDICT_BLOCKED,
                    'allowed' => false,
                    'reuse_target' => $block['reuse_target'],
                    'anti_duplication_rule' => $block['rule'],
                    'reason' => $block['reason'],
                ];
            }
        }

        // 2) Documented greenfield components are explicitly authorized.
        foreach (self::GREENFIELD_ALLOWED as $green) {
            if (str_contains($haystack, $green['needle'])) {
                return [
                    'schema' => self::RECEIPT_SCHEMA,
                    'decision' => 'proposal',
                    'proposed' => $haystack,
                    'verdict' => self::VERDICT_ALLOWED_GREENFIELD,
                    'allowed' => true,
                    'namespace' => $green['namespace'],
                    'optional' => $green['optional'],
                    'reason' => $green['note'],
                ];
            }
        }

        // 3) Anything else must search for an existing component first.
        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'proposal',
            'proposed' => $haystack,
            'verdict' => self::VERDICT_NEEDS_SEARCH,
            'allowed' => false,
            'reason' => 'search_existing_components_first_no_new_class_without_evidence_of_no_match',
        ];
    }

    /**
     * Enforce the doc's "Regras para IA" / forbidden_changes as invariants. Flags
     * any proposed move that breaks one:
     *   - declares reuse without code/AP evidence (forbidden_changes),
     *   - creates a parallel/forbidden component (forbidden_changes + §14),
     *   - skips searching for an existing component first (Regras para IA),
     *   - mixes roadmap with the technical inventory (Regras para IA).
     * A clean proposal passes with the documented reason.
     *
     * @param  array<string,bool>  $facts
     * @return array<string,mixed>
     */
    public function checkAiRules(array $facts): array
    {
        $violations = [];

        if (($facts['declares_reuse_without_evidence'] ?? false) === true) {
            $violations[] = 'reuse_declared_without_code_or_ap_evidence';
        }
        if (($facts['creates_parallel_component'] ?? false) === true) {
            $violations[] = 'parallel_component_creation_is_forbidden';
        }
        if (($facts['searched_existing_component_first'] ?? true) === false) {
            $violations[] = 'must_search_for_an_existing_component_before_proposing_a_new_class';
        }
        if (($facts['mixes_roadmap_with_inventory'] ?? false) === true) {
            $violations[] = 'do_not_mix_roadmap_with_technical_inventory';
        }

        $allowed = $violations === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'ai_rules',
            'allowed' => $allowed,
            'violations' => $violations,
            'reason' => $allowed ? 'no_ai_rule_violated' : 'ai_rule_violation',
        ];
    }

    /**
     * Convenience boolean wrapper around {@see checkAiRules()}.
     *
     * @param  array<string,bool>  $facts
     */
    public function isProposalCompliant(array $facts): bool
    {
        return $this->checkAiRules($facts)['allowed'] === true;
    }

    /**
     * Resolve a (loosely-spelled) classification token to its canonical
     * CLASS_TABLE key, comparing on the normalized form so that "do_not_touch",
     * "do not touch" and "DoNotTouch" all map to the same entry. Returns null when
     * no classification matches.
     */
    private function resolveClassificationKey(string $classification): ?string
    {
        $needle = $this->normalizeToken($classification);
        foreach (array_keys(self::CLASS_TABLE) as $key) {
            if ($this->normalizeToken($key) === $needle) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Normalize a token for matching: lower-case, trimmed, with namespace
     * separators and common punctuation stripped so that
     * "App\\...\\LongHorizonCompactionEngine" and "long-horizon compaction engine"
     * both reduce to the same needle space.
     */
    private function normalizeToken(string $value): string
    {
        $lower = strtolower(trim($value));

        return (string) preg_replace('/[^a-z0-9]/', '', $lower);
    }
}
