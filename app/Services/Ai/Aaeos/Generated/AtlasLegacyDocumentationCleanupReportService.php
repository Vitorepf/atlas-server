<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Legacy Documentation Cleanup Report decider.
 *
 * Pure, deterministic runtime for the *report index* policy (distinct from the
 * cleanup *plan* decider {@see AtlasLegacyDocumentationCleanupPlanService},
 * which decides whether a promote/merge/redirect/archive/quarantine/delete
 * action may proceed). This service turns the report's classification taxonomy,
 * canonical authority ordering and Non-Negotiables into a contract:
 *
 *   1. classify()           — map one legacy item to exactly one of the six
 *                             documented Cleanup Classes and route it to the
 *                             active location the doc names for that class.
 *   2. resolveAuthority()   — in a conflict, decide which document wins per the
 *                             "Canonical Authority" table; legacy cleanup docs
 *                             never override the named authorities.
 *   3. checkNonNegotiables()— flag violations of the five "Non-Negotiables".
 *
 * It NEVER touches the filesystem, git, Obsidian, or any doc. It consumes
 * already-gathered facts and emits verdicts + reasons.
 *
 * Rules grounded in the canonical doc:
 *   Cleanup Classes (6): keep_canonical | promote_to_kb | merge_into_existing |
 *     archive_with_redirect | archived_quarantine | human_vault_only, each with
 *     a documented active location.
 *   Cleanup policy:
 *     - "Treat Obsidian/AtlasVault as Human Knowledge Surface, not raw runtime
 *       source." -> vault/personal material classifies as human_vault_only.
 *     - "Use quarantine before delete; deletion requires explicit human
 *       approval." -> a delete candidate is never deleted here; it routes to
 *       archived_quarantine.
 *   Canonical Authority: README/START_HERE, canonical-architecture-index,
 *     documentation-operating-system, kernel-architecture, master-architecture.
 *     "Legacy cleanup docs never override these files."
 *   Non-Negotiables (5):
 *     1. "Never delete a legacy document in the same wave that first archives it."
 *     2. "Never promote personal or vault content without privacy review."
 *     3. "Never copy a long legacy file into a new canonical doc."
 *     4. "Never use prompt files, AGENTS.md, CLAUDE.md or provider bootstrap
 *        docs as source of truth." -> such material can never be kept canonical
 *        nor promoted; the safe class is archived_quarantine.
 *     5. "Never create a second master architecture because a legacy doc sounds
 *        better."
 *
 * @see docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md
 */
final class AtlasLegacyDocumentationCleanupReportService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.aaeos.legacy_documentation_cleanup_report.v1';

    /** The six Cleanup Classes (closed set from the "Cleanup Classes" table). */
    public const CLASS_KEEP_CANONICAL = 'keep_canonical';
    public const CLASS_PROMOTE_TO_KB = 'promote_to_kb';
    public const CLASS_MERGE_INTO_EXISTING = 'merge_into_existing';
    public const CLASS_ARCHIVE_WITH_REDIRECT = 'archive_with_redirect';
    public const CLASS_ARCHIVED_QUARANTINE = 'archived_quarantine';
    public const CLASS_HUMAN_VAULT_ONLY = 'human_vault_only';

    /**
     * Active location each Cleanup Class routes to, exactly as the doc's
     * "Cleanup Classes" table declares.
     *
     * @var array<string,string>
     */
    private const CLASS_LOCATION = [
        self::CLASS_KEEP_CANONICAL => 'legacy-cleanup/inventory-summary.md',
        self::CLASS_PROMOTE_TO_KB => 'legacy-documentation-cleanup-plan.md',
        self::CLASS_MERGE_INTO_EXISTING => 'legacy-documentation-cleanup-plan.md',
        self::CLASS_ARCHIVE_WITH_REDIRECT => 'legacy-cleanup/executed-promotions.md',
        self::CLASS_ARCHIVED_QUARANTINE => 'legacy-cleanup/inventory-summary.md',
        self::CLASS_HUMAN_VAULT_ONLY => 'obsidian-atlas-vault.md',
    ];

    /**
     * Material kinds that "must not be used as source of truth" (Non-Negotiable
     * 4). Such an item can never be keep_canonical nor promote_to_kb; the safe
     * class is archived_quarantine.
     *
     * @var list<string>
     */
    private const NON_AUTHORITATIVE_KINDS = [
        'prompt',
        'agents_md',
        'claude_md',
        'provider_bootstrap',
    ];

    /**
     * The five canonical authorities, in the order the "Canonical Authority"
     * table lists them (lower index == higher precedence). The index doc is the
     * tie-breaker that "Decides authority in conflicts". Legacy cleanup docs are
     * deliberately absent — they sit below every authority here.
     *
     * @var list<string>
     */
    private const AUTHORITY_ORDER = [
        'readme',
        'start_here',
        'canonical_architecture_index',
        'documentation_operating_system',
        'kernel_architecture',
        'master_architecture',
    ];

    /**
     * Classify one legacy documentation item into exactly one Cleanup Class and
     * route it to that class's active location.
     *
     * The order of checks encodes the doc's preserve-first, authority-protecting
     * posture: vault/personal material and non-authoritative material are
     * diverted before anything can be kept canonical or promoted; a delete
     * candidate is quarantined, never deleted.
     *
     * @param array<string,mixed> $item
     *   kind            : material kind (source_material|prompt|agents_md|
     *                     claude_md|provider_bootstrap|...).
     *   from_vault      : bool  Obsidian/AtlasVault/personal/research content.
     *   is_current_authority : bool  is this a current authority/official support doc.
     *   has_stable_decision  : bool  does it carry a stable decision worth KB promotion.
     *   belongs_in_owner_doc : bool  is it valuable but belongs inside an existing doc.
     *   has_canonical_replacement : bool  does a canonical replacement already exist.
     *   delete_candidate : bool  is it a possible delete candidate.
     *
     * @return array<string,mixed>
     */
    public function classify(array $item): array
    {
        $kind = strtolower(trim((string) ($item['kind'] ?? 'source_material')));
        $fromVault = (bool) ($item['from_vault'] ?? false);
        $nonAuthoritative = in_array($kind, self::NON_AUTHORITATIVE_KINDS, true);

        [$class, $reason] = $this->resolveClass($item, $kind, $fromVault, $nonAuthoritative);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'cleanup_class',
            'kind' => $kind,
            'class' => $class,
            'active_location' => self::CLASS_LOCATION[$class],
            'from_vault' => $fromVault,
            'non_authoritative_source' => $nonAuthoritative,
            'reason' => $reason,
        ];
    }

    /**
     * Resolve, in a conflict between two documents, which one is authoritative.
     *
     * Per "Canonical Authority": the five named authorities outrank everything
     * else and "Legacy cleanup docs never override these files"; the canonical
     * architecture index "Decides authority in conflicts". Two non-authority
     * docs tie.
     *
     * @param string $a kind key (see AUTHORITY_ORDER), or any non-authority label.
     * @param string $b kind key, or any non-authority label.
     * @return array<string,mixed>
     */
    public function resolveAuthority(string $a, string $b): array
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));

        $rankA = $this->authorityRank($a);
        $rankB = $this->authorityRank($b);

        if ($rankA === $rankB) {
            $winner = null;
            $reason = $rankA === null
                ? 'both_non_authority_no_override'
                : 'same_authority';
        } elseif ($rankA === null) {
            $winner = $b;
            $reason = 'legacy_never_overrides_canonical_authority';
        } elseif ($rankB === null) {
            $winner = $a;
            $reason = 'legacy_never_overrides_canonical_authority';
        } else {
            // Lower rank index == higher precedence.
            $winner = $rankA < $rankB ? $a : $b;
            $reason = 'higher_canonical_authority_wins';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'authority_conflict',
            'left' => $a,
            'right' => $b,
            'winner' => $winner,
            'reason' => $reason,
        ];
    }

    /**
     * Check one proposed cleanup action against the five Non-Negotiables and
     * return every violation. An action is allowed only when no Non-Negotiable
     * is broken.
     *
     * @param array<string,mixed> $action
     *   archives_now        : bool  does this wave archive the item.
     *   deletes_now         : bool  does this same wave also delete it.
     *   first_archive_wave  : bool  is this the wave that first archives it.
     *   promotes_vault_content : bool  promoting personal/vault content.
     *   privacy_reviewed    : bool  was that content privacy-reviewed.
     *   copies_long_legacy_file : bool  copying a long legacy file into a new canon doc.
     *   creates_master_architecture : bool  creating a master architecture doc.
     *   master_architecture_exists  : bool  does a master architecture already exist.
     *
     * @return array<string,mixed>
     */
    public function checkNonNegotiables(array $action): array
    {
        $violations = [];

        // 1. Never delete in the same wave that first archives the document.
        if ((bool) ($action['deletes_now'] ?? false)
            && (bool) ($action['archives_now'] ?? false)
            && (bool) ($action['first_archive_wave'] ?? false)) {
            $violations[] = 'delete_in_same_wave_as_first_archive';
        }

        // 2. Never promote personal/vault content without privacy review.
        if ((bool) ($action['promotes_vault_content'] ?? false)
            && ! (bool) ($action['privacy_reviewed'] ?? false)) {
            $violations[] = 'vault_content_promoted_without_privacy_review';
        }

        // 3. Never copy a long legacy file into a new canonical doc.
        if ((bool) ($action['copies_long_legacy_file'] ?? false)) {
            $violations[] = 'long_legacy_file_copied_into_canonical_doc';
        }

        // 5. Never create a second master architecture because a legacy doc
        //    sounds better.
        if ((bool) ($action['creates_master_architecture'] ?? false)
            && (bool) ($action['master_architecture_exists'] ?? false)) {
            $violations[] = 'second_master_architecture_created';
        }

        $allowed = $violations === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'non_negotiables',
            'allowed' => $allowed,
            'violations' => array_values($violations),
            'reason' => $allowed ? 'no_non_negotiable_violated' : $violations[0],
        ];
    }

    /**
     * Convenience predicate: does this proposed action respect every
     * Non-Negotiable?
     *
     * @param array<string,mixed> $action
     */
    public function isActionAllowed(array $action): bool
    {
        return $this->checkNonNegotiables($action)['allowed'] === true;
    }

    /**
     * Resolve the Cleanup Class for an item plus a short reason code.
     *
     * @param array<string,mixed> $item
     * @return array{0:string,1:string}
     */
    private function resolveClass(array $item, string $kind, bool $fromVault, bool $nonAuthoritative): array
    {
        // Vault/personal/research content is a Human Knowledge Surface, "not raw
        // runtime source" — it can only be human_vault_only.
        if ($fromVault) {
            return [self::CLASS_HUMAN_VAULT_ONLY, 'vault_content_is_human_knowledge_surface'];
        }

        // Non-Negotiable 4: prompts / AGENTS.md / CLAUDE.md / provider bootstrap
        // are never source of truth. They cannot be kept canonical nor promoted;
        // the safe, preserve-first class is archived_quarantine.
        if ($nonAuthoritative) {
            return [self::CLASS_ARCHIVED_QUARANTINE, 'non_authoritative_source_quarantined'];
        }

        // A current authority / official support doc stays canonical.
        if ((bool) ($item['is_current_authority'] ?? false)) {
            return [self::CLASS_KEEP_CANONICAL, 'current_authority_kept_canonical'];
        }

        // A possible delete candidate is never deleted here: "Use quarantine
        // before delete; deletion requires explicit human approval."
        if ((bool) ($item['delete_candidate'] ?? false)) {
            return [self::CLASS_ARCHIVED_QUARANTINE, 'delete_candidate_quarantined_not_deleted'];
        }

        // Historical source with a canonical replacement is archived + redirect.
        if ((bool) ($item['has_canonical_replacement'] ?? false)) {
            return [self::CLASS_ARCHIVE_WITH_REDIRECT, 'historical_source_archived_with_redirect'];
        }

        // A stable decision worth becoming KB is promoted (handled by the plan).
        if ((bool) ($item['has_stable_decision'] ?? false)) {
            return [self::CLASS_PROMOTE_TO_KB, 'stable_decision_promoted_to_kb'];
        }

        // Valuable but belongs inside an existing owner doc.
        if ((bool) ($item['belongs_in_owner_doc'] ?? false)) {
            return [self::CLASS_MERGE_INTO_EXISTING, 'valuable_material_merged_into_owner_doc'];
        }

        // Default preserve-first posture: when nothing else fits, quarantine the
        // material rather than lose it.
        return [self::CLASS_ARCHIVED_QUARANTINE, 'unclassified_material_quarantined'];
    }

    /**
     * Precedence rank of an authority kind, or null when the label is not a
     * canonical authority (e.g. a legacy cleanup doc). Lower == higher authority.
     */
    private function authorityRank(string $kind): ?int
    {
        $index = array_search($kind, self::AUTHORITY_ORDER, true);

        return $index === false ? null : (int) $index;
    }
}
