<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Legacy Documentation Cleanup Plan decider.
 *
 * Pure, deterministic runtime for the cleanup-plan policy: given one legacy
 * documentation item and the facts known about it, it decides which cleanup
 * action is allowed (promote | merge | redirect | archive | quarantine | block)
 * and — separately — whether an explicit hard-delete may proceed. It turns the
 * plan's prose Safety Rules, Invariants, "Promotion Definition Of Done" and
 * "Delete Definition Of Done" into a contract that cannot pass silently.
 *
 * It NEVER touches the filesystem, git, or any doc. It consumes already-gathered
 * facts (does a redirect exist, was a reference scan run, did Vitor approve…)
 * and emits the verdict + reasons + an audit receipt. The caller performs the
 * move/archive/delete only when this decider allows it.
 *
 * Rules grounded in the canonical doc:
 *   Safety Rules:
 *     1. "Do not delete files in a first cleanup wave."  -> delete blocked while
 *        the item is in its first wave (no prior redirect/archive release).
 *     2. "Do not move a document without redirect or archived source path."
 *     4. "Do not promote Obsidian/AtlasVault content without privacy review."
 *     5. "Do not treat prompts, provider bootstrap files or task plans as source
 *        of truth." -> such material can only quarantine, never promote.
 *     6. "Do not mix runtime implementation and documentation cleanup unless
 *        explicitly scoped." -> a wave that touched runtime paths is blocked.
 *   Invariants: "Redirect before delete"; "Privacy before promotion";
 *     "Runtime untouched"; "Worktree respected".
 *   Promotion Definition Of Done: 6 requirements, all mandatory.
 *   Delete Definition Of Done: 5 gates, deletion blocked unless ALL are true.
 *
 * @see docs/engineering-knowledge-base/legacy-documentation-cleanup-plan.md
 */
final class AtlasLegacyDocumentationCleanupPlanService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.aaeos.legacy_documentation_cleanup_plan.v1';

    /** Cleanup actions (the closed set from the "Execution Waves" table). */
    public const ACTION_PROMOTE = 'promote';
    public const ACTION_MERGE = 'merge';
    public const ACTION_REDIRECT = 'redirect';
    public const ACTION_ARCHIVE = 'archive';
    public const ACTION_QUARANTINE = 'quarantine';
    /** Returned when no non-destructive action is yet permitted. */
    public const ACTION_BLOCK = 'block';

    /**
     * Material classes that "must not be treated as source of truth"
     * (Safety Rule 5). Such an item can never be promoted — at most quarantined.
     *
     * @var list<string>
     */
    private const NON_AUTHORITATIVE_KINDS = ['prompt', 'provider_bootstrap', 'task_plan'];

    /**
     * The six checks of the "Promotion Definition Of Done". Each maps to a
     * boolean fact on the item; every one must hold for promotion.
     *
     * @var array<string,string>
     */
    private const PROMOTION_DOD = [
        'has_frontmatter' => 'missing_frontmatter',
        'declares_authority_and_related_paths' => 'missing_authority_or_related_paths',
        'synthesizes_decisions' => 'copies_source_instead_of_synthesizing',
        'preserves_source_link' => 'missing_legacy_source_link',
        'within_line_limits' => 'exceeds_documentation_os_line_limit',
        'passes_docs_health' => 'docs_health_or_architecture_validation_failed',
    ];

    /**
     * The five gates of the "Delete Definition Of Done". Deletion is blocked
     * unless ALL are true.
     *
     * @var array<string,string>
     */
    private const DELETE_DOD = [
        'had_redirect_or_archive_for_a_release' => 'no_redirect_or_archive_for_at_least_one_wave',
        'no_live_references' => 'live_references_still_exist',
        'git_log_follow_reviewed' => 'git_log_follow_not_reviewed',
        'valuable_material_resolved' => 'valuable_material_not_promoted_or_rejected',
        'vitor_approved_delete' => 'vitor_did_not_explicitly_approve_delete',
    ];

    /**
     * Decide which non-destructive cleanup action is allowed for one legacy item.
     *
     * Delete is intentionally NOT a possible output here: per the doc, "Delete is
     * a separate human-approved action after redirect and reference audit" — use
     * {@see evaluateDelete()} for that.
     *
     * @param array<string,mixed> $item
     *   intent        : desired action (promote|merge|redirect|archive|quarantine).
     *   kind          : material kind (e.g. source_material|prompt|task_plan|...).
     *   from_vault    : bool  is this Obsidian/AtlasVault/personal content.
     *   privacy_reviewed : bool was personal/vault content redacted/reviewed.
     *   has_redirect_or_archived_source : bool does a move have a redirect/archive.
     *   runtime_touched : bool did this (unscoped) wave change runtime paths.
     *   runtime_scoped  : bool was runtime change explicitly scoped.
     *   reverts_worktree : bool would this revert parallel worktree changes.
     *   promotion_dod   : array<string,bool> the six promotion DoD facts.
     *
     * @return array<string,mixed>
     */
    public function decideAction(array $item): array
    {
        $intent = $this->normalizeAction((string) ($item['intent'] ?? self::ACTION_QUARANTINE));
        $kind = strtolower(trim((string) ($item['kind'] ?? 'source_material')));
        $blockers = [];

        // --- Invariant "Runtime untouched": a wave that changed runtime paths
        // without explicit scope is blocked (Safety Rule 6). ---
        if ((bool) ($item['runtime_touched'] ?? false) && ! (bool) ($item['runtime_scoped'] ?? false)) {
            $blockers[] = 'runtime_changed_without_scope';
        }

        // --- Invariant "Worktree respected": never revert parallel changes. ---
        if ((bool) ($item['reverts_worktree'] ?? false)) {
            $blockers[] = 'would_revert_worktree_changes';
        }

        // --- Safety Rule 2 / Invariant "Redirect before delete": a move
        // (redirect/archive) needs a redirect or archived source path. ---
        if (in_array($intent, [self::ACTION_REDIRECT, self::ACTION_ARCHIVE], true)
            && ! (bool) ($item['has_redirect_or_archived_source'] ?? false)) {
            $blockers[] = 'move_without_redirect_or_archived_source';
        }

        // --- Promotion-specific gates. ---
        if ($intent === self::ACTION_PROMOTE) {
            // Safety Rule 5: prompts / bootstrap / task plans are never source
            // of truth — they cannot be promoted, only quarantined.
            if (in_array($kind, self::NON_AUTHORITATIVE_KINDS, true)) {
                $blockers[] = 'non_authoritative_material_cannot_be_promoted';
            }
            // Safety Rule 4 / Invariant "Privacy before promotion".
            if ((bool) ($item['from_vault'] ?? false) && ! (bool) ($item['privacy_reviewed'] ?? false)) {
                $blockers[] = 'vault_content_promoted_without_privacy_review';
            }
            // Promotion Definition Of Done — all six must hold.
            foreach ($this->promotionGaps($item['promotion_dod'] ?? []) as $gap) {
                $blockers[] = $gap;
            }
        }

        $allowed = $blockers === [];
        // When the intended action is not yet allowed, the doc's preserve-first
        // posture means the safe fallback is to quarantine (Wave 4), never lose
        // the material — unless quarantine itself was the blocked intent.
        $action = $allowed ? $intent : ($intent === self::ACTION_QUARANTINE ? self::ACTION_BLOCK : self::ACTION_QUARANTINE);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'cleanup_action',
            'intent' => $intent,
            'kind' => $kind,
            'allowed' => $allowed,
            'action' => $action,
            'destructive' => false,
            'blockers' => array_values($blockers),
            'reason' => $allowed ? ('action_allowed:' . $intent) : $blockers[0],
        ];
    }

    /**
     * Decide whether an explicit hard-delete may proceed for one legacy item.
     *
     * Implements "Delete Definition Of Done" (deletion blocked unless ALL gates
     * true) plus Safety Rule 1 ("Do not delete files in a first cleanup wave").
     *
     * @param array<string,mixed> $item
     *   first_wave    : bool  is this the item's first cleanup wave.
     *   delete_dod    : array<string,bool> the five delete-DoD facts.
     *
     * @return array<string,mixed>
     */
    public function evaluateDelete(array $item): array
    {
        $blockers = [];

        // Safety Rule 1: a first wave never deletes, regardless of the DoD.
        if ((bool) ($item['first_wave'] ?? false)) {
            $blockers[] = 'delete_forbidden_in_first_wave';
        }

        $dod = is_array($item['delete_dod'] ?? null) ? $item['delete_dod'] : [];
        foreach (self::DELETE_DOD as $fact => $failureReason) {
            if (! (bool) ($dod[$fact] ?? false)) {
                $blockers[] = $failureReason;
            }
        }

        $allowed = $blockers === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'delete',
            'allowed' => $allowed,
            'destructive' => true,
            'requires_human_approval' => true,
            'gates_total' => count(self::DELETE_DOD),
            'gates_failed' => $this->countDeleteGapsOnly($item),
            'blockers' => array_values($blockers),
            'reason' => $allowed ? 'delete_allowed' : $blockers[0],
        ];
    }

    /**
     * Preflight a whole cleanup wave against the doc's "Required Preflight" and
     * "Invariants": the session must know its canonical doc, the touched source,
     * the work type, and the validation it will run — and must not break the
     * runtime/worktree invariants.
     *
     * @param array<string,mixed> $wave
     *   canonical_doc_known : bool
     *   legacy_source_known : bool
     *   work_type           : string  promotion|merge|redirect|archive|quarantine
     *   validation_planned  : bool  are the proof commands chosen
     *   runtime_touched / runtime_scoped / reverts_worktree : bool
     *
     * @return array<string,mixed>
     */
    public function preflightWave(array $wave): array
    {
        $missing = [];
        if (! (bool) ($wave['canonical_doc_known'] ?? false)) {
            $missing[] = 'canonical_doc_unknown';
        }
        if (! (bool) ($wave['legacy_source_known'] ?? false)) {
            $missing[] = 'legacy_source_unknown';
        }
        $workType = $this->normalizeAction((string) ($wave['work_type'] ?? ''));
        if (! in_array($workType, $this->knownWorkTypes(), true)) {
            $missing[] = 'work_type_undeclared';
        }
        if (! (bool) ($wave['validation_planned'] ?? false)) {
            $missing[] = 'validation_commands_unplanned';
        }
        if ((bool) ($wave['runtime_touched'] ?? false) && ! (bool) ($wave['runtime_scoped'] ?? false)) {
            $missing[] = 'runtime_changed_without_scope';
        }
        if ((bool) ($wave['reverts_worktree'] ?? false)) {
            $missing[] = 'would_revert_worktree_changes';
        }

        $ready = $missing === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => 'wave_preflight',
            'work_type' => $workType,
            'ready' => $ready,
            'missing' => array_values($missing),
            'reason' => $ready ? 'wave_preflight_ready' : $missing[0],
        ];
    }

    /**
     * Convenience predicate: may this legacy item be hard-deleted right now?
     *
     * @param array<string,mixed> $item
     */
    public function canDelete(array $item): bool
    {
        return $this->evaluateDelete($item)['allowed'] === true;
    }

    /**
     * Promotion DoD gaps for the given facts.
     *
     * @param mixed $facts
     * @return list<string>
     */
    private function promotionGaps(mixed $facts): array
    {
        $facts = is_array($facts) ? $facts : [];
        $gaps = [];
        foreach (self::PROMOTION_DOD as $fact => $failureReason) {
            if (! (bool) ($facts[$fact] ?? false)) {
                $gaps[] = $failureReason;
            }
        }

        return $gaps;
    }

    /**
     * Count only the failed delete-DoD gates (excludes the first-wave rule),
     * so callers can report "N/5 gates failed".
     *
     * @param array<string,mixed> $item
     */
    private function countDeleteGapsOnly(array $item): int
    {
        $dod = is_array($item['delete_dod'] ?? null) ? $item['delete_dod'] : [];
        $failed = 0;
        foreach (self::DELETE_DOD as $fact => $_reason) {
            if (! (bool) ($dod[$fact] ?? false)) {
                $failed++;
            }
        }

        return $failed;
    }

    /**
     * @return list<string>
     */
    private function knownWorkTypes(): array
    {
        return [
            self::ACTION_PROMOTE,
            self::ACTION_MERGE,
            self::ACTION_REDIRECT,
            self::ACTION_ARCHIVE,
            self::ACTION_QUARANTINE,
        ];
    }

    private function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));

        return in_array($action, [
            self::ACTION_PROMOTE,
            self::ACTION_MERGE,
            self::ACTION_REDIRECT,
            self::ACTION_ARCHIVE,
            self::ACTION_QUARANTINE,
            self::ACTION_BLOCK,
        ], true) ? $action : self::ACTION_QUARANTINE;
    }
}
