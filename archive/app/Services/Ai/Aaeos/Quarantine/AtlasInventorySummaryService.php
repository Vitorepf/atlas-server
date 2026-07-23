<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Legacy Cleanup Inventory Summary decider.
 *
 * Pure, deterministic runtime for the *inventory* model (distinct from the
 * cleanup *report* index decider {@see AtlasLegacyDocumentationCleanupReportService}
 * and the cleanup *plan* action gate {@see AtlasLegacyDocumentationCleanupPlanService}).
 * This service turns the inventory summary's four tables into a contract:
 *
 *   1. classify()        — apply the doc's "Decision Criteria" table in its
 *                          documented priority order to map one legacy item to
 *                          exactly one Inventory Class, and describe the class's
 *                          default action.
 *   2. handleFamily()    — route one of the documented "Current Families" to its
 *                          declared handling.
 *   3. checkRiskRules()  — enforce the four "Risk Rules" as invariants, flagging
 *                          any proposed interpretation/action that breaks one.
 *
 * It NEVER touches the filesystem, git, Obsidian, or any doc. It consumes
 * already-gathered facts and emits verdicts + reasons.
 *
 * Rules grounded in the canonical doc:
 *   Classes (7): keep_canonical | promote_to_kb | merge_into_existing |
 *     archive_with_redirect | archived_quarantine | archived_projection |
 *     human_vault_only, each with a documented default action.
 *   Decision Criteria — applied top-to-bottom (first matching question wins):
 *     1. Listed by README / START_HERE / canonical index?     -> keep_canonical
 *     2. Carries a decision with no canonical equivalent?      -> promote_to_kb
 *     3. Duplicates an owner doc but adds useful detail?       -> merge_into_existing
 *     4. Is a prompt / plan / old report / completed spec?     -> archive_with_redirect
 *     5. Personal / sensitive / research-heavy?                -> human_vault_only
 *     6. Obsolete and unreferenced?                            -> archived_quarantine
 *        ("never immediate delete")
 *     plus the seventh class for provider/agent projections    -> archived_projection
 *   Risk Rules (4):
 *     - "human_vault_only does not mean low value."
 *     - "archived_quarantine does not mean delete approved."
 *     - "promote_to_kb means synthesize the decision; do not paste the full source."
 *     - "Any class change must preserve enough traceability for future audits."
 *
 * @see docs/engineering-knowledge-base/legacy-cleanup/inventory-summary.md
 */
final class AtlasInventorySummaryService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.aaeos.legacy_cleanup_inventory_summary.v1';

    /** The seven Inventory Classes (closed set from the "Classes" table). */
    public const CLASS_KEEP_CANONICAL = 'keep_canonical';
    public const CLASS_PROMOTE_TO_KB = 'promote_to_kb';
    public const CLASS_MERGE_INTO_EXISTING = 'merge_into_existing';
    public const CLASS_ARCHIVE_WITH_REDIRECT = 'archive_with_redirect';
    public const CLASS_ARCHIVED_QUARANTINE = 'archived_quarantine';
    public const CLASS_ARCHIVED_PROJECTION = 'archived_projection';
    public const CLASS_HUMAN_VAULT_ONLY = 'human_vault_only';

    /**
     * Default action each Inventory Class carries, verbatim intent from the
     * "Classes" table.
     *
     * @var array<string,string>
     */
    private const CLASS_DEFAULT_ACTION = [
        self::CLASS_KEEP_CANONICAL => 'keep_and_link_from_index_or_readme',
        self::CLASS_PROMOTE_TO_KB => 'create_small_kb_doc_or_patch_owner_doc',
        self::CLASS_MERGE_INTO_EXISTING => 'merge_only_missing_decisions_then_redirect',
        self::CLASS_ARCHIVE_WITH_REDIRECT => 'preserve_with_canonical_replacement',
        self::CLASS_ARCHIVED_QUARANTINE => 'keep_until_explicit_delete_review',
        self::CLASS_ARCHIVED_PROJECTION => 'preserve_as_historical_never_source_of_truth',
        self::CLASS_HUMAN_VAULT_ONLY => 'keep_in_human_surface_promote_only_reviewed_excerpts',
    ];

    /**
     * The documented "Current Families" and their handling. Keys are normalized
     * family identifiers; values are the handling label the doc assigns.
     *
     * @var array<string,string>
     */
    private const FAMILY_HANDLING = [
        'canonical_kb_docs' => 'keep_under_engineering_knowledge_base',
        'deprecated_kb_stubs' => 'keep_with_redirect_do_not_expand',
        'operational_runbooks' => 'keep_if_actively_useful_point_to_kb_owner',
        'resolver_corpus' => 'historical_governed_corpus_not_direct_authority',
        'provider_bootstrap_files' => 'archived_projection_useful_for_history_only',
        'superpower_plans_specs' => 'preserve_as_source_material_or_redirect_to_canonical',
        'vault_obsidian_notes' => 'human_knowledge_surface_curated_sync_only',
    ];

    /**
     * Classify one legacy documentation item into exactly one Inventory Class by
     * applying the doc's "Decision Criteria" table in its documented order: the
     * first question that answers "yes" decides the class. The order is load
     * bearing — README/index membership is checked before promotion, archival
     * before vault, and so on, exactly as the table lists.
     *
     * Two cross-cutting flags from the "Classes" table sit ahead of the criteria
     * table because the doc gives them dedicated classes: a provider/agent
     * projection is always archived_projection ("never source of truth"), which
     * the criteria table does not otherwise capture.
     *
     * @param array<string,mixed> $item
     *   is_projection         : bool  provider/agent projection (e.g. CLAUDE.md/AGENTS.md).
     *   listed_in_index       : bool  listed by README / START_HERE / canonical index.
     *   has_decision_without_canonical : bool  carries a decision with no canonical equivalent.
     *   duplicates_owner_doc_adds_detail : bool  duplicates an owner doc but adds useful detail.
     *   is_prompt_plan_report_or_spec : bool  prompt / plan / old report / completed spec.
     *   is_personal_or_research : bool  personal, sensitive or research-heavy.
     *   is_obsolete_unreferenced : bool  appears obsolete and unreferenced.
     *
     * @return array<string,mixed>
     */
    public function classify(array $item): array
    {
        [$class, $reason] = $this->resolveClass($item);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'inventory_class',
            'class' => $class,
            'default_action' => self::CLASS_DEFAULT_ACTION[$class],
            'never_immediate_delete' => $class === self::CLASS_ARCHIVED_QUARANTINE,
            'reason' => $reason,
        ];
    }

    /**
     * Route one of the documented "Current Families" to its declared handling.
     * An unknown family is reported (never silently kept) so the inventory stays
     * an exhaustive, auditable model.
     *
     * @return array<string,mixed>
     */
    public function handleFamily(string $family): array
    {
        $key = $this->normalizeFamily($family);
        $known = array_key_exists($key, self::FAMILY_HANDLING);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'family_handling',
            'family' => $key,
            'known' => $known,
            'handling' => $known ? self::FAMILY_HANDLING[$key] : 'unknown_family_requires_inventory_update',
        ];
    }

    /**
     * Enforce the four "Risk Rules" as invariants against a proposed
     * interpretation/action and return every violation. The proposal is allowed
     * only when no rule is broken.
     *
     * @param array<string,mixed> $proposal
     *   treats_vault_as_low_value     : bool  treating human_vault_only as low value.
     *   treats_quarantine_as_delete_ok : bool  treating archived_quarantine as delete-approved.
     *   promote_to_kb                 : bool  this is a promote_to_kb action.
     *   pastes_full_source            : bool  it pastes the full legacy source instead of synthesizing.
     *   changes_class                 : bool  it changes an item's inventory class.
     *   preserves_traceability        : bool  it preserves enough traceability for future audits.
     *
     * @return array<string,mixed>
     */
    public function checkRiskRules(array $proposal): array
    {
        $violations = [];

        // 1. "human_vault_only does not mean low value."
        if ((bool) ($proposal['treats_vault_as_low_value'] ?? false)) {
            $violations[] = 'human_vault_only_treated_as_low_value';
        }

        // 2. "archived_quarantine does not mean delete approved."
        if ((bool) ($proposal['treats_quarantine_as_delete_ok'] ?? false)) {
            $violations[] = 'archived_quarantine_treated_as_delete_approved';
        }

        // 3. "promote_to_kb means synthesize the decision; do not paste the full
        //    source."
        if ((bool) ($proposal['promote_to_kb'] ?? false)
            && (bool) ($proposal['pastes_full_source'] ?? false)) {
            $violations[] = 'promote_to_kb_pasted_full_source_instead_of_synthesizing';
        }

        // 4. "Any class change must preserve enough traceability for future
        //    audits."
        if ((bool) ($proposal['changes_class'] ?? false)
            && ! (bool) ($proposal['preserves_traceability'] ?? false)) {
            $violations[] = 'class_change_without_traceability_for_audit';
        }

        $allowed = $violations === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'risk_rules',
            'allowed' => $allowed,
            'violations' => array_values($violations),
            'reason' => $allowed ? 'no_risk_rule_violated' : $violations[0],
        ];
    }

    /**
     * Convenience predicate: does this proposed interpretation/action respect
     * every Risk Rule?
     *
     * @param array<string,mixed> $proposal
     */
    public function isProposalAllowed(array $proposal): bool
    {
        return $this->checkRiskRules($proposal)['allowed'] === true;
    }

    /**
     * Resolve the Inventory Class for an item plus a short reason code, applying
     * the "Decision Criteria" table in documented order.
     *
     * @param array<string,mixed> $item
     * @return array{0:string,1:string}
     */
    private function resolveClass(array $item): array
    {
        // Dedicated class from the "Classes" table: a provider/agent projection
        // is preserved as historical and is "never source of truth".
        if ((bool) ($item['is_projection'] ?? false)) {
            return [self::CLASS_ARCHIVED_PROJECTION, 'provider_projection_never_source_of_truth'];
        }

        // Decision Criteria, top-to-bottom — first yes wins.

        // "Is it listed by README, START_HERE or canonical index?"
        if ((bool) ($item['listed_in_index'] ?? false)) {
            return [self::CLASS_KEEP_CANONICAL, 'listed_in_readme_start_here_or_canonical_index'];
        }

        // "Does it contain a decision without canonical equivalent?"
        if ((bool) ($item['has_decision_without_canonical'] ?? false)) {
            return [self::CLASS_PROMOTE_TO_KB, 'decision_without_canonical_equivalent'];
        }

        // "Does it duplicate an owner doc but add useful detail?"
        if ((bool) ($item['duplicates_owner_doc_adds_detail'] ?? false)) {
            return [self::CLASS_MERGE_INTO_EXISTING, 'duplicates_owner_doc_but_adds_useful_detail'];
        }

        // "Is it a prompt, plan, old report or completed spec?"
        if ((bool) ($item['is_prompt_plan_report_or_spec'] ?? false)) {
            return [self::CLASS_ARCHIVE_WITH_REDIRECT, 'prompt_plan_report_or_completed_spec'];
        }

        // "Is it personal, sensitive or research-heavy?"
        if ((bool) ($item['is_personal_or_research'] ?? false)) {
            return [self::CLASS_HUMAN_VAULT_ONLY, 'personal_sensitive_or_research_heavy'];
        }

        // "Does it appear obsolete and unreferenced?" -> quarantine, never an
        // immediate delete.
        if ((bool) ($item['is_obsolete_unreferenced'] ?? false)) {
            return [self::CLASS_ARCHIVED_QUARANTINE, 'obsolete_unreferenced_quarantined_never_immediate_delete'];
        }

        // Preserve-first default: when no criterion matches, quarantine the
        // material for explicit review rather than lose it.
        return [self::CLASS_ARCHIVED_QUARANTINE, 'unclassified_material_quarantined_for_review'];
    }

    /**
     * Normalize a free-form family label to a known family key when possible.
     */
    private function normalizeFamily(string $family): string
    {
        $key = strtolower(trim($family));
        $key = (string) preg_replace('/[^a-z0-9]+/', '_', $key);

        return trim($key, '_');
    }
}
